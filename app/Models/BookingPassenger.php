<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One passenger column on a Request for Airline Booking. */
class BookingPassenger extends Model
{
    protected $fillable = [
        'position',
        'traveler_id',
        'title',
        'first_name',
        'last_name',
        'remarks',
        'id_number',
        'contact_number',
        'email',
        'birth_date',
        'going_baggage',
        'return_baggage',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'going_baggage' => 'boolean',
            'return_baggage' => 'boolean',
        ];
    }

    public function bookingRequest(): BelongsTo
    {
        return $this->belongsTo(BookingRequest::class);
    }

    public function traveler(): BelongsTo
    {
        return $this->belongsTo(Traveler::class);
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
