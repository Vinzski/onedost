<?php

namespace App\Services\Travel;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Rebuilds resources/data/places.json from the PSA's Philippine Standard
 * Geographic Code, as published by the psgc.cloud API.
 */
class DestinationListBuilder
{
    public const SOURCE = 'https://psgc.cloud/api';

    /** Places with no province of their own, filed under this name instead. */
    private const SPECIAL_GEOGRAPHIC_AREA = '19999';

    /**
     * Fetch the PSGC and write the list to $path.
     *
     * @return array{source: string, synced_on: string, regions: array<string, string>, places: list<list<string>>}
     */
    public function sync(string $path): array
    {
        $api = Http::baseUrl(self::SOURCE)->acceptJson()->timeout(60)->retry(2, 1000);

        $list = $this->build(
            $api->get('regions')->throw()->json(),
            $api->get('provinces')->throw()->json(),
            $api->get('cities-municipalities')->throw()->json(),
        );

        File::put($path, $this->encode($list));

        return $list;
    }

    /**
     * @param  list<array{code: string, name: string}>  $regions
     * @param  list<array{code: string, name: string}>  $provinces
     * @param  list<array{code: string, name: string, type: string}>  $places
     * @return array{source: string, synced_on: string, regions: array<string, string>, places: list<list<string>>}
     */
    public function build(array $regions, array $provinces, array $places): array
    {
        $regionNames = [];
        foreach ($regions as $region) {
            $regionNames[substr($region['code'], 0, 2)] = $this->regionName($this->clean($region['name']));
        }
        ksort($regionNames);

        // PSGC codes: 2 digits region, 3 province, then city/municipality.
        $provinceNames = [];
        foreach ($provinces as $province) {
            $provinceNames[substr($province['code'], 0, 5)] = $this->clean($province['name']);
        }
        $provinceNames[self::SPECIAL_GEOGRAPHIC_AREA] = 'BARMM Special Geographic Area';

        $list = [];
        foreach ($provinces as $province) {
            $list[] = [$this->code($province['code']), $this->clean($province['name']), '', 'province'];
        }
        foreach ($places as $place) {
            // Manila's districts (sub-municipalities) travel as Manila.
            if ($place['type'] === 'SubMun') {
                continue;
            }
            $list[] = [
                $this->code($place['code']),
                $this->placeName($this->clean($place['name'])),
                $provinceNames[substr($place['code'], 0, 5)] ?? '',
                $place['type'] === 'City' ? 'city' : 'municipality',
            ];
        }

        usort($list, fn (array $a, array $b) => [Str::ascii($a[1]), $a[2]] <=> [Str::ascii($b[1]), $b[2]]);

        return [
            'source' => 'PSA Philippine Standard Geographic Code, via '.self::SOURCE,
            'synced_on' => now()->toDateString(),
            'regions' => $regionNames,
            'places' => $list,
        ];
    }

    /** One place per line, so a refresh diffs cleanly. */
    private function encode(array $list): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        $places = implode(",\n", array_map(fn (array $place) => '    '.json_encode($place, $flags), $list['places']));

        return "{\n"
            .'  "source": '.json_encode($list['source'], $flags).",\n"
            .'  "synced_on": '.json_encode($list['synced_on'], $flags).",\n"
            .'  "regions": '.json_encode($list['regions'], $flags | JSON_FORCE_OBJECT).",\n"
            ."  \"places\": [\n{$places}\n  ]\n}\n";
    }

    /** The API's codes are 10 digits; a 9-digit one lost its leading zero. */
    private function code(string|int $code): string
    {
        return str_pad((string) $code, 10, '0', STR_PAD_LEFT);
    }

    /** Trim, and repair names that were UTF-8 read as Latin-1 ("ParaÃ±aque"). */
    private function clean(string $name): string
    {
        if (str_contains($name, 'Ã')) {
            $repaired = mb_convert_encoding($name, 'ISO-8859-1', 'UTF-8');
            $name = mb_check_encoding($repaired, 'UTF-8') ? $repaired : $name;
        }

        return Str::squish($name);
    }

    /** "City of Pagadian" → "Pagadian City", as the city is written on travel papers. */
    private function placeName(string $name): string
    {
        if ($name === 'City of Manila') {
            return 'Manila';
        }

        return preg_match('/^City of (.+)$/', $name, $m) ? "{$m[1]} City" : $name;
    }

    private function regionName(string $name): string
    {
        return match ($name) {
            'MIMAROPA Region' => 'Region IV-B (MIMAROPA)',
            default => str_replace(' In ', ' in ', $name),
        };
    }
}
