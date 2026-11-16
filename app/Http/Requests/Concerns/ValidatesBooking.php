<?php

namespace App\Http\Requests\Concerns;

use App\Enums\Airline;
use App\Enums\FlightTrip;
use Illuminate\Validation\Rule;

/** Validation shared by creating and updating a booking request. */
trait ValidatesBooking
{
    protected function bookingRules(): array
    {
        return [
            'travel_order_no' => ['required', 'string', 'max:50'],
            'purpose' => ['required', 'string', 'max:1000'],
            'fund_source' => ['required', 'string', 'max:60'],
            'project' => ['nullable', 'string', 'max:255'],

            'trip' => ['required', Rule::enum(FlightTrip::class)],
            'airline' => ['required', Rule::enum(Airline::class)],
            'origin' => ['required', 'string', 'max:100'],
            'destination' => ['required', 'string', 'max:100'],
            'departure_date' => ['required', 'date'],
            'departure_etd' => ['nullable', 'string', 'max:60'],
            'return_date' => ['nullable', 'date', 'after_or_equal:departure_date'],
            'return_etd' => ['nullable', 'string', 'max:60'],
            'baggage_justification' => ['nullable', 'string', 'max:255'],
            'boarding_passes_submitted' => ['nullable', 'boolean'],

            'requested_by' => ['required', 'string', 'max:255'],
            'requested_on' => ['required', 'date'],
            'signatories' => ['required', 'array'],
            'signatories.noted_name' => ['required', 'string', 'max:255'],
            'signatories.noted_title' => ['required', 'string', 'max:255'],
            'signatories.approved_name' => ['required', 'string', 'max:255'],
            'signatories.approved_title' => ['required', 'string', 'max:255'],

            'submitted_on' => ['nullable', 'date'],
            'booking_reference' => ['nullable', 'string', 'max:100'],

            'passengers' => ['required', 'array', 'min:1', 'max:30'],
            'passengers.*.traveler_id' => ['nullable', 'integer', 'exists:travelers,id'],
            'passengers.*.title' => ['nullable', Rule::in(['Ms.', 'Mr.'])],
            'passengers.*.first_name' => ['required', 'string', 'max:100'],
            'passengers.*.last_name' => ['required', 'string', 'max:100'],
            'passengers.*.remarks' => ['nullable', 'string', 'max:100'],
            'passengers.*.id_number' => ['nullable', 'string', 'max:60'],
            'passengers.*.contact_number' => ['nullable', 'string', 'max:30'],
            'passengers.*.email' => ['nullable', 'email', 'max:255'],
            'passengers.*.birth_date' => ['nullable', 'date', 'before:today'],
            'passengers.*.going_baggage' => ['boolean'],
            'passengers.*.return_baggage' => ['boolean'],
        ];
    }

    protected function bookingMessages(): array
    {
        return [
            'passengers.required' => 'Add at least one passenger.',
            'passengers.*.first_name.required' => 'First name, as on their ID.',
            'passengers.*.last_name.required' => 'Last name, as on their ID.',
            'passengers.*.birth_date.before' => 'This birth date is in the future.',
            'return_date.after_or_equal' => 'The return flight can\'t be before the departure.',
        ];
    }

    protected function bookingAttributes(): array
    {
        return [
            'travel_order_no' => 'travel order no.',
            'fund_source' => 'source of funds',
            'departure_etd' => 'preferred ETD',
            'return_etd' => 'preferred ETD',
            'passengers.*.email' => 'email',
            'passengers.*.birth_date' => 'date of birth',
            'passengers.*.contact_number' => 'contact number',
            'passengers.*.id_number' => 'ID or passport no.',
            'signatories.*' => 'signatory',
        ];
    }
}
