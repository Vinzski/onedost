<?php

namespace App\Http\Resources\LeaveApplication;

use App\Models\LeaveApplication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A leave application as its edit form needs it.
 *
 * @mixin LeaveApplication
 */
class LeaveApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'traveler_id' => $this->traveler_id,
            'office' => $this->office,
            'last_name' => $this->last_name,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'position' => $this->position,
            'salary' => $this->salary,
            'filed_on' => $this->filed_on->toDateString(),
            'leave_type' => $this->leave_type->value,
            'leave_type_other' => $this->leave_type_other,
            'detail' => $this->detail?->value,
            'detail_text' => $this->detail_text,
            'working_days' => $this->working_days,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'inclusive_dates' => $this->inclusive_dates,
            'commutation_requested' => $this->commutation_requested,
            'signatories' => $this->signatories,
            'submitted_on' => $this->submitted_on?->toDateString(),
            'outcome' => $this->outcome?->value,
            'status' => $this->status(),
            'full_name' => $this->fullName(),
            'type_label' => $this->typeLabel(),
        ];
    }
}
