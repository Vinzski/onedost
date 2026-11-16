<?php

namespace App\Services\BookingRequest;

use App\Enums\Airline;
use App\Enums\FlightTrip;
use App\Models\BookingRequest;
use App\Models\CashAdvance;
use App\Models\Liquidation;
use App\Models\Traveler;
use App\Services\Travel\Destinations;
use App\Traits\HandlesTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class BookingRequestService
{
    use HandlesTransaction;

    public const PER_PAGE = 20;

    /** Passenger fields stored on the booking (the rest come from the traveller's profile). */
    private const PASSENGER_FIELDS = [
        'traveler_id', 'title', 'first_name', 'last_name', 'remarks', 'id_number', 'contact_number', 'email', 'birth_date',
        'going_baggage', 'return_baggage',
    ];

    public function __construct(private Destinations $destinations) {}

    /**
     * @param  array{search?: ?string, status?: ?string}  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        return BookingRequest::query()
            ->with('passengers')
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->where(fn (Builder $q) => $q
                ->where('travel_order_no', 'like', "%{$search}%")
                ->orWhere('destination', 'like', "%{$search}%")
                ->orWhere('purpose', 'like', "%{$search}%")
                ->orWhereHas('passengers', fn (Builder $p) => $p
                    ->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%"))))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $this->withStatus($q, $status))
            ->orderByDesc('departure_date')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    public function summary(): array
    {
        return [
            'total' => BookingRequest::count(),
            'draft' => $this->withStatus(BookingRequest::query(), 'draft')->count(),
            'submitted' => $this->withStatus(BookingRequest::query(), 'submitted')->count(),
            'booked' => $this->withStatus(BookingRequest::query(), 'booked')->count(),
        ];
    }

    /** What a new booking request starts with. */
    public function defaults(): array
    {
        return [
            'trip' => FlightTrip::Return->value,
            'airline' => Airline::Any->value,
            'origin' => config('onedost.booking.origin'),
            'fund_source' => config('onedost.liquidation.fund_cluster'),
            'requested_on' => CarbonImmutable::today()->toDateString(),
            'signatories' => config('onedost.booking.signatories'),
        ];
    }

    /**
     * Trips with a travel order (cash advances and claims), one entry per
     * travel order, newest first: what "Start from a travel order" offers,
     * with everyone on it as the passengers.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function travelOrders(int $limit = 20): Collection
    {
        $columns = ['travel_order_no', 'traveler_name', 'departure_date', 'return_date', 'destination_code', 'venue', 'purpose', 'fund_cluster'];
        $trips = CashAdvance::query()->latest('departure_date')->limit(100)->get($columns)
            ->concat(Liquidation::query()->latest('departure_date')->limit(100)->get($columns))
            ->sortByDesc(fn (Model $trip) => $trip->departure_date->toDateString());

        $booked = BookingRequest::query()->whereIn('travel_order_no', $trips->pluck('travel_order_no')->unique())->pluck('travel_order_no')->flip();

        return $trips->groupBy('travel_order_no')->take($limit)->map(function (Collection $team, string $travelOrderNo) use ($booked) {
            $trip = $team->first();

            return [
                'travel_order_no' => $travelOrderNo,
                'purpose' => $trip->purpose,
                'fund_source' => $trip->fund_cluster,
                'destination' => $this->flightCity($trip),
                'departure_date' => $trip->departure_date->toDateString(),
                'return_date' => $trip->return_date->toDateString(),
                'travel_dates' => $trip->travelDates(),
                'travelers' => $team->pluck('traveler_name')->filter()->unique(fn (string $name) => mb_strtoupper($name))->values(),
                'has_booking' => isset($booked[$travelOrderNo]),
            ];
        })->values();
    }

    public function create(array $data): array
    {
        return self::handleTransaction(function () use ($data) {
            $booking = new BookingRequest;
            $this->fill($booking, $data);

            return ['data' => $booking, 'message' => 'Booking request saved.', 'info' => null, 'status' => true];
        });
    }

    public function update(BookingRequest $booking, array $data): array
    {
        return self::handleTransaction(function () use ($booking, $data) {
            $this->fill($booking, $data);

            return ['data' => $booking, 'message' => 'Booking request saved.', 'info' => null, 'status' => true];
        });
    }

    public function delete(BookingRequest $booking): array
    {
        return self::handleTransaction(function () use ($booking) {
            $booking->delete();

            return ['data' => null, 'message' => 'Booking request deleted.', 'info' => null, 'status' => true];
        });
    }

    private function fill(BookingRequest $booking, array $data): void
    {
        $booking->fill(Arr::except($data, ['passengers']));
        // A one-way flight has no return to book.
        $oneWay = ! $booking->trip->hasReturn();
        if ($oneWay) {
            $booking->fill(['return_date' => null, 'return_etd' => null]);
        }
        $booking->save();

        // validated() can return wildcard rows out of order (a field only some
        // rows send comes first); the submitted index is the order.
        $passengers = $data['passengers'];
        ksort($passengers);

        $booking->passengers()->delete();
        foreach (array_values($passengers) as $i => $passenger) {
            $traveler = $this->rememberPassenger($passenger);
            $booking->passengers()->create([
                ...Arr::only($passenger, self::PASSENGER_FIELDS),
                'position' => $i,
                'traveler_id' => $traveler->id,
                'going_baggage' => (bool) ($passenger['going_baggage'] ?? false),
                'return_baggage' => ! $oneWay && ($passenger['return_baggage'] ?? false),
            ]);
        }
        $booking->unsetRelation('passengers');
    }

    /**
     * Save the passenger's booking details on their traveller profile, so the
     * next booking fills them in. Someone new becomes a saved traveller; a
     * blank never erases what was saved before.
     */
    private function rememberPassenger(array $passenger): Traveler
    {
        $first = Str::squish($passenger['first_name']);
        $last = Str::squish($passenger['last_name']);

        $traveler = Traveler::findPerson(filled($passenger['traveler_id'] ?? null) ? (int) $passenger['traveler_id'] : null, $first, $last);
        $traveler ??= new Traveler(['name' => mb_strtoupper("{$first} {$last}"), 'position' => '', 'official_station' => '', 'division' => '']);

        $details = Arr::only([...$passenger, 'first_name' => $first, 'last_name' => $last], Traveler::BOOKING_FIELDS);
        $traveler->fill(array_filter($details, fn ($value) => filled($value)))->save();

        return $traveler;
    }

    /** Where to fly: the trip's destination city ("Cebu City" → "Cebu"), else the venue's last part. */
    private function flightCity(Model $trip): string
    {
        $name = $this->destinations->find($trip->destination_code)[1] ?? Str::afterLast((string) $trip->venue, ',');

        return trim(preg_replace('/\s+City\.?$/i', '', trim($name, ' .')));
    }

    private function withStatus(Builder $query, string $status): Builder
    {
        return match ($status) {
            'booked' => $query->whereNotNull('booking_reference')->where('booking_reference', '!=', ''),
            'submitted' => $query->whereNotNull('submitted_on')->where(fn (Builder $q) => $q->whereNull('booking_reference')->orWhere('booking_reference', '')),
            'draft' => $query->whereNull('submitted_on')->where(fn (Builder $q) => $q->whereNull('booking_reference')->orWhere('booking_reference', '')),
            default => $query,
        };
    }
}
