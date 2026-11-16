<?php

namespace App\Http\Resources\CashAdvance;

use App\Models\CashAdvance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the cash advances list.
 *
 * @mixin CashAdvance
 */
class CashAdvanceSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'traveler_name' => $this->traveler_name,
            'position' => $this->position,
            'travel_order_no' => $this->travel_order_no,
            'travel_dates' => $this->travelDates(),
            'venue' => $this->venue,
            'amount' => $this->requestedAmount(),
            'requested_on' => $this->requested_on->toDateString(),
            'deadline' => $this->deadline()->toDateString(),
            'status' => $this->status(),
            'liquidation_id' => $this->liquidation?->id,
            'missing_papers' => $this->missingPapers(),
        ];
    }
}
