<?php

namespace App\Models;

use App\Enums\LeaveDetail;
use App\Enums\LeaveOutcome;
use App\Enums\LeaveType;
use Carbon\CarbonInterface;
use Database\Factories\LeaveApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An Application for Leave (CSC Form No. 6): one employee, one leave. */
class LeaveApplication extends Model
{
    /** @use HasFactory<LeaveApplicationFactory> */
    use HasFactory;

    protected $fillable = [
        'traveler_id',
        'office',
        'last_name',
        'first_name',
        'middle_name',
        'position',
        'salary',
        'filed_on',
        'leave_type',
        'leave_type_other',
        'detail',
        'detail_text',
        'working_days',
        'start_date',
        'end_date',
        'inclusive_dates',
        'commutation_requested',
        'signatories',
        'submitted_on',
        'outcome',
    ];

    protected function casts(): array
    {
        return [
            'leave_type' => LeaveType::class,
            'detail' => LeaveDetail::class,
            'outcome' => LeaveOutcome::class,
            'salary' => 'decimal:2',
            'working_days' => 'float',
            'filed_on' => 'date',
            'start_date' => 'date',
            'end_date' => 'date',
            'submitted_on' => 'date',
            'commutation_requested' => 'boolean',
            'signatories' => 'array',
        ];
    }

    public function traveler(): BelongsTo
    {
        return $this->belongsTo(Traveler::class);
    }

    /** approved · disapproved · submitted (waiting on a decision) · draft. */
    public function status(): string
    {
        return match (true) {
            $this->outcome !== null => $this->outcome->value,
            $this->submitted_on !== null => 'submitted',
            default => 'draft',
        };
    }

    /** "JOHN CARLO W. MONOPOLLO". */
    public function fullName(): string
    {
        return trim(preg_replace('/\s+/', ' ', "{$this->first_name} {$this->middle_name} {$this->last_name}"));
    }

    /** "Vacation Leave", or what "Others" was specified as. */
    public function typeLabel(): string
    {
        return $this->leave_type === LeaveType::Others && filled($this->leave_type_other)
            ? $this->leave_type_other
            : $this->leave_type->label();
    }

    /** "2 DAYS", "1 DAY", "0.5 DAY" — 6.C as filled in. */
    public function daysApplied(): string
    {
        $days = $this->working_days;
        $number = rtrim(rtrim(number_format($days, 1, '.', ''), '0'), '.');

        return $number.($days > 1 ? ' DAYS' : ' DAY');
    }

    /**
     * 6.C's inclusive dates: as typed, else worded from the dates —
     * "October 7-8, 2026". leave.js inclusiveDates() words them the same way.
     */
    public function inclusiveDates(): string
    {
        if (filled($this->inclusive_dates)) {
            return $this->inclusive_dates;
        }

        return self::wordDates($this->start_date, $this->end_date);
    }

    public static function wordDates(CarbonInterface $from, CarbonInterface $to): string
    {
        return match (true) {
            $from->isSameDay($to) => $to->format('F j, Y'),
            $from->isSameMonth($to) => $from->format('F j').'-'.$to->format('j, Y'),
            $from->isSameYear($to) => $from->format('F j').' - '.$to->format('F j, Y'),
            default => $from->format('F j, Y').' - '.$to->format('F j, Y'),
        };
    }

    /** A signatory's name or title, falling back to today's configured default. */
    public function signatory(string $key): string
    {
        return $this->signatories[$key] ?? config("onedost.leave.signatories.{$key}", '');
    }
}
