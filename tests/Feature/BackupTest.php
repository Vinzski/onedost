<?php

use App\Models\DtrNote;
use App\Models\LeaveApplication;
use App\Models\Traveler;
use Illuminate\Database\Connection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/onedost-test-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->dir);
});

afterEach(fn () => File::deleteDirectory($this->dir));

/** A SQLite file, opened on its own (not through the app's connections). */
function openSqlite(string $path): Connection
{
    return new SQLiteConnection(new PDO("sqlite:{$path}"));
}

function someRecords(): Traveler
{
    $juan = Traveler::create(['name' => 'JUAN A. DELA CRUZ', 'position' => 'PTA II', 'official_station' => '', 'division' => '']);
    LeaveApplication::factory()->create(['traveler_id' => $juan->id, 'start_date' => '2026-09-14', 'end_date' => '2026-09-16']);
    DtrNote::create(['traveler_id' => $juan->id, 'starts_on' => '2026-09-07', 'ends_on' => '2026-09-07', 'slots' => ['am_arrival'], 'reason' => 'no_internet']);

    return $juan;
}

it('copies everything saved into a new SQLite file', function () {
    someRecords();
    $path = "{$this->dir}/database.sqlite";

    $this->artisan('app:export-sqlite', ['path' => $path])->assertSuccessful();

    $copy = openSqlite($path);
    expect($copy->table('travelers')->count())->toBe(1)
        ->and($copy->table('leave_applications')->value('start_date'))->toStartWith('2026-09-14')
        ->and(json_decode($copy->table('dtr_notes')->value('slots')))->toBe(['am_arrival'])
        // Passing state stays behind; the migrations are recorded, so the copy is up to date.
        ->and($copy->table('sessions')->count())->toBe(0)
        ->and($copy->table('migrations')->count())->toBeGreaterThan(10);
    // Let go of the file: Windows won't replace a file that's open.
    $copy->disconnect();

    // A file already there needs --force.
    $this->artisan('app:export-sqlite', ['path' => $path])->assertFailed();
    $this->artisan('app:export-sqlite', ['path' => $path, '--force' => true])->assertSuccessful();
});

/** @return list<string> The paths inside a zip. */
function zipNames(string $path): array
{
    $zip = new ZipArchive;
    $zip->open($path);
    $names = array_map(fn (int $i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
    $zip->close();

    return $names;
}

function zipEntry(string $path, string $name): string|false
{
    $zip = new ZipArchive;
    $zip->open($path);
    $content = $zip->getFromName($name);
    $zip->close();

    return $content;
}

it('downloads a backup of the database and the uploaded photos', function () {
    Storage::fake('local');
    Storage::disk('local')->put('liquidations/999/photo.jpg', 'not really a jpeg');
    someRecords();

    $file = $this->get(route('backup'))->assertOk()->assertDownload()->baseResponse->getFile()->getPathname();
    $zipPath = "{$this->dir}/backup.zip";
    rename($file, $zipPath);

    expect(zipEntry($zipPath, 'storage/app/private/liquidations/999/photo.jpg'))->toBe('not really a jpeg');
    file_put_contents("{$this->dir}/database.sqlite", zipEntry($zipPath, 'database/database.sqlite'));
    $copy = openSqlite("{$this->dir}/database.sqlite");
    expect($copy->table('dtr_notes')->count())->toBe(1);
    $copy->disconnect();
});

it('packages the app for the host, with the data and a live .env only the first time', function () {
    Storage::fake('local');
    Storage::disk('local')->put('liquidations/999/photo.jpg', 'not really a jpeg');
    someRecords();
    // Composer and npm aren't run here: composer "installs" a vendor folder of one file.
    Process::fake(function ($process) {
        if (str_starts_with($process->command, 'composer')) {
            File::ensureDirectoryExists($process->path.'/vendor');
            file_put_contents($process->path.'/vendor/autoload.php', '<?php');
        }

        return Process::result();
    });

    $this->artisan('app:package', ['--first' => true, '--url' => 'https://juan.alwaysdata.net', '--output' => $this->dir])->assertSuccessful();
    $this->artisan('app:package', ['--output' => $this->dir])->assertSuccessful();

    $first = glob("{$this->dir}/onedost-*-first.zip")[0];
    $update = collect(glob("{$this->dir}/onedost-*.zip"))->reject(fn (string $p) => $p === $first)->sole();
    $shipped = zipNames($first);
    $env = zipEntry($first, '.env');

    expect($shipped)
        ->toContain('artisan', 'vendor/autoload.php', 'public/index.php', 'public/.htaccess', 'public/build/manifest.json',
            'resources/templates/leave/application-for-leave.pdf', 'storage/framework/views/.gitignore',
            'database/database.sqlite', 'storage/app/private/liquidations/999/photo.jpg', '.env')
        ->not->toContain('public/hot', 'resources/js/app.js', 'tests/Pest.php', 'phpunit.xml')
        ->and(collect($shipped)->filter(fn (string $n) => str_starts_with($n, 'storage/app/imports')
            || str_starts_with($n, 'node_modules')
            || (str_starts_with($n, 'bootstrap/cache/') && str_ends_with($n, '.php'))))->toBeEmpty()
        ->and($env)->toContain('APP_ENV=production', 'APP_DEBUG=false', 'APP_URL=https://juan.alwaysdata.net', 'DB_CONNECTION=sqlite', 'SESSION_SECURE_COOKIE=true')
        ->and($env)->toMatch('/APP_KEY=base64:\S{40,}/')
        // Later uploads leave the host's .env, database and photos alone.
        ->and(zipNames($update))->toContain('artisan', 'vendor/autoload.php')
        ->not->toContain('.env', 'database/database.sqlite', 'storage/app/private/liquidations/999/photo.jpg');
})->skip(! file_exists(__DIR__.'/../../public/build/manifest.json'), 'Build the pages first (npm run build).');

it('won\'t make the first upload without the site\'s address', function () {
    $this->artisan('app:package', ['--first' => true, '--output' => $this->dir])->assertFailed();
});
