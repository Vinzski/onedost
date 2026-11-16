<?php

namespace Database\Factories;

use App\Models\CashAdvance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashAdvance>
 */
class CashAdvanceFactory extends Factory
{
    public function definition(): array
    {
        $departure = fake()->dateTimeBetween('+5 days', '+30 days');
        $return = (clone $departure)->modify('+2 days');

        return [
            'traveler_name' => strtoupper(fake()->firstName().' '.fake()->randomLetter().'. '.fake()->lastName()),
            'position' => 'Project Technical Assistant II',
            'employee_no' => fake()->bothify('???-####'),
            'official_station' => 'DOST- Zamboanga City',
            'division' => 'Technical Operations Services',
            'travel_order_no' => '2026-'.fake()->numberBetween(1000, 9999),
            'travel_order_date' => (clone $departure)->modify('-10 days')->format('Y-m-d'),
            'departure_date' => $departure->format('Y-m-d'),
            'return_date' => $return->format('Y-m-d'),
            'venue' => 'Pagadian City, Zamboanga del Sur',
            'purpose' => 'For on-site monitoring of projects in Pagadian City.',
            'fund_cluster' => 'Regular Fund',
            'requested_on' => (clone $departure)->modify('-7 days')->format('Y-m-d'),
            'signatories' => config('onedost.liquidation.signatories'),
        ];
    }

    /** A 3-day planned itinerary: ₱150 commutes each way, ₱1,500 per diem, half on the last day — ₱3,300. */
    public function withItinerary(): static
    {
        return $this->afterCreating(function (CashAdvance $cashAdvance) {
            $d = $cashAdvance->departure_date;
            $legs = [
                [$d, 'Residence to DOST IX Office', 'Public', 150, 1500],
                [$d, 'DOST IX Office to Pagadian', 'Official Vehicle', 0, 0],
                [$d->addDay(), 'Still in Pagadian', 'Official Vehicle', 0, 1500],
                [$d->addDays(2), 'Pagadian to DOST IX Office', 'Official Vehicle', 0, 0],
                [$d->addDays(2), 'DOST IX Office to Residence', 'Public', 150, 0],
            ];
            foreach ($legs as $i => [$date, $place, $means, $fare, $perDiem]) {
                $cashAdvance->items()->create([
                    'position' => $i, 'date' => $date, 'place' => $place, 'departure_time' => '08:00', 'arrival_time' => '09:00',
                    'transport_means' => $means, 'transport_cost' => $fare, 'per_diem' => $perDiem,
                ]);
            }
        });
    }
}
