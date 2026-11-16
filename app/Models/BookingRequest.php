<?php

namespace App\Models;

use App\Enums\Airline;
use App\Enums\FlightTrip;
use Database\Factories\BookingRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Request for Airline Booking: one flight (out, and back unless one-way)
 * for one or more passengers, three to a printed form.
 */
class BookingRequest extends Model
{
    /** @use HasFactory<BookingRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'travel_order_no',
        'purpose',
        'fund_source',
        'project',
        'trip',
        'airline',
        'origin',
        'destination',
        'departure_date',
        'departure_etd',
        'return_date',
        'return_etd',
        'baggage_justification',
        'boarding_passes_submitted',
        'requested_by',
        'requested_on',
        'signatories',
        'submitted_on',
        'booking_reference',
    ];

    protected function casts(): array
    {
        return [
            'trip' => FlightTrip::class,
            'airline' => Airline::class,
            'departure_date' => 'date',
            'return_date' => 'date',
            'requested_on' => 'date',
            'submitted_on' => 'date',
            'boarding_passes_submitted' => 'boolean',
            'signatories' => 'array',
        ];
    }

    public function passengers(): HasMany
    {
        return $this->hasMany(BookingPassenger::class)->orderBy('position');
    }

    /** booked (has a booking reference) · submitted (with the Supply Unit) · draft. */
    public function status(): string
    {
        return match (true) {
            filled($this->booking_reference) => 'booked',
            $this->submitted_on !== null => 'submitted',
            default => 'draft',
        };
    }

    /** "Zamboanga → Manila", or "Zamboanga ⇄ Manila" for a return flight. */
    public function flightRoute(): string
    {
        return $this->origin.($this->trip?->hasReturn() ? ' ⇄ ' : ' → ').$this->destination;
    }

    /**
     * What the form marks required (*) that is still blank, e.g.
     * "Passenger 2: date of birth". Edit.vue mirrors these rules — change both.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        $missing = [];
        if (blank($this->departure_etd)) {
            $missing[] = 'Preferred ETD (going)';
        }
        if ($this->trip?->hasReturn()) {
            if ($this->return_date === null) {
                $missing[] = 'Return date';
            }
            if (blank($this->return_etd)) {
                $missing[] = 'Preferred ETD (return)';
            }
        }

        foreach ($this->passengers as $i => $passenger) {
            $blank = array_keys(array_filter([
                'contact number' => blank($passenger->contact_number),
                'email' => blank($passenger->email),
                'date of birth' => $passenger->birth_date === null,
            ]));
            if ($blank) {
                $missing[] = 'Passenger '.($i + 1).': '.implode(', ', $blank);
            }
        }

        return $missing;
    }

    /** A signatory's name or title, falling back to today's configured default. */
    public function signatory(string $key): string
    {
        return $this->signatories[$key] ?? config("onedost.booking.signatories.{$key}", '');
    }
}
