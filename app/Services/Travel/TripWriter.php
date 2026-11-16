<?php

namespace App\Services\Travel;

use App\Models\Traveler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Saving what cash advances and liquidations share: the traveller and the itinerary. */
class TripWriter
{
    public function __construct(private HomeFares $fares) {}

    /**
     * Upsert the traveller's saved details, so the next form can fill them
     * in — including where they live and how far, for their fares.
     */
    public function rememberTraveler(array $data): Traveler
    {
        return Traveler::updateOrCreate(
            ['name' => $data['traveler_name']],
            Arr::only($data, ['position', 'employee_no', 'official_station', 'division', ...HomeFares::TRAVELER_FIELDS]),
        );
    }

    /**
     * Replace the itinerary with the submitted legs, in order.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function syncItems(HasMany $relation, array $items): void
    {
        $relation->delete();

        // validated() can return wildcard rows out of order (a field only some
        // rows send comes first); the submitted index is the order.
        ksort($items);

        foreach (array_values($items) as $i => $item) {
            $relation->create([
                'position' => $i,
                // Blank continues the day above.
                'date' => ($item['date'] ?? null) instanceof \DateTimeInterface ? $item['date']->format('Y-m-d') : ($item['date'] ?? null),
                'place' => $item['place'],
                'departure_time' => $item['departure_time'] ?? null,
                'arrival_time' => $item['arrival_time'] ?? null,
                'transport_means' => $item['transport_means'] ?? null,
                'transport_cost' => $item['transport_cost'] ?? 0,
                'per_diem' => $item['per_diem'] ?? 0,
                'other_cost' => $item['other_cost'] ?? 0,
            ]);
        }
    }

    /**
     * The people to add to a travel order, each with their saved profile if
     * there is one. Blanks, repeats and anyone already on it are dropped.
     *
     * @param  list<string>  $names
     * @param  iterable<string>  $onBoard  traveller names already on the travel order
     * @return list<array{name: string, traveler: ?Traveler}>
     */
    public function newcomers(array $names, iterable $onBoard): array
    {
        $seen = [];
        foreach ($onBoard as $name) {
            $seen[mb_strtolower(Str::squish($name))] = true;
        }

        $people = [];
        foreach ($names as $name) {
            $name = Str::squish($name);
            $key = mb_strtolower($name);
            if ($name === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $traveler = Traveler::whereRaw('lower(name) = ?', [$key])->first();
            $people[] = ['name' => $traveler->name ?? $name, 'traveler' => $traveler];
        }

        return $people;
    }

    /** A copy's traveller columns; someone not saved yet keeps the source's station and division. */
    public function travelerColumns(array $person, Model $source): array
    {
        $traveler = $person['traveler'];

        return [
            'traveler_id' => $traveler?->id,
            'traveler_name' => $person['name'],
            'position' => $traveler->position ?? '',
            'employee_no' => $traveler?->employee_no,
            'official_station' => $traveler->official_station ?? $source->official_station,
            'division' => $traveler->division ?? $source->division,
        ];
    }

    /**
     * The source's legs as plain rows for a copy, with the traveller's own
     * fares on the home legs.
     *
     * @return list<array<string, mixed>>
     */
    public function itemsFor(Collection $items, ?Traveler $traveler): array
    {
        $rows = $items->map(fn (Model $item) => Arr::except($item->getAttributes(), ['id', 'cash_advance_id', 'liquidation_id']))->values()->all();

        return $traveler ? $this->fares->applyTo($rows, $traveler) : $rows;
    }

    /**
     * The flash for an "add travellers" result: who was added, plus what
     * still needs their attention (a warning when anything does).
     *
     * @param  list<array{name: string, traveler: ?Traveler}>  $added
     * @return array{message: string, info: ?string}
     */
    public function addedMessage(array $added, Collection $items, string $travelOrderNo): array
    {
        if (! $added) {
            return ['message' => "Everyone picked is already on TO {$travelOrderNo}.", 'info' => 'warning'];
        }

        $message = count($added) === 1
            ? "Added {$added[0]['name']} to TO {$travelOrderNo}."
            : 'Added '.count($added)." travellers to TO {$travelOrderNo}.";

        $rows = $this->itemsFor($items, null);
        $unsaved = array_column(array_filter($added, fn (array $p) => ! $p['traveler']), 'name');
        $noFares = array_column(array_filter($added, fn (array $p) => $p['traveler'] && $this->fares->missingFor($rows, $p['traveler'])), 'name');

        $notes = array_filter([
            $unsaved ? 'Open '.implode(', ', $unsaved).' to fill in position and fares.' : null,
            $noFares ? 'No fares saved for '.implode(', ', $noFares).' — their residence legs kept the original amounts.' : null,
        ]);

        return $notes
            ? ['message' => $message.' '.implode(' ', $notes), 'info' => 'warning']
            : ['message' => $message, 'info' => null];
    }
}
