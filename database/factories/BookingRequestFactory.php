<?php

namespace Database\Factories;

use App\Enums\Airline;
use App\Enums\FlightTrip;
use App\Models\BookingRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingRequest>
 */
class BookingRequestFactory extends Factory
{
    public function definition(): array
    {
        $departure = fake()->dateTimeBetween('+1 week', '+2 months');

        return [
            'travel_order_no' => '2026-'.fake()->unique()->numberBetween(5000, 9999),
            'purpose' => 'To attend the '.fake()->words(3, true).' meeting.',
            'fund_source' => 'Regular Fund',
            'trip' => FlightTrip::Return,
            'airline' => Airline::CebuPacific,
            'origin' => 'Zamboanga',
            'destination' => 'Manila',
            'departure_date' => $departure,
            'departure_etd' => '12:50 pm - 2:30 pm',
            'return_date' => (clone $departure)->modify('+2 days'),
            'return_etd' => '6:40 am - 8:20 am',
            'requested_by' => 'VINCE BRADLEY C. MULOC',
            'requested_on' => now(),
            'signatories' => config('onedost.booking.signatories'),
        ];
    }

    /** With $count passengers, complete enough for the form. */
    public function withPassengers(int $count = 1): static
    {
        return $this->afterCreating(function (BookingRequest $booking) use ($count) {
            for ($i = 0; $i < $count; $i++) {
                $booking->passengers()->create([
                    'position' => $i,
                    'title' => fake()->randomElement(['Ms.', 'Mr.']),
                    'first_name' => strtoupper(fake()->firstName()),
                    'last_name' => strtoupper(fake()->lastName()),
                    'contact_number' => '0917'.fake()->numerify('#######'),
                    'email' => fake()->safeEmail(),
                    'birth_date' => fake()->dateTimeBetween('-50 years', '-22 years'),
                ]);
            }
        });
    }
}
