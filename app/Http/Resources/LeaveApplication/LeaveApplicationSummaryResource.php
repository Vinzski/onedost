<?php

namespace App\Http\Resources\LeaveApplication;

use App\Models\LeaveApplication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the leave applications list.
 *
 * @mixin LeaveApplication
 */
class LeaveApplicationSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->fullName(),
            'position' => $this->position,
            'type' => $this->typeLabel(),
            'detail' => $this->detail?->label(),
            'days' => $this->working_days,
            'inclusive_dates' => $this->inclusiveDates(),
            'start_date' => $this->start_date->toDateString(),
            'filed_on' => $this->filed_on->toDateString(),
            'status' => $this->status(),
            'submitted_on' => $this->submitted_on?->toDateString(),
        ];
    }
}
