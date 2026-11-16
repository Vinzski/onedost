<?php

namespace App\Services\Dtr;

use App\Enums\DtrSlot;
use App\Enums\LeaveOutcome;
use App\Models\BookingRequest;
use App\Models\CashAdvance;
use App\Models\DtrDay;
use App\Models\DtrNote;
use App\Models\LeaveApplication;
use App\Models\Liquidation;
use App\Models\Traveler;
use App\Traits\HandlesTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Someone's Daily Time Record, month by month: the times imported from the
 * DTR system, the blanks among them, and what explains each blank — their
 * own notes, or a trip or leave already in OneDOST.
 */
class DtrService
{
    use HandlesTransaction;

    public function __construct(private DtrPdfReader $reader) {}

    /**
     * Today's date in Manila, whatever the server's clock says — at midnight
     * in the app's timezone, like every other date here, so they compare.
     */
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now(config('onedost.timezone'))->toDateString());
    }

    /** @return Collection<int, array{id: int, name: string}> */
    public function people(): Collection
    {
        return Traveler::orderBy('name')->get(['id', 'name']);
    }

    /** Whoever's DTR was last imported or noted: on a shared portal, most likely the one opening it. */
    public function defaultPerson(): ?Traveler
    {
        $recent = collect([
            DtrDay::query()->latest('updated_at')->first(['traveler_id', 'updated_at']),
            DtrNote::query()->latest('updated_at')->first(['traveler_id', 'updated_at']),
        ])->filter()->sortByDesc('updated_at')->first();

        return $recent ? Traveler::find($recent->traveler_id) : null;
    }

    /**
     * A month of someone's DTR, a row a day, with what's blank and what explains it.
     *
     * @return array{days: list<array<string, mixed>>, notes: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function month(Traveler $person, CarbonImmutable $month): array
    {
        [$start, $end] = [$month->startOfMonth(), $month->endOfMonth()->startOfDay()];
        $today = $this->today();

        $imported = DtrDay::query()->where('traveler_id', $person->id)->tap(fn (Builder $q) => $this->within($q, 'date', $start, $end))
            ->get()->keyBy(fn (DtrDay $day) => $day->date->toDateString());
        $notes = DtrNote::query()->where('traveler_id', $person->id)
            ->whereDate('starts_on', '<=', $end)->whereDate('ends_on', '>=', $start)
            ->orderBy('starts_on')->orderBy('id')->get();
        $away = $this->away($person, $start, $end);

        $days = [];
        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $key = $date->toDateString();
            $record = $imported->get($key);
            $dayNotes = $notes->filter(fn (DtrNote $n) => $date->between($n->starts_on, $n->ends_on));
            // One trip a day (the best evidence of it), and any leave.
            $auto = $away->filter(fn (array $a) => $date->between($a['from'], $a['to']))
                ->map(fn (array $a) => $a['explanation'])->unique('kind')->values();
            $counts = $record && $date->lte($today);

            $blanks = $counts ? $record->blankSlots() : [];
            $unexplained = $auto->isNotEmpty() ? [] : array_values(array_filter(
                $blanks,
                fn (DtrSlot $slot) => ! $dayNotes->contains(fn (DtrNote $n) => $n->covers($date, $slot)),
            ));

            $days[] = [
                'date' => $key,
                'day' => $date->day,
                'weekday' => $date->format('D'),
                'is_today' => $date->equalTo($today),
                'imported' => $record !== null,
                'times' => $record ? $record->only(DtrSlot::values()) : array_fill_keys(DtrSlot::values(), null),
                'remark' => $record?->remark,
                'remark_slots' => $record?->remark_slots ?? [],
                'undertime' => $record ? $this->undertime($record) : null,
                'blanks' => array_map(fn (DtrSlot $s) => $s->value, $blanks),
                'unexplained' => array_map(fn (DtrSlot $s) => $s->value, $unexplained),
                'notes' => $dayNotes->pluck('id')->values(),
                'auto' => $auto,
                'status' => match (true) {
                    $record === null => $date->gt($today) ? 'upcoming' : 'not_imported',
                    $record->isOff() => 'off',
                    ! $counts => 'upcoming',
                    $blanks === [] => 'complete',
                    $unexplained === [] => 'explained',
                    default => 'unexplained',
                },
            ];
        }

        $rows = collect($days);
        $counted = $imported->keys();

        return [
            'days' => $days,
            'notes' => $notes->map(fn (DtrNote $note) => $this->noteData($note))->values()->all(),
            'summary' => [
                'imported_from' => $counted->min(),
                'imported_to' => $counted->max(),
                'imported_at' => $imported->max('updated_at')?->toIso8601String(),
                'workdays' => $rows->whereIn('status', ['complete', 'explained', 'unexplained'])->count(),
                'complete' => $rows->where('status', 'complete')->count(),
                'explained' => $rows->where('status', 'explained')->count(),
                'unexplained' => $rows->where('status', 'unexplained')->count(),
                'unexplained_slots' => $rows->sum(fn (array $d) => count($d['unexplained'])),
                'first_unexplained' => $rows->firstWhere('status', 'unexplained')['date'] ?? null,
            ],
        ];
    }

    /**
     * Other imported months with blanks still to explain, newest first, for the "needs you" list.
     *
     * @return list<array{month: string, label: string, days: int}>
     */
    public function monthsToExplain(Traveler $person, ?CarbonImmutable $except = null, int $limit = 6): array
    {
        return DtrDay::query()->where('traveler_id', $person->id)->latest('date')->pluck('date')
            ->map(fn ($date) => CarbonImmutable::parse($date)->startOfMonth())
            ->unique(fn (CarbonImmutable $m) => $m->format('Y-m'))
            ->reject(fn (CarbonImmutable $m) => $except && $m->isSameMonth($except))
            ->take($limit)
            ->map(fn (CarbonImmutable $m) => ['month' => $m->format('Y-m'), 'label' => $m->format('F Y'), 'days' => $this->month($person, $m)['summary']['unexplained']])
            ->filter(fn (array $m) => $m['days'] > 0)
            ->values()
            ->all();
    }

    /** The Dashboard's figures: the latest imported month of whoever uses the DTR here. */
    public function summary(): array
    {
        $person = $this->defaultPerson();
        $latest = $person ? DtrDay::query()->where('traveler_id', $person->id)->max('date') : null;
        if (! $latest) {
            return ['person' => $person?->name, 'month' => null, 'month_label' => null, 'unexplained' => 0, 'explained' => 0];
        }

        $month = CarbonImmutable::parse($latest)->startOfMonth();
        $summary = $this->month($person, $month)['summary'];

        return [
            'person' => $person->name,
            'person_id' => $person->id,
            'month' => $month->format('Y-m'),
            'month_label' => $month->format('F Y'),
            'unexplained' => $summary['unexplained'],
            'explained' => $summary['explained'],
        ];
    }

    /**
     * Read an uploaded DTR and save its days for the person named on it (a
     * new saved person if need be). Importing again replaces those days.
     */
    public function import(UploadedFile $file): array
    {
        try {
            $dtr = $this->reader->read($file->getRealPath());
        } catch (DtrReadException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }
        if ($dtr['days'] === []) {
            throw ValidationException::withMessages(['file' => 'The DTR has no days in it to import.']);
        }

        return self::handleTransaction(function () use ($dtr) {
            $person = $this->person($dtr['name']);
            $month = $dtr['month'];
            $existing = DtrDay::query()->where('traveler_id', $person->id)
                ->tap(fn (Builder $q) => $this->within($q, 'date', $month->startOfMonth(), $month->endOfMonth()))
                ->get()->keyBy(fn (DtrDay $day) => $day->date->toDateString());

            foreach ($dtr['days'] as $day => $entry) {
                $date = $month->setDay($day);
                ($existing->get($date->toDateString()) ?? new DtrDay(['traveler_id' => $person->id, 'date' => $date]))
                    ->fill($entry)->touch();
            }

            $summary = $this->month($person, $month)['summary'];
            $days = array_keys($dtr['days']);
            $toExplain = $summary['unexplained']
                ? "{$summary['unexplained']} day".($summary['unexplained'] === 1 ? ' has' : 's have').' blanks to explain.'
                : 'Every blank is explained.';

            return [
                'data' => ['person' => $person, 'month' => $month],
                'message' => "{$month->format('F Y')} DTR imported for {$person->name} (days ".min($days).'–'.max($days).'). '.$toExplain,
                'info' => null,
                'status' => true,
            ];
        });
    }

    public function createNote(array $data): array
    {
        return self::handleTransaction(function () use ($data) {
            $note = DtrNote::create($this->noteFields($data));

            return ['data' => $note, 'message' => 'Noted: '.$note->reason->label().'.', 'info' => null, 'status' => true];
        });
    }

    public function updateNote(DtrNote $note, array $data): array
    {
        return self::handleTransaction(function () use ($note, $data) {
            $note->update($this->noteFields([...$data, 'traveler_id' => $note->traveler_id]));

            return ['data' => $note, 'message' => 'Note updated.', 'info' => null, 'status' => true];
        });
    }

    public function deleteNote(DtrNote $note): array
    {
        return self::handleTransaction(function () use ($note) {
            $note->delete();

            return ['data' => null, 'message' => 'Note deleted.', 'info' => null, 'status' => true];
        });
    }

    public function noteData(DtrNote $note): array
    {
        return [
            'id' => $note->id,
            'starts_on' => $note->starts_on->toDateString(),
            'ends_on' => $note->ends_on->toDateString(),
            'slots' => $note->slots,
            'reason' => $note->reason->value,
            'details' => $note->details,
            'actual_time' => $note->actual_time,
            'dates_label' => $note->datesLabel(),
            'slots_label' => $note->slotsLabel(),
            'form_slots_label' => $note->slotsLabel(form: true),
        ];
    }

    /**
     * One day or a run of days; all four times are the whole day; the actual
     * time only means something for one time on one day.
     */
    private function noteFields(array $data): array
    {
        $slots = array_values(array_intersect(DtrSlot::values(), $data['slots'] ?? []));
        if (count($slots) === count(DtrSlot::cases())) {
            $slots = [];
        }
        $starts = $data['starts_on'];
        $ends = filled($data['ends_on'] ?? null) ? $data['ends_on'] : $starts;
        $single = $starts === $ends && count($slots) === 1;

        return [
            'traveler_id' => $data['traveler_id'],
            'starts_on' => $starts,
            'ends_on' => $ends,
            'slots' => $slots,
            'reason' => $data['reason'],
            'details' => filled($data['details'] ?? null) ? trim($data['details']) : null,
            'actual_time' => $single && filled($data['actual_time'] ?? null) ? $data['actual_time'] : null,
        ];
    }

    /**
     * Trips and leave already in OneDOST over these dates: each explains
     * the blanks of every day it spans.
     *
     * @return Collection<int, array{from: CarbonImmutable, to: CarbonImmutable, explanation: array<string, string>}>
     */
    private function away(Traveler $person, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $theirs = fn (Builder $q) => $q->where(fn (Builder $q) => $q
            ->where('traveler_id', $person->id)
            ->orWhereRaw('lower(traveler_name) = ?', [mb_strtolower($person->name)]));
        $overlapping = fn (Builder $q, string $from, string $to) => $q->whereDate($from, '<=', $end)->whereDate($to, '>=', $start);

        // Best evidence first — the trip as liquidated, as advanced for, as
        // booked — since month() shows one trip a day.
        $trips = collect()
            ->concat(Liquidation::query()->tap($theirs)->tap(fn (Builder $q) => $overlapping($q, 'departure_date', 'return_date'))->get()
                ->map(fn (Liquidation $trip) => $this->trip($trip, route($trip->kind->routePrefix().'.edit', $trip))))
            ->concat(CashAdvance::query()->tap($theirs)->tap(fn (Builder $q) => $overlapping($q, 'departure_date', 'return_date'))->get()
                ->map(fn (CashAdvance $trip) => $this->trip($trip, route('cash-advances.edit', $trip))))
            ->concat(BookingRequest::query()
                ->whereHas('passengers', fn (Builder $q) => $q->where('traveler_id', $person->id))
                ->whereDate('departure_date', '<=', $end)
                ->where(fn (Builder $q) => $q->whereDate('return_date', '>=', $start)
                    ->orWhere(fn (Builder $q) => $q->whereNull('return_date')->whereDate('departure_date', '>=', $start)))
                ->get()
                ->map(fn (BookingRequest $booking) => [
                    'from' => CarbonImmutable::parse($booking->departure_date),
                    'to' => CarbonImmutable::parse($booking->return_date ?? $booking->departure_date),
                    'explanation' => [
                        'kind' => 'travel',
                        'icon' => 'ri-plane-line',
                        'label' => 'Official travel',
                        'detail' => "TO {$booking->travel_order_no} · flight to {$booking->destination}",
                        'url' => route('booking-requests.edit', $booking),
                    ],
                ]));

        $leave = LeaveApplication::query()->where('traveler_id', $person->id)
            ->where(fn (Builder $q) => $q->whereNull('outcome')->orWhere('outcome', LeaveOutcome::Approved->value))
            ->tap(fn (Builder $q) => $overlapping($q, 'start_date', 'end_date'))
            ->get()
            ->toBase()
            ->map(fn (LeaveApplication $leave) => [
                'from' => CarbonImmutable::parse($leave->start_date),
                'to' => CarbonImmutable::parse($leave->end_date),
                'explanation' => [
                    'kind' => 'leave',
                    'icon' => 'ri-calendar-check-line',
                    'label' => $leave->typeLabel(),
                    'detail' => match ($leave->status()) {
                        'approved' => 'approved',
                        'submitted' => 'filed, awaiting approval',
                        default => 'application not yet filed',
                    },
                    'url' => route('leave-applications.edit', $leave),
                ],
            ]);

        return $trips->concat($leave);
    }

    private function trip(CashAdvance|Liquidation $trip, string $url): array
    {
        return [
            'from' => CarbonImmutable::parse($trip->departure_date),
            'to' => CarbonImmutable::parse($trip->return_date),
            'explanation' => [
                'kind' => 'travel',
                'icon' => 'ri-suitcase-line',
                'label' => 'Official travel',
                'detail' => "TO {$trip->travel_order_no} · {$trip->venue}",
                'url' => $url,
            ],
        ];
    }

    /** "1h 5m" of undertime, or null. */
    private function undertime(DtrDay $day): ?string
    {
        $parts = array_filter([
            $day->undertime_hours ? "{$day->undertime_hours}h" : null,
            $day->undertime_minutes ? "{$day->undertime_minutes}m" : null,
        ]);

        return $parts ? implode(' ', $parts) : null;
    }

    /** The saved person a DTR names ("MULOC, VINCE BRADLEY C."), or a new one. */
    private function person(string $printed): Traveler
    {
        [$last, $rest] = array_pad(array_map('trim', explode(',', $printed, 2)), 2, '');
        $words = preg_split('/\s+/', $rest, -1, PREG_SPLIT_NO_EMPTY);
        $middle = $words && preg_match('/^[A-Z]\.$/i', end($words)) ? array_pop($words) : null;
        $first = implode(' ', $words);

        return Traveler::findPerson(null, $first, $last, $middle) ?? Traveler::create([
            'name' => mb_strtoupper(trim(preg_replace('/\s+/', ' ', "{$first} {$middle} {$last}"))),
            'first_name' => $first ?: null,
            'middle_name' => $middle,
            'last_name' => $last,
            'position' => '',
            'official_station' => '',
            'division' => '',
        ]);
    }

    /** Dates stored with or without a time (SQLite keeps one), compared as dates. */
    private function within(Builder $query, string $column, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $query->whereDate($column, '>=', $from)->whereDate($column, '<=', $to);
    }
}
