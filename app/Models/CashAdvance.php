<?php

namespace App\Models;

use App\Models\Concerns\DescribesTrip;
use Carbon\CarbonImmutable;
use Database\Factories\CashAdvanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A cash advance requested before travel: Travel Order (from VRAMS), the
 * planned Itinerary of Travel and the no-pending cash advance certification.
 */
class CashAdvance extends Model
{
    use DescribesTrip;

    /** @use HasFactory<CashAdvanceFactory> */
    use HasFactory;

    protected $fillable = [
        'traveler_id',
        'traveler_name',
        'position',
        'employee_no',
        'official_station',
        'division',
        'travel_order_no',
        'travel_order_date',
        'departure_date',
        'return_date',
        'venue',
        'destination_code',
        'lunch_provided',
        'purpose',
        'fund_cluster',
        'requested_on',
        'signatories',
        'has_travel_order',
    ];

    protected function casts(): array
    {
        return [
            'travel_order_date' => 'immutable_date',
            'departure_date' => 'immutable_date',
            'return_date' => 'immutable_date',
            'requested_on' => 'immutable_date',
            'signatories' => 'array',
            'lunch_provided' => 'boolean',
            'has_travel_order' => 'boolean',
        ];
    }

    public function traveler(): BelongsTo
    {
        return $this->belongsTo(Traveler::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CashAdvanceItem::class)->orderBy('position');
    }

    /** The liquidation that settles this cash advance, once started. */
    public function liquidation(): HasOne
    {
        return $this->hasOne(Liquidation::class);
    }

    /** What is being advanced: always the Itinerary of Travel's total. */
    public function requestedAmount(): float
    {
        return $this->travelExpenses();
    }

    /** liquidated · liquidating · upcoming · overdue · due */
    public function status(): string
    {
        $today = CarbonImmutable::today();
        $liquidation = $this->liquidation;

        return match (true) {
            $liquidation?->submitted_on !== null => 'liquidated',
            $liquidation !== null && $this->deadline()->gte($today) => 'liquidating',
            $this->return_date->gte($today) => 'upcoming',
            $this->deadline()->lt($today) => 'overdue',
            default => 'due',
        };
    }

    /**
     * The papers a cash advance request goes in with.
     *
     * @return list<array{key: string, label: string, source: string, status: string}>
     */
    public function checklist(): array
    {
        return [
            ['key' => 'travel_order', 'label' => 'Travel Order', 'source' => 'external', 'status' => $this->has_travel_order ? 'done' : 'missing'],
            ['key' => 'itinerary', 'label' => 'Itinerary of Travel', 'source' => 'generated', 'status' => $this->items->isNotEmpty() ? 'done' : 'missing'],
            ['key' => 'no_pending', 'label' => 'No Pending Cash Advance certification', 'source' => 'generated', 'status' => $this->requestedAmount() > 0 ? 'done' : 'missing'],
        ];
    }

    /** @return list<string> */
    public function missingPapers(): array
    {
        return collect($this->checklist())->where('status', 'missing')->pluck('label')->values()->all();
    }
}
