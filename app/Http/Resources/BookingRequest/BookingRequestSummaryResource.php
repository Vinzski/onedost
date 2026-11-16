<?php

namespace App\Http\Resources\BookingRequest;

use App\Models\BookingPassenger;
use App\Models\BookingRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the booking requests list.
 *
 * @mixin BookingRequest
 */
class BookingRequestSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'travel_order_no' => $this->travel_order_no,
            'route' => $this->flightRoute(),
            'trip' => $this->trip->label(),
            'airline' => $this->airline->label(),
            'departure_date' => $this->departure_date->toDateString(),
            'return_date' => $this->return_date?->toDateString(),
            'passengers' => $this->passengers->map(fn (BookingPassenger $p) => $p->fullName())->values(),
            'status' => $this->status(),
            'submitted_on' => $this->submitted_on?->toDateString(),
            'booking_reference' => $this->booking_reference,
            'missing' => $this->missing(),
        ];
    }
}
