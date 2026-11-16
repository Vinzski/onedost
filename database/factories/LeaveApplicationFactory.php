<?php

namespace Database\Factories;

use App\Enums\LeaveDetail;
use App\Enums\LeaveType;
use App\Models\LeaveApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveApplication>
 */
class LeaveApplicationFactory extends Factory
{
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('+1 week', '+2 months');

        return [
            'office' => config('onedost.leave.office'),
            'last_name' => strtoupper(fake()->lastName()),
            'first_name' => strtoupper(fake()->firstName()),
            'middle_name' => strtoupper(fake()->randomLetter()).'.',
            'position' => 'PTA III',
            'filed_on' => now(),
            'leave_type' => LeaveType::Vacation,
            'detail' => LeaveDetail::WithinPhilippines,
            'working_days' => 1,
            'start_date' => $start,
            'end_date' => $start,
            'commutation_requested' => false,
            'signatories' => config('onedost.leave.signatories'),
        ];
    }
}
