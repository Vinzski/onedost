<?php

namespace App\Services\Travel;

use Illuminate\Support\Facades\File;

/**
 * Where in the Philippines a trip can go — every province, city and
 * municipality in the PSGC (resources/data/places.json, refreshed with
 * `php artisan travel:sync-places`) — and the per diem each one earns.
 *
 * A place is [PSGC code, name, province, type]; the first two digits of
 * the code are its region, which sets the EO 77 rate.
 */
class Destinations
{
    /** @var array{regions: array<string, string>, places: list<array{0: string, 1: string, 2: string, 3: string}>}|null */
    private ?array $list = null;

    /** @var array<string, array{0: string, 1: string, 2: string, 3: string}>|null places by code */
    private ?array $codes = null;

    public static function path(): string
    {
        return resource_path('data/places.json');
    }

    /** @return array{regions: array<string, string>, places: list<array{0: string, 1: string, 2: string, 3: string}>} */
    public function list(): array
    {
        return $this->list ??= json_decode(File::get(self::path()), true, flags: JSON_THROW_ON_ERROR);
    }

    public function has(?string $code): bool
    {
        return $this->find($code) !== null;
    }

    /** @return array{0: string, 1: string, 2: string, 3: string}|null [code, name, province, type] */
    public function find(?string $code): ?array
    {
        $this->codes ??= array_column($this->list()['places'], null, 0);

        return $this->codes[(string) $code] ?? null;
    }

    /**
     * EO 77 s. 2019 daily travel expense at the destination; the default
     * rate while no destination is picked.
     */
    public function rate(?string $code): float
    {
        $settings = config('onedost.liquidation');
        $rate = $code ? ($settings['dte_rates'][substr($code, 0, 2)] ?? null) : null;

        return (float) ($rate ?? $settings['per_diem_rate']);
    }
}
