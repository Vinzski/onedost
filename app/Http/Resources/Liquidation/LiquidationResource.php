<?php

namespace App\Http\Resources\Liquidation;

use App\Models\Liquidation;
use App\Models\LiquidationItem;
use App\Models\LiquidationPhoto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Everything the liquidation form edits.
 *
 * @mixin Liquidation
 */
class LiquidationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
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
            'cash_advance_amount' => $this->cash_advance_amount,
            'dv_no' => $this->dv_no,
            'dv_date' => $this->dv_date?->toDateString(),
            'or_no' => $this->or_no,
            'or_date' => $this->or_date?->toDateString(),
            'supplies_amount' => $this->supplies_amount,
            'other_expenses_amount' => $this->other_expenses_amount,
            'persons_contacted' => $this->persons_contacted ?? [],
            'output_summary' => $this->output_summary,
            'follow_up' => $this->follow_up,
            'compliance' => $this->compliance->value,
            'compliance_note' => $this->compliance_note,
            'evidence' => $this->evidence ?? [],
            'activity_title' => $this->activity_title,
            'activity_body' => $this->activity_body,
            'signatories' => $this->signatories,
            'submitted_on' => $this->submitted_on?->toDateString(),
            'has_travel_order' => $this->has_travel_order,
            'has_certificate_of_appearance' => $this->has_certificate_of_appearance,
            'receipt_certificates' => array_map(fn (array $c) => ['lines' => $c['lines']], $this->receiptCertificates()),
            'items' => $this->items->map(fn (LiquidationItem $item) => [
                'date' => $item->date?->toDateString(),
                'place' => $item->place,
                'departure_time' => $item->departure_time,
                'arrival_time' => $item->arrival_time,
                'transport_means' => $item->transport_means,
                'transport_cost' => $item->transport_cost,
                'per_diem' => $item->per_diem,
                'other_cost' => $item->other_cost,
            ])->all(),
            'photos' => $this->photos->map(fn (LiquidationPhoto $photo) => [
                'id' => $photo->id,
                'name' => $photo->original_name,
                'url' => route($this->kind->routePrefix().'.photos.show', [$this->id, $photo->id]),
            ])->all(),
            'cash_advance' => $this->cashAdvance ? [
                'id' => $this->cashAdvance->id,
                'travel_order_no' => $this->cashAdvance->travel_order_no,
                'requested_on' => $this->cashAdvance->requested_on->toDateString(),
            ] : null,
            'deadline' => $this->deadline()->toDateString(),
            'status' => $this->status(),
        ];
    }
}
