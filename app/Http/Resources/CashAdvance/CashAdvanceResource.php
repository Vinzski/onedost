<?php

namespace App\Http\Resources\CashAdvance;

use App\Models\CashAdvance;
use App\Models\CashAdvanceItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Everything the cash advance form edits.
 *
 * @mixin CashAdvance
 */
class CashAdvanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $liquidation = $this->liquidation;

        return [
            'id' => $this->id,
            'traveler_name' => $this->traveler_name,
            'position' => $this->position,
            'employee_no' => $this->employee_no,
            'official_station' => $this->official_station,
            'division' => $this->division,
            'travel_order_no' => $this->travel_order_no,
            'travel_order_date' => $this->travel_order_date?->toDateString(),
            'departure_date' => $this->departure_date->toDateString(),
            'return_date' => $this->return_date->toDateString(),
            'venue' => $this->venue,
            'destination_code' => $this->destination_code,
            'lunch_provided' => $this->lunch_provided,
            'purpose' => $this->purpose,
            'fund_cluster' => $this->fund_cluster,
            'requested_on' => $this->requested_on->toDateString(),
            'signatories' => $this->signatories,
            'has_travel_order' => $this->has_travel_order,
            'items' => $this->items->map(fn (CashAdvanceItem $item) => [
                'date' => $item->date?->toDateString(),
                'place' => $item->place,
                'departure_time' => $item->departure_time,
                'arrival_time' => $item->arrival_time,
                'transport_means' => $item->transport_means,
                'transport_cost' => $item->transport_cost,
                'per_diem' => $item->per_diem,
                'other_cost' => $item->other_cost,
            ])->all(),
            'liquidation' => $liquidation ? [
                'id' => $liquidation->id,
                'submitted_on' => $liquidation->submitted_on?->toDateString(),
            ] : null,
            'deadline' => $this->deadline()->toDateString(),
            'status' => $this->status(),
        ];
    }
}
