<?php

namespace App\Http\Resources\BookingRequest;

use App\Models\BookingPassenger;
use App\Models\BookingRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A booking request as its edit form needs it.
 *
 * @mixin BookingRequest
 */
class BookingRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'travel_order_no' => $this->travel_order_no,
            'purpose' => $this->purpose,
            'fund_source' => $this->fund_source,
            'project' => $this->project,
            'trip' => $this->trip->value,
            'airline' => $this->airline->value,
            'origin' => $this->origin,
            'destination' => $this->destination,
            'departure_date' => $this->departure_date->toDateString(),
            'departure_etd' => $this->departure_etd,
            'return_date' => $this->return_date?->toDateString(),
            'return_etd' => $this->return_etd,
            'baggage_justification' => $this->baggage_justification,
            'boarding_passes_submitted' => $this->boarding_passes_submitted,
            'requested_by' => $this->requested_by,
            'requested_on' => $this->requested_on->toDateString(),
            'signatories' => $this->signatories,
            'submitted_on' => $this->submitted_on?->toDateString(),
            'booking_reference' => $this->booking_reference,
            'status' => $this->status(),
            'passengers' => $this->passengers->map(fn (BookingPassenger $p) => [
                'traveler_id' => $p->traveler_id,
                'title' => $p->title,
                'first_name' => $p->first_name,
                'last_name' => $p->last_name,
                'remarks' => $p->remarks,
                'id_number' => $p->id_number,
                'contact_number' => $p->contact_number,
                'email' => $p->email,
                'birth_date' => $p->birth_date?->toDateString(),
                'going_baggage' => $p->going_baggage,
                'return_baggage' => $p->return_baggage,
            ])->values(),
        ];
    }
}
