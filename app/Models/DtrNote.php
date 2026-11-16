<?php

namespace App\Models;

use App\Enums\DtrReason;
use App\Enums\DtrSlot;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Why some of a person's DTR times are blank, over one day or several. */
class DtrNote extends Model
{
    protected $fillable = [
        'traveler_id',
        'starts_on',
        'ends_on',
        'slots',
        'reason',
        'details',
        'actual_time',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'slots' => 'array',
            'reason' => DtrReason::class,
        ];
    }

    public function traveler(): BelongsTo
    {
        return $this->belongsTo(Traveler::class);
    }

    public function isWholeDay(): bool
    {
        return ($this->slots ?? []) === [];
    }

    /** Whether it explains this time on this day. */
    public function covers(CarbonInterface $date, DtrSlot $slot): bool
    {
        return $date->between($this->starts_on, $this->ends_on)
            && ($this->isWholeDay() || in_array($slot->value, $this->slots, true));
    }

    /** "Time in, Time out", or "Whole day". */
    public function slotsLabel(bool $form = false): string
    {
        if ($this->isWholeDay()) {
            return 'Whole day';
        }

        return collect(DtrSlot::cases())
            ->filter(fn (DtrSlot $slot) => in_array($slot->value, $this->slots, true))
            ->map(fn (DtrSlot $slot) => $form ? $slot->formLabel() : $slot->label())
            ->implode(', ');
    }

    /** "Sep 14", or "Sep 14 – 16" for a run of days. */
    public function datesLabel(): string
    {
        [$from, $to] = [$this->starts_on, $this->ends_on];

        return match (true) {
            $from->isSameDay($to) => $from->format('M j'),
            $from->isSameMonth($to) => $from->format('M j').' – '.$to->format('j'),
            default => $from->format('M j').' – '.$to->format('M j'),
        };
    }
}
