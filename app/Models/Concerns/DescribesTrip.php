<?php

namespace App\Models\Concerns;

use Carbon\CarbonImmutable;

/**
 * What a cash advance and a liquidation have in common: a traveller, a trip
 * with an itinerary (`items`), signatories, and the 30-day liquidation window.
 */
trait DescribesTrip
{
    // ── Itinerary money ───────────────────────────────────────────────

    public function transportTotal(): float
    {
        return (float) $this->items->sum('transport_cost');
    }

    public function perDiemTotal(): float
    {
        return (float) $this->items->sum('per_diem');
    }

    public function otherCostTotal(): float
    {
        return (float) $this->items->sum('other_cost');
    }

    /** The itinerary's grand total — "Traveling Expenses" on the forms. */
    public function travelExpenses(): float
    {
        return round($this->transportTotal() + $this->perDiemTotal() + $this->otherCostTotal(), 2);
    }

    /** Last day to liquidate: 30 days after the trip ends (COA Circular 2012-001). */
    public function deadline(): CarbonImmutable
    {
        return $this->return_date->addDays(config('onedost.liquidation.deadline_days'));
    }

    // ── Wording ───────────────────────────────────────────────────────

    /** "20 - 24 September 2026", as on the Itinerary of Travel. */
    public function travelDates(): string
    {
        [$from, $to] = [$this->departure_date, $this->return_date];

        return match (true) {
            $from->isSameDay($to) => $to->format('j F Y'),
            $from->isSameMonth($to) => $from->format('j').' - '.$to->format('j F Y'),
            $from->isSameYear($to) => $from->format('j F').' - '.$to->format('j F Y'),
            default => $from->format('j F Y').' - '.$to->format('j F Y'),
        };
    }

    /** "September 20-24, 2026", as on the Activity Report. */
    public function travelDatesMonthFirst(): string
    {
        [$from, $to] = [$this->departure_date, $this->return_date];

        return match (true) {
            $from->isSameDay($to) => $to->format('F j, Y'),
            $from->isSameMonth($to) => $from->format('F j').'-'.$to->format('j, Y'),
            $from->isSameYear($to) => $from->format('F j').' - '.$to->format('F j, Y'),
            default => $from->format('F j, Y').' - '.$to->format('F j, Y'),
        };
    }

    /** "VCM" for VINCE BRADLEY C. MULOC — first name, middle initial, surname. */
    public function initials(): string
    {
        $words = preg_split('/\s+/', strtoupper(trim((string) $this->traveler_name))) ?: [];
        while (count($words) > 1 && preg_match('/^(JR\.?|SR\.?|I{2,3}|IV|V)$/', end($words))) {
            array_pop($words);
        }
        if ($words === [] || $words === ['']) {
            return '';
        }

        $middle = collect($words)->slice(1, -1)->first(fn (string $w) => preg_match('/^[A-Z]\.$/', $w));

        return $words[0][0].($middle ? $middle[0] : '').(count($words) > 1 ? end($words)[0] : '');
    }

    /** A signatory's name or title, falling back to today's configured default. */
    public function signatory(string $key): string
    {
        return $this->signatories[$key] ?? config("onedost.liquidation.signatories.{$key}", '');
    }
}
