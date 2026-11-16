<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * The log pane of `composer dev`. Laravel's own `pail` needs the pcntl
 * extension, which PHP on Windows doesn't have; this follows the newest
 * storage/logs/*.log instead. One line per entry; -v adds stack traces.
 */
#[Signature('log:tail {--recent=0 : Show this many recent entries first} {--once : Show the recent entries and stop}')]
#[Description('Follow the application log as it grows (works on Windows, unlike pail)')]
class TailLog extends Command
{
    private const POLL_MICROSECONDS = 500_000;

    /** Long entry lines (exception context) are cut to this unless -v. */
    private const MAX_LINE = 300;

    public function handle(): int
    {
        $file = $this->newestLog();
        $recent = max(0, (int) $this->option('recent'));
        if ($file && $recent > 0) {
            foreach (array_slice($this->entries((string) file_get_contents($file)), -$recent) as $entry) {
                $this->show($entry);
            }
        }
        if ($this->option('once')) {
            return self::SUCCESS;
        }

        // Plain ASCII: Windows consoles garble dashes and ellipses.
        $this->components->info('Watching storage/logs - new log entries appear here.');
        $position = $file ? filesize($file) : 0;

        while (true) {
            usleep(self::POLL_MICROSECONDS);
            $newest = $this->newestLog();
            if ($newest !== $file) {
                // A new daily file started: read it from the top.
                [$file, $position] = [$newest, 0];
            }
            if (! $file) {
                continue;
            }

            clearstatcache(true, $file);
            $size = filesize($file);
            if ($size < $position) {
                $position = 0; // emptied or rotated
            }
            if ($size === $position) {
                continue;
            }

            $handle = fopen($file, 'r');
            fseek($handle, $position);
            $chunk = stream_get_contents($handle);
            $position = ftell($handle);
            fclose($handle);

            foreach ($this->entries($chunk) as $entry) {
                $this->show($entry);
            }
        }
    }

    /** Most recently written storage/logs/*.log, if any. */
    private function newestLog(): ?string
    {
        $files = glob(storage_path('logs/*.log')) ?: [];
        usort($files, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));

        return $files[0] ?? null;
    }

    /**
     * Split log text into entries: each starts with "[2026-10-27 08:00:00]".
     *
     * @return list<string>
     */
    private function entries(string $text): array
    {
        $parts = preg_split('/^(?=\[\d{4}-\d{2}-\d{2}[ T])/m', $text, flags: PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(array_map('rtrim', $parts), fn (string $entry) => $entry !== ''));
    }

    private function show(string $entry): void
    {
        if ($this->output->isVerbose()) {
            $this->line(OutputFormatter::escape($entry));

            return;
        }

        $first = strtok($entry, "\n");
        $first = mb_strlen($first) > self::MAX_LINE ? mb_substr($first, 0, self::MAX_LINE).'...' : $first;
        $style = match (true) {
            (bool) preg_match('/\.(ERROR|CRITICAL|ALERT|EMERGENCY):/', $first) => 'fg=red',
            (bool) preg_match('/\.WARNING:/', $first) => 'fg=yellow',
            default => null,
        };
        $first = OutputFormatter::escape($first);
        $this->line($style ? "<{$style}>{$first}</>" : $first);
    }
}
