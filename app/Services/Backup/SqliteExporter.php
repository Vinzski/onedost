<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Copies everything saved in the app's database — MySQL here, SQLite once
 * hosted — into a new SQLite file: the tables as the migrations make them,
 * then every row. That file is the database a host without a database
 * server runs on, and a backup of it.
 */
class SqliteExporter
{
    /** The temporary connection the copy is written through. */
    private const CONNECTION = 'sqlite_export';

    /** Passing state, not records: logins in progress, cache, queued jobs. */
    private const SKIP = ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

    /** Rows a statement; kept low for SQLite's limit on bound values. */
    private const CHUNK = 100;

    /**
     * @return array<string, int> Rows copied, by table.
     */
    public function export(string $path): array
    {
        $source = config('database.default');
        if ($this->isSource($source, $path)) {
            throw new RuntimeException('That file is the database in use: pick another path.');
        }
        if (file_exists($path) && ! @unlink($path)) {
            throw new RuntimeException("Couldn't replace {$path}: is it open somewhere?");
        }
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        touch($path);

        config(['database.connections.'.self::CONNECTION => [
            'driver' => 'sqlite',
            'database' => $path,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        try {
            Artisan::call('migrate', ['--database' => self::CONNECTION, '--force' => true]);
            $copied = Schema::connection(self::CONNECTION)->withoutForeignKeyConstraints(fn () => $this->copy($source));

            $broken = DB::connection(self::CONNECTION)->select('PRAGMA foreign_key_check');
            if ($broken !== []) {
                throw new RuntimeException('The copy has '.count($broken).' row(s) pointing at records that don\'t exist.');
            }

            return $copied;
        } finally {
            // Let go of the file (Windows won't zip or move it while it's open).
            DB::purge(self::CONNECTION);
        }
    }

    /** @return array<string, int> */
    private function copy(string $source): array
    {
        $from = DB::connection($source);
        $to = DB::connection(self::CONNECTION);
        $copied = [];

        foreach (array_column(Schema::connection(self::CONNECTION)->getTables(), 'name') as $table) {
            if (in_array($table, self::SKIP, true) || ! Schema::connection($source)->hasTable($table)) {
                continue;
            }
            $columns = Schema::connection($source)->getColumns($table);
            // Laravel keeps a date on SQLite as "2026-09-14 00:00:00"; MySQL gives "2026-09-14".
            $dates = array_column(array_filter($columns, fn (array $c) => $c['type_name'] === 'date'), 'name');

            $query = $from->table($table);
            if (in_array('id', array_column($columns, 'name'), true)) {
                $query->orderBy('id');
            }

            $copied[$table] = 0;
            foreach ($query->get()->chunk(self::CHUNK) as $rows) {
                $to->table($table)->insert($rows->map(fn (object $row) => $this->row((array) $row, $dates))->values()->all());
                $copied[$table] += $rows->count();
            }
        }

        return $copied;
    }

    private function row(array $row, array $dates): array
    {
        foreach ($dates as $column) {
            if (is_string($row[$column] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $row[$column])) {
                $row[$column] .= ' 00:00:00';
            }
        }

        return $row;
    }

    private function isSource(string $source, string $path): bool
    {
        $config = config("database.connections.{$source}");
        if (($config['driver'] ?? null) !== 'sqlite' || ($config['database'] ?? '') === ':memory:') {
            return false;
        }

        return file_exists($path) && realpath($path) === realpath($config['database']);
    }
}
