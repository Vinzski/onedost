<?php

namespace App\Http\Resources\Liquidation;

use App\Models\Liquidation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the liquidations list.
 *
 * @mixin Liquidation
 */
class LiquidationSummaryResource extends JsonResource
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
            'purpose' => $this->purpose,
            'total_spent' => $this->totalSpent(),
            'cash_advance' => (float) $this->cash_advance_amount,
            'refund' => $this->refund(),
            'reimbursement' => $this->reimbursement(),
            'deadline' => $this->deadline()->toDateString(),
            'submitted_on' => $this->submitted_on?->toDateString(),
            'status' => $this->status(),
            'missing_papers' => $this->missingPapers(),
        ];
    }
}
