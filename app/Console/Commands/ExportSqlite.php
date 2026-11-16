<?php

namespace App\Console\Commands;

use App\Services\Backup\SqliteExporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/** Copies the app's database into a SQLite file: to host it without a database server, or to keep a copy. */
#[Signature('app:export-sqlite {path? : Where to write it (default: storage/app/export/database.sqlite)} {--force : Replace the file if it exists}')]
#[Description('Copy everything saved into a new SQLite database file')]
class ExportSqlite extends Command
{
    public function handle(SqliteExporter $exporter): int
    {
        $path = $this->argument('path') ?: storage_path('app/export/database.sqlite');
        if (file_exists($path) && ! $this->option('force')) {
            $this->error("{$path} already exists. Add --force to replace it.");

            return self::FAILURE;
        }

        try {
            $copied = $exporter->export($path);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Table', 'Rows'], collect($copied)->map(fn (int $rows, string $table) => [$table, $rows])->values()->all());
        $this->info('Copied '.array_sum($copied)." rows into {$path}.");

        return self::SUCCESS;
    }
}
