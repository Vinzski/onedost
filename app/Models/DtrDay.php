<?php

namespace App\Models;

use App\Enums\DtrSlot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One day of a Daily Time Record, as the DTR system printed it. */
class DtrDay extends Model
{
    protected $fillable = [
        'traveler_id',
        'date',
        'am_arrival',
        'am_departure',
        'pm_arrival',
        'pm_departure',
        'undertime_hours',
        'undertime_minutes',
        'remark',
        'remark_slots',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'remark_slots' => 'array',
        ];
    }

    public function traveler(): BelongsTo
    {
        return $this->belongsTo(Traveler::class);
    }

    /** A remark across every time ("NON-WORKING DAY"): nothing that day can be blank. */
    public function isOff(): bool
    {
        return count($this->remark_slots ?? []) === count(DtrSlot::cases());
    }

    /**
     * The times left empty that a remark doesn't account for.
     *
     * @return list<DtrSlot>
     */
    public function blankSlots(): array
    {
        return array_values(array_filter(
            DtrSlot::cases(),
            fn (DtrSlot $slot) => blank($this->{$slot->value}) && ! in_array($slot->value, $this->remark_slots ?? [], true),
        ));
    }
}
