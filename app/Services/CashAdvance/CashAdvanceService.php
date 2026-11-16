<?php

namespace App\Services\CashAdvance;

use App\Models\CashAdvance;
use App\Models\CashAdvanceItem;
use App\Models\Liquidation;
use App\Services\Liquidation\LiquidationService;
use App\Services\Travel\HomeFares;
use App\Services\Travel\TripWriter;
use App\Traits\HandlesTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

class CashAdvanceService
{
    use HandlesTransaction;

    public const PER_PAGE = 20;

    /** Copied from the cash advance into its liquidation. */
    private const TRIP_FIELDS = [
        'traveler_id', 'traveler_name', 'position', 'employee_no', 'official_station', 'division',
        'travel_order_no', 'travel_order_date', 'departure_date', 'return_date', 'venue', 'destination_code', 'lunch_provided',
        'purpose', 'fund_cluster', 'signatories', 'has_travel_order',
    ];

    public function __construct(
        private TripWriter $trips,
        private LiquidationService $liquidations,
    ) {}

    /**
     * @param  array{search?: ?string, status?: ?string}  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        return CashAdvance::query()
            ->with(['items', 'liquidation'])
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->where(fn (Builder $q) => $q
                ->where('traveler_name', 'like', "%{$search}%")
                ->orWhere('travel_order_no', 'like', "%{$search}%")
                ->orWhere('purpose', 'like', "%{$search}%")
                ->orWhere('venue', 'like', "%{$search}%")))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => match ($status) {
                'pending' => $this->notLiquidated($q),
                'overdue' => $this->overdue($q),
                'liquidated' => $q->whereHas('liquidation', fn (Builder $l) => $l->whereNotNull('submitted_on')),
                default => $q,
            })
            ->orderByDesc('departure_date')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    public function summary(): array
    {
        return [
            'total' => CashAdvance::count(),
            'pending' => $this->notLiquidated(CashAdvance::query())->count(),
            'overdue' => $this->overdue(CashAdvance::query())->count(),
            'liquidated' => CashAdvance::whereHas('liquidation', fn (Builder $l) => $l->whereNotNull('submitted_on'))->count(),
        ];
    }

    /** What a new cash advance starts with. */
    public function defaults(): array
    {
        $settings = config('onedost.liquidation');

        return [
            'official_station' => $settings['official_station'],
            'division' => $settings['division'],
            'fund_cluster' => $settings['fund_cluster'],
            'requested_on' => CarbonImmutable::today()->toDateString(),
            'signatories' => $settings['signatories'],
        ];
    }

    public function create(array $data): array
    {
        return self::handleTransaction(function () use ($data) {
            $cashAdvance = new CashAdvance;
            $this->fill($cashAdvance, $data);

            return ['data' => $cashAdvance, 'message' => 'Cash advance saved.', 'info' => null, 'status' => true];
        });
    }

    public function update(CashAdvance $cashAdvance, array $data): array
    {
        return self::handleTransaction(function () use ($cashAdvance, $data) {
            $this->fill($cashAdvance, $data);

            return ['data' => $cashAdvance, 'message' => 'Cash advance saved.', 'info' => null, 'status' => true];
        });
    }

    public function delete(CashAdvance $cashAdvance): array
    {
        return self::handleTransaction(function () use ($cashAdvance) {
            $cashAdvance->delete();

            return ['data' => null, 'message' => 'Cash advance deleted.', 'info' => null, 'status' => true];
        });
    }

    /**
     * Others on the same travel order (group travel): one cash advance each,
     * with the same trip and planned itinerary, their own saved details and
     * their own fares from home. Never linked to the source's liquidation.
     *
     * @param  list<string>  $names
     */
    public function addTravelers(CashAdvance $source, array $names): array
    {
        return self::handleTransaction(function () use ($source, $names) {
            $people = $this->trips->newcomers($names, $this->team($source)->pluck('traveler_name'));

            $copies = [];
            foreach ($people as $person) {
                $copy = $source->replicate();
                $copy->fill($this->trips->travelerColumns($person, $source))->save();
                foreach ($this->trips->itemsFor($source->items, $person['traveler']) as $item) {
                    $copy->items()->create($item);
                }
                $copies[] = $copy;
            }

            return ['data' => $copies, ...$this->trips->addedMessage($people, $source->items, $source->travel_order_no), 'status' => true];
        });
    }

    /** Everyone's cash advance on this one's travel order, this one included. */
    public function team(CashAdvance $cashAdvance): Builder
    {
        return CashAdvance::query()->where('travel_order_no', $cashAdvance->travel_order_no)->orderBy('traveler_name')->orderBy('id');
    }

    /**
     * Start the liquidation from the cash advance: traveller, travel order,
     * trip and planned itinerary are copied, and the amount advanced (the IOT total) becomes
     * its cash advance. Opens the existing one if it was already started.
     */
    public function liquidate(CashAdvance $cashAdvance): array
    {
        return self::handleTransaction(function () use ($cashAdvance) {
            if ($existing = $cashAdvance->liquidation) {
                return ['data' => $existing, 'message' => 'This cash advance already has a liquidation.', 'info' => null, 'status' => true];
            }

            $defaults = $this->liquidations->defaults();
            $liquidation = Liquidation::create([
                ...$cashAdvance->only(self::TRIP_FIELDS),
                'cash_advance_id' => $cashAdvance->id,
                'cash_advance_amount' => $cashAdvance->requestedAmount(),
                'compliance' => $defaults['compliance'],
                'evidence' => $defaults['evidence'],
                'output_summary' => $defaults['output_summary'],
            ]);

            $this->trips->syncItems(
                $liquidation->items(),
                $cashAdvance->items->map(fn (CashAdvanceItem $item) => $item->only([
                    'date', 'place', 'departure_time', 'arrival_time', 'transport_means', 'transport_cost', 'per_diem', 'other_cost',
                ]))->all(),
            );

            return [
                'data' => $liquidation,
                'message' => 'Liquidation started from the cash advance — update anything that changed on the trip.',
                'info' => null,
                'status' => true,
            ];
        });
    }

    private function fill(CashAdvance $cashAdvance, array $data): void
    {
        $traveler = $this->trips->rememberTraveler($data);

        $cashAdvance->fill([
            ...Arr::except($data, ['items', ...HomeFares::TRAVELER_FIELDS]),
            'traveler_id' => $traveler->id,
            'lunch_provided' => (bool) ($data['lunch_provided'] ?? false),
            'has_travel_order' => (bool) ($data['has_travel_order'] ?? false),
        ])->save();

        $this->trips->syncItems($cashAdvance->items(), $data['items']);
        $cashAdvance->unsetRelation('items');
    }

    /** No submitted liquidation yet. */
    private function notLiquidated(Builder $query): Builder
    {
        return $query->whereDoesntHave('liquidation', fn (Builder $l) => $l->whereNotNull('submitted_on'));
    }

    /** Not liquidated, and past the 30 days allowed after the trip ended. */
    private function overdue(Builder $query): Builder
    {
        $cutoff = CarbonImmutable::today()->subDays(config('onedost.liquidation.deadline_days'));

        return $this->notLiquidated($query)->where('return_date', '<', $cutoff->toDateString());
    }
}
