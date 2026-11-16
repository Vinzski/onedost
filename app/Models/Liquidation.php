<?php

namespace App\Models;

use App\Enums\ClaimKind;
use App\Enums\TravelCompliance;
use App\Models\Concerns\DescribesTrip;
use Carbon\CarbonImmutable;
use Database\Factories\LiquidationFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One traveller's claim for one trip, after travel: everything needed to
 * produce the Itinerary of Travel, Travel Accomplishment Report, Certificate
 * of Travel Completed, CENRR and Activity Report — plus, for a liquidation
 * (settling a cash advance), the Liquidation Report. A reimbursement (`kind`)
 * claims back the traveller's own spending: no cash advance, no Liquidation
 * Report.
 */
class Liquidation extends Model
{
    use DescribesTrip;

    /** @use HasFactory<LiquidationFactory> */
    use HasFactory;

    protected $attributes = [
        'kind' => 'liquidation',
    ];

    protected $fillable = [
        'traveler_id',
        'cash_advance_id',
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
        'cash_advance_amount',
        'dv_no',
        'dv_date',
        'or_no',
        'or_date',
        'supplies_amount',
        'other_expenses_amount',
        'receipt_certificates',
        'persons_contacted',
        'output_summary',
        'follow_up',
        'compliance',
        'compliance_note',
        'evidence',
        'activity_title',
        'activity_body',
        'signatories',
        'submitted_on',
        'has_travel_order',
        'has_certificate_of_appearance',
    ];

    protected function casts(): array
    {
        return [
            'travel_order_date' => 'immutable_date',
            'departure_date' => 'immutable_date',
            'return_date' => 'immutable_date',
            'dv_date' => 'immutable_date',
            'or_date' => 'immutable_date',
            'submitted_on' => 'immutable_date',
            'cash_advance_amount' => 'decimal:2',
            'supplies_amount' => 'decimal:2',
            'other_expenses_amount' => 'decimal:2',
            'persons_contacted' => 'array',
            'evidence' => 'array',
            'signatories' => 'array',
            'receipt_certificates' => 'array',
            'compliance' => TravelCompliance::class,
            'kind' => ClaimKind::class,
            'lunch_provided' => 'boolean',
            'has_travel_order' => 'boolean',
            'has_certificate_of_appearance' => 'boolean',
        ];
    }

    public function isReimbursement(): bool
    {
        return $this->kind === ClaimKind::Reimbursement;
    }

    #[Scope]
    protected function ofKind(Builder $query, ClaimKind $kind): void
    {
        $query->where('kind', $kind->value);
    }

    public function traveler(): BelongsTo
    {
        return $this->belongsTo(Traveler::class);
    }

    /** The cash advance this liquidation settles, when it was started from one. */
    public function cashAdvance(): BelongsTo
    {
        return $this->belongsTo(CashAdvance::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(LiquidationItem::class)->orderBy('position');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(LiquidationPhoto::class)->orderBy('position')->orderBy('id');
    }

    // ── Money ─────────────────────────────────────────────────────────

    public function totalSpent(): float
    {
        return round($this->travelExpenses() + (float) $this->supplies_amount + (float) $this->other_expenses_amount, 2);
    }

    /** Unspent cash advance to return. */
    public function refund(): float
    {
        return max(round((float) $this->cash_advance_amount - $this->totalSpent(), 2), 0.0);
    }

    /** Spending beyond the cash advance, owed to the traveller. */
    public function reimbursement(): float
    {
        return max(round($this->totalSpent() - (float) $this->cash_advance_amount, 2), 0.0);
    }

    /**
     * Certifications of Expenses Not Requiring Receipts: each holds up to
     * three particulars and ₱300 (see config), one certificate per entry.
     *
     * @return list<array{lines: list<array{particulars: string, amount: float}>, total: float}>
     */
    public function receiptCertificates(): array
    {
        return collect($this->receipt_certificates ?? [])
            ->map(function (array $certificate) {
                $lines = collect($certificate['lines'] ?? [])
                    ->filter(fn ($line) => filled($line['particulars'] ?? null))
                    ->map(fn ($line) => ['particulars' => (string) $line['particulars'], 'amount' => (float) ($line['amount'] ?? 0)])
                    ->values();

                return ['lines' => $lines->all(), 'total' => round($lines->sum('amount'), 2)];
            })
            ->filter(fn (array $certificate) => $certificate['lines'] !== [])
            ->values()
            ->all();
    }

    // ── Checklist ─────────────────────────────────────────────────────

    /**
     * Every paper the claim is submitted with. "generated" papers come from
     * this app; "external" ones the traveller obtains and ticks off. A
     * reimbursement has no Liquidation Report. Mirrored for live display in
     * Pages/Liquidation/Edit.vue.
     *
     * @return list<array{key: string, label: string, source: string, status: string}>
     */
    public function checklist(): array
    {
        $paper = fn (string $key, string $label, string $source, bool|string $done) => [
            'key' => $key,
            'label' => $label,
            'source' => $source,
            'status' => is_string($done) ? $done : ($done ? 'done' : 'missing'),
        ];

        return array_values(array_filter([
            $paper('travel_order', 'Travel Order', 'external', (bool) $this->has_travel_order),
            $paper('itinerary', 'Itinerary of Travel', 'generated', $this->items->isNotEmpty()),
            $paper('accomplishment', 'Travel Accomplishment Report', 'generated', filled($this->purpose) && filled($this->venue)),
            $paper('completion', 'Certificate of Travel Completed', 'generated', $this->compliance === TravelCompliance::Strict || filled($this->compliance_note)),
            $paper('cenrr', 'Certification of Expenses Not Requiring Receipts', 'generated', $this->receiptCertificates() === [] ? 'not_needed' : 'done'),
            $this->isReimbursement() ? null : $paper('liquidation', 'Liquidation Report', 'generated', $this->cash_advance_amount !== null),
            $paper('activity', 'Activity Report', 'generated', filled($this->activity_body)),
            $paper('appearance', 'Certificate of Appearance', 'external', (bool) $this->has_certificate_of_appearance),
        ]));
    }

    /** @return list<string> Labels of papers still missing. */
    public function missingPapers(): array
    {
        return collect($this->checklist())->where('status', 'missing')->pluck('label')->values()->all();
    }

    // ── Status ────────────────────────────────────────────────────────

    /**
     * submitted · travelling · overdue · due — a reimbursement has no
     * 30-day deadline (that is the cash advance rule), so it is "pending"
     * instead of due/overdue.
     */
    public function status(): string
    {
        $today = CarbonImmutable::today();

        return match (true) {
            $this->submitted_on !== null => 'submitted',
            $this->return_date->gte($today) => 'travelling',
            $this->isReimbursement() => 'pending',
            $this->deadline()->lt($today) => 'overdue',
            default => 'due',
        };
    }
}
