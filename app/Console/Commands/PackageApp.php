<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use App\Services\Backup\SqliteExporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use ZipArchive;

/**
 * Makes the zip to upload to a web host (docs/DEPLOY.md): the app with its
 * production libraries (no test tools) and built pages, ready to unzip and
 * run — nothing from this machine that the host shouldn't have.
 *
 * The first upload (--first) also carries your data, as a SQLite database
 * and your uploaded photos, and a .env for the host with a new APP_KEY.
 * Later uploads leave the host's .env, database and photos alone.
 */
#[Signature('app:package
    {--first : The first upload: also your data and a ready .env}
    {--url= : The site\'s address, e.g. https://you.alwaysdata.net (with --first)}
    {--output= : Folder to write the zip in (default: build/)}
    {--skip-build : Use the pages already built in public/build}')]
#[Description('Make the zip to upload to the web host')]
class PackageApp extends Command
{
    /** What runs the app. */
    private const SHIP = ['app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'artisan', 'composer.json', 'composer.lock'];

    /** Never uploaded, under what ships. */
    private const NEVER = [
        // The dev server's address: the live site would load its scripts from this laptop.
        'public/hot',
        'public/storage/*',
        // This machine's caches, with its paths in them.
        'bootstrap/cache/*.php',
        // Local database files; --first adds the export of the real one.
        'database/*.sqlite*',
        // Page sources: what runs is their build in public/build.
        'resources/js/*',
        'resources/css/*',
    ];

    public function handle(SqliteExporter $exporter): int
    {
        $first = (bool) $this->option('first');
        $url = rtrim((string) $this->option('url'), '/');
        if ($first && ! preg_match('#^https?://[^/\s]+$#', $url)) {
            $this->error('With --first, give the site\'s address: --url=https://you.alwaysdata.net');

            return self::FAILURE;
        }
        if ($first && ! str_starts_with($url, 'https://')) {
            $this->warn('That address isn\'t https: logins would travel unencrypted. Use the https:// address if the host has one.');
        }

        $output = $this->option('output') ?: base_path('build');
        $zipPath = $output.'/onedost-'.now(config('onedost.timezone'))->format('Y-m-d-His').($first ? '-first' : '').'.zip';
        // Outside the project, so nothing staged here ends up in the zip.
        $stage = sys_get_temp_dir().'/onedost-package-'.bin2hex(random_bytes(4));

        try {
            if (! $this->option('skip-build')) {
                $this->components->task('Building the pages', fn () => $this->shell('npm run build', base_path()));
            }
            if (! is_file(public_path('build/manifest.json'))) {
                throw new RuntimeException('public/build has no manifest: run `npm run build` first.');
            }

            $this->components->task('Fetching the libraries, without the test tools', fn () => $this->vendor($stage));

            File::ensureDirectoryExists($output);
            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException("Couldn't write {$zipPath}.");
            }

            $this->components->task('Adding the app', function () use ($zip, $stage) {
                $this->addApp($zip);
                $this->addTree($zip, "{$stage}/vendor", 'vendor');
                $this->addStorageFolders($zip);
            });

            if ($first) {
                $this->components->task('Adding your data', function () use ($zip, $exporter, $stage, $url) {
                    $exporter->export("{$stage}/database.sqlite");
                    $zip->addFile("{$stage}/database.sqlite", 'database/database.sqlite');
                    $this->addUploads($zip);
                    $zip->addFromString('.env', $this->productionEnv($url));
                });
            }

            $count = $zip->numFiles;
            $this->components->task('Compressing', fn () => $zip->close());
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            @unlink($zipPath);

            return self::FAILURE;
        } finally {
            File::deleteDirectory($stage);
        }

        $size = number_format(filesize($zipPath) / 1048576, 1);
        $this->newLine();
        $this->info("Made {$zipPath} ({$count} files, {$size} MB).");
        $this->line($first
            ? 'It holds your data and a .env with a new key: keep it private, and upload it once. Next: docs/DEPLOY.md, "First upload".'
            : 'It leaves the host\'s .env, database and photos alone. Next: docs/DEPLOY.md, "Updating".');

        return self::SUCCESS;
    }

    /** Production libraries only, in a folder of their own (this machine's vendor/ keeps its test tools). */
    private function vendor(string $stage): void
    {
        File::ensureDirectoryExists($stage);
        foreach (['composer.json', 'composer.lock'] as $file) {
            File::copy(base_path($file), "{$stage}/{$file}");
        }
        // The app's own classes too, so the autoloader's class list includes them.
        foreach (['app', 'database/factories', 'database/seeders'] as $dir) {
            if (is_dir(base_path($dir))) {
                File::copyDirectory(base_path($dir), "{$stage}/{$dir}");
            }
        }

        $this->shell('composer install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts', $stage);
        if (! is_file("{$stage}/vendor/autoload.php")) {
            throw new RuntimeException('Composer didn\'t produce vendor/: see the messages above.');
        }
    }

    private function addApp(ZipArchive $zip): void
    {
        foreach (self::SHIP as $item) {
            $path = base_path($item);
            if (is_file($path)) {
                $zip->addFile($path, $item);
            } elseif (is_dir($path)) {
                $this->addTree($zip, $path, $item);
            }
        }
    }

    /** A folder, hidden files (.htaccess) included, minus what's never uploaded. */
    private function addTree(ZipArchive $zip, string $dir, string $as): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (File::allFiles($dir, true) as $file) {
            $name = $as.'/'.str_replace('\\', '/', $file->getRelativePathname());
            if (! $this->never($name)) {
                $zip->addFile($file->getPathname(), $name);
            }
        }
    }

    /** storage/'s folders, empty: Laravel writes its cache, sessions, views and logs there. */
    private function addStorageFolders(ZipArchive $zip): void
    {
        foreach (File::allFiles(storage_path(), true) as $file) {
            $name = 'storage/'.str_replace('\\', '/', $file->getRelativePathname());
            // Not the files you keep for reference (imports), nor a backup being made.
            $private = str_starts_with($name, 'storage/app/imports/') || str_starts_with($name, 'storage/app/backup-');
            if ($file->getFilename() === '.gitignore' && ! $private) {
                $zip->addFile($file->getPathname(), $name);
            }
        }
    }

    /** The uploaded files (activity photos), where the app keeps them. */
    private function addUploads(ZipArchive $zip): void
    {
        foreach (BackupService::uploads() as $relative => $path) {
            $zip->addFile($path, "storage/app/private/{$relative}");
        }
    }

    /** "folder/*" is everything under the folder; anything else is a pattern for one file. */
    private function never(string $name): bool
    {
        foreach (self::NEVER as $pattern) {
            $matches = str_ends_with($pattern, '/*')
                ? str_starts_with($name, substr($pattern, 0, -1))
                : fnmatch($pattern, $name, FNM_PATHNAME);
            if ($matches) {
                return true;
            }
        }

        return false;
    }

    private function shell(string $command, string $path): void
    {
        $result = Process::path($path)->timeout(900)->run($command);
        if ($result->failed()) {
            throw new RuntimeException("`{$command}` failed:\n".trim($result->errorOutput() ?: $result->output()));
        }
    }

    /** The host's .env: live settings, the SQLite database, a key of its own. */
    private function productionEnv(string $url): string
    {
        $key = 'base64:'.base64_encode(Encrypter::generateKey(config('app.cipher')));
        $secure = str_starts_with($url, 'https://') ? 'true' : 'false';

        return <<<ENV
            # Made by `php artisan app:package --first`. This file holds the app's
            # secret key: never share it or put it in a public folder.
            APP_NAME=OneDOST
            APP_ENV=production
            APP_KEY={$key}
            APP_DEBUG=false
            APP_URL={$url}

            APP_LOCALE=en
            APP_FALLBACK_LOCALE=en
            APP_FAKER_LOCALE=en_US
            APP_MAINTENANCE_DRIVER=file

            BCRYPT_ROUNDS=12

            # One log file a day, two weeks kept: a free plan's disk is small.
            LOG_CHANNEL=stack
            LOG_STACK=daily
            LOG_DAILY_DAYS=14
            LOG_LEVEL=warning
            LOG_DEPRECATIONS_CHANNEL=null

            # The whole database is the file database/database.sqlite.
            DB_CONNECTION=sqlite

            SESSION_DRIVER=database
            SESSION_LIFETIME=120
            SESSION_ENCRYPT=true
            SESSION_SECURE_COOKIE={$secure}
            SESSION_PATH=/
            SESSION_DOMAIN=null

            CACHE_STORE=database
            # No queue worker on a free plan: anything queued runs right away.
            QUEUE_CONNECTION=sync
            BROADCAST_CONNECTION=log
            FILESYSTEM_DISK=local

            MAIL_MAILER=log
            MAIL_FROM_ADDRESS="hello@example.com"
            MAIL_FROM_NAME="\${APP_NAME}"

            VITE_APP_NAME="\${APP_NAME}"
            INERTIA_SSR_ENABLED=false

            ENV;
    }
}
