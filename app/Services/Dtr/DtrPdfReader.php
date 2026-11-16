<?php

namespace App\Services\Dtr;

use App\Enums\DtrSlot;
use Carbon\CarbonImmutable;

/**
 * Reads the Daily Time Record (CSC Form No. 48) as the DTR system exports it:
 * a dompdf page holding copies of the form side by side, each covering part
 * of the month (1–15, 16–30). A copy shades the rows outside its part dark
 * grey, so a white row with nothing in it is a real blank.
 *
 * Every value is a positioned text run, so columns are found from the
 * header labels (Arrival, Departure, Arrival, Departure, Hours, Minutes) and
 * each value goes to the column it sits under. Text that isn't a time —
 * "NON-WORKING DAY", "HALF DAY" — is a remark covering the columns it spans.
 */
class DtrPdfReader
{
    /** The header labels under A.M, P.M and UNDERTIME, left to right. */
    private const HEADERS = ['Arrival', 'Departure', 'Arrival', 'Departure', 'Hours', 'Minutes'];

    /** Fill colours this dark (out of 1) shade a row out of a copy's part of the month; non-working days are lighter. */
    private const SHADE = 0.7;

    /** How far (points) a value's baseline may sit from its row's day number. */
    private const ROW_TOLERANCE = 4.0;

    /**
     * The most one stream may inflate to. A DTR's page is about 60 KB; a
     * PDF built to inflate into gigabytes stops here instead of eating the
     * server's memory.
     */
    private const MAX_STREAM = 8 * 1024 * 1024;

    /**
     * @return array{name: string, month: CarbonImmutable, days: array<int, array{
     *     am_arrival: ?string, am_departure: ?string, pm_arrival: ?string, pm_departure: ?string,
     *     undertime_hours: ?int, undertime_minutes: ?int, remark: ?string, remark_slots: list<string>
     * }>} Days keyed by day of the month; only days some copy covers.
     */
    public function read(string $path): array
    {
        $copies = [];
        foreach ($this->contentStreams((string) @file_get_contents($path)) as $stream) {
            [$texts, $shades] = $this->scan($stream);
            array_push($copies, ...$this->copies($texts, $shades));
        }
        if ($copies === []) {
            throw new DtrReadException('This PDF doesn\'t look like a Daily Time Record. Upload the DTR as the DTR system exports it.');
        }

        $names = array_unique(array_column($copies, 'name'));
        $months = array_unique(array_map(fn (CarbonImmutable $m) => $m->format('Y-m'), array_column($copies, 'month')));
        if (count($names) > 1 || count($months) > 1) {
            throw new DtrReadException('This PDF holds more than one person or month. Upload one DTR at a time.');
        }

        // A day comes from the copy that covers it; if two do, the one with something in it.
        $days = [];
        foreach ($copies as $copy) {
            foreach ($copy['days'] as $day => $entry) {
                if (! isset($days[$day]) || $this->isEmpty($days[$day])) {
                    $days[$day] = $entry;
                }
            }
        }
        ksort($days);

        return ['name' => $copies[0]['name'], 'month' => $copies[0]['month'], 'days' => $days];
    }

    /** @return list<string> Each inflated stream that draws text. */
    private function contentStreams(string $pdf): array
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches);

        return array_values(array_filter(
            array_map(fn (string $raw) => @gzuncompress($raw, self::MAX_STREAM) ?: $raw, $matches[1]),
            fn (string $stream) => str_contains($stream, ' Td') && str_contains($stream, 'BT'),
        ));
    }

    /**
     * The text runs (position, size, character spacing, text) and the dark
     * fills (rectangles) a content stream draws.
     *
     * @return array{0: list<array{x: float, y: float, size: float, spacing: float, text: string}>, 1: list<array{x: float, y: float, w: float, h: float}>}
     */
    private function scan(string $stream): array
    {
        $texts = $shades = [];
        $fill = 0.0;
        foreach (preg_split('/\r?\n/', $stream) as $line) {
            $line = trim($line);
            if (preg_match('/^([\d.]+) ([\d.]+) ([\d.]+) rg$/', $line, $m)) {
                $fill = ((float) $m[1] + (float) $m[2] + (float) $m[3]) / 3;
            } elseif (preg_match('/^([\d.]+) (-?[\d.]+) ([\d.]+) ([\d.]+) re f\*?$/', $line, $m)) {
                if ($fill > 0.05 && $fill < self::SHADE) {
                    $shades[] = ['x' => (float) $m[1], 'y' => (float) $m[2], 'w' => (float) $m[3], 'h' => (float) $m[4]];
                }
            } elseif (preg_match('/^BT (-?[\d.]+) (-?[\d.]+) Td(?: (-?[\d.]+) Tc)? \/\w+ ([\d.]+) Tf\s+\[(.*)\] TJ/', $line, $m)) {
                $text = trim($this->decode($m[5]));
                if ($text !== '') {
                    $texts[] = ['x' => (float) $m[1], 'y' => (float) $m[2], 'size' => (float) $m[4], 'spacing' => (float) ($m[3] ?: 0), 'text' => $text];
                }
            }
        }

        return [$texts, $shades];
    }

    /**
     * Each copy of the form on the page, from its "Days" header to the next one's.
     *
     * @return list<array{name: string, month: CarbonImmutable, days: array<int, array>}>
     */
    private function copies(array $texts, array $shades): array
    {
        $starts = array_values(array_filter($texts, fn (array $t) => $t['text'] === 'Days'));
        usort($starts, fn (array $a, array $b) => $a['x'] <=> $b['x']);

        $copies = [];
        foreach ($starts as $i => $days) {
            $left = $days['x'] - 10;
            $right = isset($starts[$i + 1]) ? $starts[$i + 1]['x'] - 10 : PHP_FLOAT_MAX;
            $inside = fn (array $item) => $item['x'] >= $left && $item['x'] < $right;
            $copies[] = $this->copy(array_values(array_filter($texts, $inside)), array_values(array_filter($shades, $inside)), $days);
        }

        return $copies;
    }

    private function copy(array $texts, array $shades, array $daysHeader): array
    {
        // Columns: the day number, then the six labels under the header.
        $labels = array_values(array_filter($texts, fn (array $t) => in_array($t['text'], self::HEADERS, true)
            && $t['y'] < $daysHeader['y'] && $t['y'] > $daysHeader['y'] - 20));
        usort($labels, fn (array $a, array $b) => $a['x'] <=> $b['x']);
        if (array_column($labels, 'text') !== self::HEADERS) {
            throw new DtrReadException('The DTR\'s table headings aren\'t where they should be, so its times can\'t be read.');
        }
        $centres = [$this->centre($daysHeader), ...array_map(fn (array $t) => $this->centre($t), $labels)];
        $columns = ['day', ...array_map(fn (DtrSlot $slot) => $slot->value, DtrSlot::cases()), 'undertime_hours', 'undertime_minutes'];

        $month = $this->month($texts);
        $rows = array_filter($texts, fn (array $t) => $t['y'] < $daysHeader['y'] - 20
            && preg_match('/^\d{1,2}$/', $t['text'])
            && $this->column($t, $centres) === 0
            && (int) $t['text'] >= 1 && (int) $t['text'] <= $month->daysInMonth);

        $days = [];
        foreach ($rows as $row) {
            if ($this->shaded($row['y'], $shades)) {
                continue;
            }
            $entry = array_fill_keys(array_slice($columns, 1), null) + ['remark' => null, 'remark_slots' => []];
            foreach ($texts as $item) {
                if ($item === $row || abs($item['y'] - $row['y']) > self::ROW_TOLERANCE || $item['x'] < $row['x']) {
                    continue;
                }
                $column = $columns[$this->column($item, $centres)];
                if (preg_match('/^\d{1,2}:\d{2}$/', $item['text']) && in_array($column, DtrSlot::values(), true)) {
                    $entry[$column] = str_pad($item['text'], 5, '0', STR_PAD_LEFT);
                } elseif (preg_match('/^\d+$/', $item['text']) && str_starts_with($column, 'undertime_')) {
                    $entry[$column] = (int) $item['text'];
                } else {
                    $entry['remark'] = trim(($entry['remark'] ? $entry['remark'].' ' : '').preg_replace('/\s+/', ' ', $item['text']));
                    $entry['remark_slots'] = array_values(array_unique([...$entry['remark_slots'], ...$this->spanned($item, $centres)]));
                }
            }
            $days[(int) $row['text']] = $entry;
        }

        return ['name' => $this->name($texts), 'month' => $month, 'days' => $days];
    }

    /** "September-2026", beside "For the month of". */
    private function month(array $texts): CarbonImmutable
    {
        foreach ($texts as $item) {
            if (preg_match('/^([A-Za-z]+)[- ](\d{4})$/', $item['text'], $m)) {
                $month = CarbonImmutable::createFromFormat('!F Y', ucfirst(strtolower($m[1])).' '.$m[2]);
                if ($month !== false) {
                    return $month;
                }
            }
        }

        throw new DtrReadException('The DTR\'s month ("For the month of") couldn\'t be read.');
    }

    /** The name printed under "DAILY TIME RECORD". */
    private function name(array $texts): string
    {
        $title = collect($texts)->first(fn (array $t) => strtoupper($t['text']) === 'DAILY TIME RECORD');
        $name = $title ? collect($texts)
            ->filter(fn (array $t) => $t['y'] < $title['y'] && $t['y'] > $title['y'] - 25)
            ->sortByDesc('y')
            ->first() : null;
        if (! $name) {
            throw new DtrReadException('The name on the DTR couldn\'t be read.');
        }

        return $name['text'];
    }

    /** Which column an item sits under: the nearest column centre. */
    private function column(array $item, array $centres): int
    {
        $x = $this->centre($item);
        $distances = array_map(fn (float $c) => abs($c - $x), $centres);

        return array_search(min($distances), $distances, true);
    }

    /** The time columns a remark's text spans. @return list<string> */
    private function spanned(array $item, array $centres): array
    {
        [$from, $to] = [$item['x'], $item['x'] + $this->width($item)];
        $slots = [];
        foreach (DtrSlot::cases() as $i => $slot) {
            $column = $i + 1;
            $cellFrom = ($centres[$column - 1] + $centres[$column]) / 2;
            $cellTo = ($centres[$column] + $centres[$column + 1]) / 2;
            if ($from < $cellTo && $to > $cellFrom) {
                $slots[] = $slot->value;
            }
        }

        return $slots;
    }

    /** Whether a dark fill covers this row: the row is outside the copy's part of the month. */
    private function shaded(float $baseline, array $shades): bool
    {
        foreach ($shades as $shade) {
            if ($baseline >= $shade['y'] - 1 && $baseline <= $shade['y'] + $shade['h'] + 1) {
                return true;
            }
        }

        return false;
    }

    private function centre(array $item): float
    {
        return $item['x'] + $this->width($item) / 2;
    }

    /** About how wide a text run is: Helvetica averages a little over half an em a character. */
    private function width(array $item): float
    {
        $length = mb_strlen($item['text']);

        return $length * $item['size'] * 0.56 + $length * $item['spacing'];
    }

    /** The text of a TJ array: its (strings) joined, escapes undone, from Windows-1252. */
    private function decode(string $array): string
    {
        preg_match_all('/\(((?:\\\\.|[^\\\\)])*)\)/s', $array, $strings);
        $text = implode('', array_map(fn (string $s) => preg_replace_callback(
            '/\\\\([0-7]{1,3}|.)/s',
            fn (array $m) => match (true) {
                ctype_digit($m[1]) => chr(octdec($m[1])),
                $m[1] === 'n' => "\n",
                $m[1] === 'r' => "\r",
                $m[1] === 't' => "\t",
                default => $m[1],
            },
            $s,
        ), $strings[1]));

        return mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }

    private function isEmpty(array $entry): bool
    {
        return array_filter($entry, fn ($value) => $value !== null && $value !== []) === [];
    }
}
