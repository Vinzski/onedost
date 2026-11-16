<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Everything saved in OneDOST as one zip, to keep somewhere safe: the
 * database as a SQLite file, and the uploaded files (activity photos) with
 * their folders, as they sit in storage/app/private. Restoring is putting
 * both back (see docs/DEPLOY.md).
 */
class BackupService
{
    public function __construct(private SqliteExporter $exporter) {}

    /**
     * @return array{path: string, name: string} A temp file (delete after sending) and its download name.
     */
    public function make(): array
    {
        $dir = storage_path('app/backup-'.bin2hex(random_bytes(6)));
        $database = "{$dir}/database.sqlite";
        $zipPath = "{$dir}.zip";

        try {
            $this->exporter->export($database);

            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Couldn\'t write the backup file.');
            }
            $zip->addFile($database, 'database/database.sqlite');
            $this->addUploads($zip);
            $zip->close();
        } finally {
            File::deleteDirectory($dir);
        }

        return ['path' => $zipPath, 'name' => 'onedost-backup-'.now(config('onedost.timezone'))->format('Y-m-d-His').'.zip'];
    }

    /** The uploads (the `local` disk), where they go back: storage/app/private. */
    private function addUploads(ZipArchive $zip): void
    {
        foreach (self::uploads() as $relative => $path) {
            $zip->addFile($path, "storage/app/private/{$relative}");
        }
    }

    /**
     * Every uploaded file on the `local` disk, by its path on the disk.
     *
     * @return array<string, string> Relative path => full path.
     */
    public static function uploads(): array
    {
        $root = Storage::disk('local')->path('');
        if (! is_dir($root)) {
            return [];
        }

        return collect(File::allFiles($root, true))
            ->reject(fn ($file) => $file->getFilename() === '.gitignore')
            ->mapWithKeys(fn ($file) => [str_replace('\\', '/', $file->getRelativePathname()) => $file->getPathname()])
            ->all();
    }
}
