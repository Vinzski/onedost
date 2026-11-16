<?php

namespace App\Http\Requests\Concerns;

use App\Services\Travel\Destinations;
use App\Services\Travel\HomeFares;
use Closure;
use Illuminate\Validation\Rule;

/** Validation shared by cash advances and liquidations: traveller, trip, itinerary, signatories. */
trait ValidatesTrip
{
    protected const MONEY = ['nullable', 'numeric', 'min:0', 'max:9999999999'];

    protected function tripRules(): array
    {
        return [
            'traveler_name' => ['required', 'string', 'max:255'],
            'position' => ['required', 'string', 'max:255'],
            'employee_no' => ['nullable', 'string', 'max:30'],
            'official_station' => ['required', 'string', 'max:255'],
            'division' => ['required', 'string', 'max:255'],
            // Saved with the traveller (fares memo): where they live and how far.
            'home_area' => ['nullable', 'string', Rule::in(array_keys(app(HomeFares::class)->areas()))],
            'office_distance' => ['nullable', 'string', Rule::in(app(HomeFares::class)->distances($this->input('home_area'), HomeFares::OFFICE))],
            'terminal_distance' => ['nullable', 'string', Rule::in(app(HomeFares::class)->distances($this->input('home_area'), HomeFares::TERMINAL))],

            'travel_order_no' => ['required', 'string', 'max:50'],
            'travel_order_date' => ['nullable', 'date'],
            'departure_date' => ['required', 'date'],
            'return_date' => ['required', 'date', 'after_or_equal:departure_date'],
            'venue' => ['required', 'string', 'max:255'],
            // PSGC code of the place picked from the list; sets the EO 77 per diem.
            'destination_code' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (! app(Destinations::class)->has($value)) {
                    $fail('Pick the destination from the list.');
                }
            }],
            'purpose' => ['required', 'string', 'max:1000'],
            'fund_cluster' => ['required', 'string', 'max:60'],
            // Lunch provided at the activity; the form takes it off the per diem.
            'lunch_provided' => ['boolean'],
            'has_travel_order' => ['boolean'],

            'items' => ['required', 'array', 'min:1', 'max:60'],
            // Blank continues the day above, as on the paper IOT.
            'items.*.date' => ['nullable', 'date', 'after_or_equal:departure_date', 'before_or_equal:return_date'],
            'items.*.place' => ['required', 'string', 'max:255'],
            'items.*.departure_time' => ['nullable', 'date_format:H:i'],
            'items.*.arrival_time' => ['nullable', 'date_format:H:i'],
            'items.*.transport_means' => ['nullable', 'string', 'max:40'],
            'items.*.transport_cost' => self::MONEY,
            'items.*.per_diem' => self::MONEY,
            'items.*.other_cost' => self::MONEY,

            'signatories' => ['required', 'array'],
            'signatories.supervisor_name' => ['required', 'string', 'max:255'],
            'signatories.supervisor_title' => ['required', 'string', 'max:255'],
            'signatories.director_name' => ['required', 'string', 'max:255'],
            'signatories.director_title' => ['required', 'string', 'max:255'],
            'signatories.accountant_name' => ['required', 'string', 'max:255'],
            'signatories.accountant_title' => ['required', 'string', 'max:255'],
        ];
    }

    protected function tripMessages(): array
    {
        return [
            'items.required' => 'Add at least one leg to the itinerary.',
            'items.*.date.after_or_equal' => 'This date is before the trip starts.',
            'items.*.date.before_or_equal' => 'This date is after the trip ends.',
            'items.*.place.required' => 'Say where this leg went.',
            'return_date.after_or_equal' => 'The return date can\'t be before the departure date.',
        ];
    }

    protected function tripAttributes(): array
    {
        return [
            'traveler_name' => 'name',
            'home_area' => 'where they live',
            'office_distance' => 'distance to the office',
            'terminal_distance' => 'distance to the airport, wharf or terminal',
            'travel_order_no' => 'travel order no.',
            'items.*.date' => 'date',
            'items.*.departure_time' => 'departure time',
            'items.*.arrival_time' => 'arrival time',
            'items.*.transport_cost' => 'transportation',
            'items.*.per_diem' => 'per diem',
            'items.*.other_cost' => 'other expenses',
            'signatories.*' => 'signatory',
        ];
    }
}
