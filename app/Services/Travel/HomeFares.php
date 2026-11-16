<?php

namespace App\Services\Travel;

use App\Models\Traveler;

/**
 * The fares memo, applied to a traveller: what they may claim for a ride
 * between home and the office (`office`) or the airport, wharf or bus
 * terminal (`terminal`), from where they live and how far (saved on the
 * traveller). Only those home legs follow the memo automatically; rides once
 * at the destination are entered by hand.
 *
 * resources/js/Components/Travel/fares.js mirrors legKind() — change both.
 */
class HomeFares
{
    public const OFFICE = 'office';

    public const TERMINAL = 'terminal';

    /** Fields saved on the traveller (and sent with each trip form), not on the trip. */
    public const TRAVELER_FIELDS = ['home_area', 'office_distance', 'terminal_distance'];

    /** @return array<string, array{label: string, office: array<string, int>, terminal: array<string, int>}> */
    public function areas(): array
    {
        return config('onedost.liquidation.home_fares');
    }

    /** Distance bands offered for an area and destination, e.g. ['1–7 km', '8–15 km', 'Beyond 15 km']. */
    public function distances(?string $area, string $to): array
    {
        return array_keys($this->areas()[$area][$to] ?? []);
    }

    /** The memo's fare for this area and distance, or null when either is unknown. */
    public function fare(?string $area, ?string $distance, string $to): ?float
    {
        $fare = $this->areas()[$area][$to][$distance] ?? null;

        return $fare === null ? null : (float) $fare;
    }

    public function forTraveler(?Traveler $traveler, string $to): ?float
    {
        return $traveler
            ? $this->fare($traveler->home_area, $to === self::OFFICE ? $traveler->office_distance : $traveler->terminal_distance, $to)
            : null;
    }

    /**
     * Which fare a leg takes, from its place: "Residence to DOST IX Office" →
     * office, "Zamboanga Airport to Residence" → terminal, anything not
     * between home and one of those → null.
     */
    public static function legKind(?string $place): ?string
    {
        $place = (string) $place;
        if (! preg_match('/\b(residence|home)\b/i', $place)) {
            return null;
        }
        if (preg_match('/\b(airport|port|wharf|pier|terminal|ibt)\b/i', $place)) {
            return self::TERMINAL;
        }

        return preg_match('/\b(office|dost)\b/i', $place) ? self::OFFICE : null;
    }

    /**
     * The legs with this traveller's fares on their home legs. Legs the
     * traveller has no saved fare for keep their amount.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public function applyTo(array $items, Traveler $traveler): array
    {
        return array_map(function (array $item) use ($traveler) {
            $kind = self::legKind($item['place'] ?? null);
            $fare = $kind ? $this->forTraveler($traveler, $kind) : null;

            return $fare === null ? $item : [...$item, 'transport_cost' => $fare];
        }, $items);
    }

    /**
     * True when one of these legs is a home leg the traveller has no saved
     * fare for (so it kept someone else's amount).
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function missingFor(array $items, ?Traveler $traveler): bool
    {
        foreach ($items as $item) {
            $kind = self::legKind($item['place'] ?? null);
            if ($kind && $this->forTraveler($traveler, $kind) === null) {
                return true;
            }
        }

        return false;
    }
}
