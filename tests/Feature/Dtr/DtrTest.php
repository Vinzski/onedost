<?php

use App\Enums\DtrReason;
use App\Enums\LeaveOutcome;
use App\Models\BookingRequest;
use App\Models\CashAdvance;
use App\Models\DtrDay;
use App\Models\DtrNote;
use App\Models\LeaveApplication;
use App\Models\Liquidation;
use App\Models\Traveler;
use App\Services\Dtr\DtrPdfReader;
use App\Services\Dtr\DtrService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00', 'Asia/Manila'));
});

/**
 * A DTR as the DTR system exports it (dompdf), cut down to what the reader
 * looks at: two copies side by side for days 1–15 and 16–30, each shading
 * the other half's rows dark grey.
 *
 * @param  array<int, list<?string>|string>  $days  Day => its four times (null for blank; "HALF DAY" as the third
 *                                                  covers the afternoon), or a remark across the day. Days left out are blank.
 */
function dtrPdf(array $days, string $name = 'DELA CRUZ, JUAN A.', string $month = 'September-2026'): string
{
    $f = fn (float $n) => number_format($n, 3, '.', '');
    $lines = [];
    foreach ([[0.0, 1, 15], [297.151, 16, 30]] as [$dx, $from, $to]) {
        $at = fn (float $x) => $f($x + $dx);
        $lines[] = "BT {$at(102.813)} 786.607 Td /F4 9.0 Tf  [(DAILY TIME RECORD)] TJ ET";
        $lines[] = "BT {$at(93.596)} 772.601 Td /F2 8.2 Tf  [({$name})] TJ ET";
        $lines[] = "BT {$at(30.0)} 758.760 Td /F2 7.5 Tf  [(For the month of)] TJ ET";
        $lines[] = "BT {$at(156.564)} 758.760 Td /F2 7.5 Tf  [({$month})] TJ ET";
        $lines[] = "BT {$at(34.725)} 716.518 Td /F2 6.8 Tf  [(Days)] TJ ET";
        foreach ([[62.927, 'Arrival'], [94.876, 'Departure'], [137.712, 'Arrival'], [169.660, 'Departure'], [210.720, 'Hours'], [239.772, 'Minutes']] as [$x, $label]) {
            $lines[] = "BT {$at($x)} 703.551 Td /F2 6.8 Tf  [({$label})] TJ ET";
        }

        for ($day = 1; $day <= 30; $day++) {
            $y = 690.584 - ($day - 1) * 12.967;
            $lines[] = 'BT '.$at($day < 10 ? 40.537 : 38.646)." {$f($y)} Td /F2 6.8 Tf  [({$day})] TJ ET";
            $entry = $days[$day] ?? [];
            if ($day < $from || $day > $to) {
                $lines[] = '0.502 0.502 0.502 rg';
                $lines[] = "{$at(54.826)} {$f($y - 3.424)} 35.324 11.992 re f";
            } elseif (is_string($entry)) {
                $lines[] = '0.839 0.839 0.839 rg';
                $lines[] = "{$at(54.826)} {$f($y - 3.424)} 212.327 11.992 re f";
                $lines[] = '0.000 0.000 0.000 rg';
                $lines[] = "BT {$at(86.656)} {$f($y)} Td 6.000 Tc /F2 6.0 Tf  [({$entry})] TJ 0.000 Tc ET";
            } else {
                foreach ([64.044, 101.437, 138.829, 176.221] as $i => $x) {
                    $text = $entry[$i] ?? null;
                    if ($text === 'HALF DAY') {
                        $lines[] = '0.800 0.000 0.000 rg';
                        $lines[] = "BT {$at(148.351)} {$f($y + 0.275)} Td 0.750 Tc /F4 6.0 Tf  [(HALF DAY)] TJ 0.000 Tc ET";
                        break;
                    }
                    if ($text) {
                        $lines[] = "BT {$at($x)} {$f($y)} Td /F2 6.8 Tf  [({$text})] TJ ET";
                    }
                }
            }
            $lines[] = '0.000 0.000 0.000 rg';
        }
    }
    $stream = gzcompress(implode("\n", $lines));

    return "%PDF-1.7\n1 0 obj\n<< /Length ".strlen($stream)." /Filter /FlateDecode >>\nstream\n{$stream}\nendstream\nendobj\ntrailer\n<< >>\n%%EOF\n";
}

/**
 * September 2026 for someone on a Monday–Thursday week: Fridays to Sundays
 * off, every other day logged in full, except as given.
 */
function september(array $overrides = []): array
{
    $days = [];
    for ($day = 1; $day <= 30; $day++) {
        $days[$day] = CarbonImmutable::create(2026, 9, $day)->dayOfWeekIso >= 5
            ? 'NON-WORKING DAY'
            : ['07:10', '12:00', '12:01', '06:05'];
    }

    return array_replace($days, $overrides);
}

/** The month's blanks, as the reader of the example DTR found them. */
function sampleBlanks(): array
{
    return september([
        2 => [],
        3 => [null, null, null, '06:17'],
        7 => [null, '12:06', '12:06', '06:07'],
        14 => [],
        15 => [],
        16 => [],
        28 => ['06:26', '12:11', 'HALF DAY'],
    ]);
}

function importDtr(array $days, string $name = 'DELA CRUZ, JUAN A.')
{
    return test()->post(route('dtr.import'), ['file' => UploadedFile::fake()->createWithContent('September-2026.pdf', dtrPdf($days, $name))]);
}

function dayOf(array $record, int $day): array
{
    return collect($record['days'])->firstWhere('day', $day);
}

it('reads the DTR system\'s PDF: both halves, blanks, remarks and the rows each copy leaves out', function () {
    $path = tempnam(sys_get_temp_dir(), 'dtr');
    file_put_contents($path, dtrPdf(sampleBlanks()));

    $dtr = app(DtrPdfReader::class)->read($path);
    unlink($path);

    expect($dtr['name'])->toBe('DELA CRUZ, JUAN A.')
        ->and($dtr['month']->format('Y-m-d'))->toBe('2026-09-01')
        ->and(array_keys($dtr['days']))->toBe(range(1, 30))
        ->and($dtr['days'][1])->toMatchArray(['am_arrival' => '07:10', 'am_departure' => '12:00', 'pm_arrival' => '12:01', 'pm_departure' => '06:05', 'remark' => null])
        // Day 16 is blank on the second copy, not shaded out of the first.
        ->and($dtr['days'][16])->toMatchArray(['am_arrival' => null, 'pm_departure' => null, 'remark' => null])
        ->and($dtr['days'][3])->toMatchArray(['am_arrival' => null, 'pm_departure' => '06:17'])
        ->and($dtr['days'][4])->toMatchArray(['remark' => 'NON-WORKING DAY', 'remark_slots' => ['am_arrival', 'am_departure', 'pm_arrival', 'pm_departure']])
        ->and($dtr['days'][28])->toMatchArray(['am_arrival' => '06:26', 'am_departure' => '12:11', 'remark' => 'HALF DAY', 'remark_slots' => ['pm_arrival', 'pm_departure']]);
});

it('reads the example DTR from the DTR system', function () {
    $dtr = app(DtrPdfReader::class)->read(base_path('storage/app/imports/files/dtr/September-2026 (3).pdf'));

    expect($dtr['month']->format('F Y'))->toBe('September 2026')
        ->and($dtr['days'])->toHaveCount(30)
        ->and($dtr['days'][3]['pm_departure'])->toBe('06:17')
        ->and($dtr['days'][7]['am_arrival'])->toBeNull()
        ->and($dtr['days'][28]['remark_slots'])->toBe(['pm_arrival', 'pm_departure']);
})->skip(! file_exists(__DIR__.'/../../../storage/app/imports/files/dtr/September-2026 (3).pdf'), 'The example DTR isn\'t in storage/app/imports.');

it('imports a DTR for the person named on it and shows what needs explaining', function () {
    importDtr(sampleBlanks())
        ->assertRedirect(route('dtr.index', ['person' => Traveler::sole()->id, 'month' => '2026-09']))
        ->assertInertiaFlash('message', 'September 2026 DTR imported for JUAN A. DELA CRUZ (days 1–30). 6 days have blanks to explain.');

    expect(Traveler::sole())->name->toBe('JUAN A. DELA CRUZ')->last_name->toBe('DELA CRUZ')->middle_name->toBe('A.')
        ->and(DtrDay::count())->toBe(30);

    $record = app(DtrService::class)->month(Traveler::sole(), CarbonImmutable::parse('2026-09-01'));
    expect(dayOf($record, 1)['status'])->toBe('complete')
        ->and(dayOf($record, 2))->toMatchArray(['status' => 'unexplained', 'blanks' => ['am_arrival', 'am_departure', 'pm_arrival', 'pm_departure']])
        ->and(dayOf($record, 4)['status'])->toBe('off')
        ->and(dayOf($record, 7)['unexplained'])->toBe(['am_arrival'])
        // HALF DAY accounts for the afternoon.
        ->and(dayOf($record, 28)['status'])->toBe('complete')
        ->and($record['summary'])->toMatchArray(['unexplained' => 6, 'explained' => 0, 'imported_from' => '2026-09-01', 'imported_to' => '2026-09-30']);
});

it('replaces the days when the same DTR is imported again', function () {
    importDtr(sampleBlanks());
    importDtr(september())->assertSessionHasNoErrors();

    expect(DtrDay::count())->toBe(30)
        ->and(DtrDay::whereDate('date', '2026-09-02')->sole()->am_arrival)->toBe('07:10')
        ->and(Traveler::count())->toBe(1);
});

it('matches the DTR to a saved person', function () {
    $juan = Traveler::create(['name' => 'JUAN A. DELA CRUZ', 'position' => 'PTA II', 'official_station' => 'DOST- Zamboanga City', 'division' => 'TOS']);

    importDtr(sampleBlanks());

    expect(Traveler::sole()->id)->toBe($juan->id)
        ->and(DtrDay::first()->traveler_id)->toBe($juan->id);
});

it('says why a file isn\'t a DTR', function () {
    $this->post(route('dtr.import'), ['file' => UploadedFile::fake()->createWithContent('scan.pdf', "%PDF-1.4\n%%EOF\n")])
        ->assertSessionHasErrors(['file' => 'This PDF doesn\'t look like a Daily Time Record. Upload the DTR as the DTR system exports it.']);

    $this->post(route('dtr.import'), ['file' => UploadedFile::fake()->create('dtr.xlsx', 10)])
        ->assertSessionHasErrors(['file' => 'Upload the DTR as a PDF.']);
});

it('explains a blank with a note made on the day, before the DTR is imported', function () {
    $juan = Traveler::create(['name' => 'JUAN A. DELA CRUZ', 'position' => 'PTA II', 'official_station' => '', 'division' => '']);

    $this->post(route('dtr.notes.store'), [
        'traveler_id' => $juan->id, 'starts_on' => '2026-09-07', 'slots' => ['am_arrival'],
        'reason' => 'no_internet', 'details' => 'Office internet down until 10 AM', 'actual_time' => '07:05',
    ])->assertSessionHasNoErrors()->assertInertiaFlash('message', 'Noted: No internet connection.');

    importDtr(sampleBlanks());

    $record = app(DtrService::class)->month($juan, CarbonImmutable::parse('2026-09-01'));
    expect(dayOf($record, 7))->toMatchArray(['status' => 'explained', 'unexplained' => []])
        ->and($record['notes'][0])->toMatchArray(['ends_on' => '2026-09-07', 'actual_time' => '07:05', 'slots_label' => 'Time in', 'dates_label' => 'Sep 7'])
        ->and($record['summary']['unexplained'])->toBe(5);
});

it('explains a run of days, or only the times it names', function () {
    importDtr(sampleBlanks());
    $juan = Traveler::sole();
    $note = fn (array $fields) => $this->post(route('dtr.notes.store'), ['traveler_id' => $juan->id, 'slots' => [], ...$fields])->assertSessionHasNoErrors();

    // The whole day, Sept 14 to 16; all four times count as the whole day; an actual time needs one time on one day.
    $note(['starts_on' => '2026-09-14', 'ends_on' => '2026-09-16', 'reason' => 'official_business', 'actual_time' => '07:00']);
    $note(['starts_on' => '2026-09-02', 'slots' => ['am_arrival', 'am_departure', 'pm_arrival', 'pm_departure'], 'reason' => 'holiday']);
    // Only the morning of the 3rd: the rest stays unexplained.
    $note(['starts_on' => '2026-09-03', 'slots' => ['am_arrival', 'am_departure'], 'reason' => 'forgot']);

    expect(DtrNote::where('reason', 'official_business')->sole())->actual_time->toBeNull()->datesLabel()->toBe('Sep 14 – 16')
        ->and(DtrNote::where('reason', 'holiday')->sole()->slots)->toBe([]);

    $record = app(DtrService::class)->month($juan, CarbonImmutable::parse('2026-09-01'));
    expect(collect([2, 14, 15, 16])->map(fn (int $d) => dayOf($record, $d)['status'])->unique()->all())->toBe(['explained'])
        ->and(dayOf($record, 3))->toMatchArray(['status' => 'unexplained', 'unexplained' => ['pm_arrival']])
        ->and($record['summary'])->toMatchArray(['unexplained' => 2, 'explained' => 4, 'first_unexplained' => '2026-09-03']);
});

it('lets a trip or leave already in OneDOST explain its days', function () {
    importDtr(sampleBlanks());
    $juan = Traveler::sole();
    Liquidation::factory()->create(['traveler_id' => $juan->id, 'travel_order_no' => '2026-5667', 'venue' => 'Cebu City',
        'departure_date' => '2026-09-14', 'return_date' => '2026-09-16']);
    // Matched by name too, when the trip wasn't linked to the saved person.
    Liquidation::factory()->create(['traveler_name' => 'juan a. dela cruz', 'departure_date' => '2026-09-02', 'return_date' => '2026-09-02']);
    LeaveApplication::factory()->create(['traveler_id' => $juan->id, 'start_date' => '2026-09-07', 'end_date' => '2026-09-07', 'outcome' => LeaveOutcome::Approved]);
    // Disapproved leave explains nothing.
    LeaveApplication::factory()->create(['traveler_id' => $juan->id, 'start_date' => '2026-09-03', 'end_date' => '2026-09-03', 'outcome' => LeaveOutcome::Disapproved]);

    $record = app(DtrService::class)->month($juan, CarbonImmutable::parse('2026-09-01'));

    expect(dayOf($record, 15)['status'])->toBe('explained')
        ->and(dayOf($record, 15)['auto'][0])->toMatchArray(['kind' => 'travel', 'label' => 'Official travel', 'detail' => 'TO 2026-5667 · Cebu City'])
        ->and(dayOf($record, 2)['status'])->toBe('explained')
        ->and(dayOf($record, 7)['auto'][0])->toMatchArray(['kind' => 'leave', 'label' => 'Vacation Leave', 'detail' => 'approved'])
        ->and(dayOf($record, 3)['status'])->toBe('unexplained')
        ->and($record['summary']['unexplained'])->toBe(1);
});

it('shows a trip once a day, and counts a booked flight', function () {
    importDtr(september([24 => [], 30 => []]));
    $juan = Traveler::sole();
    // The same trip, advanced for and then liquidated under another TO number: the liquidation speaks for it.
    CashAdvance::factory()->create(['traveler_id' => $juan->id, 'travel_order_no' => 'N/A', 'departure_date' => '2026-09-24', 'return_date' => '2026-09-24']);
    Liquidation::factory()->create(['traveler_id' => $juan->id, 'travel_order_no' => '2026-5824', 'venue' => 'Pagadian City',
        'departure_date' => '2026-09-24', 'return_date' => '2026-09-24']);
    $booking = BookingRequest::factory()->create(['travel_order_no' => '2026-6001', 'destination' => 'Manila', 'departure_date' => '2026-09-30', 'return_date' => '2026-10-02']);
    $booking->passengers()->create(['position' => 0, 'traveler_id' => $juan->id, 'first_name' => 'JUAN', 'last_name' => 'DELA CRUZ']);

    $record = app(DtrService::class)->month($juan, CarbonImmutable::parse('2026-09-01'));

    expect(dayOf($record, 24)['auto'])->toHaveCount(1)
        ->and(dayOf($record, 24)['auto'][0]['detail'])->toBe('TO 2026-5824 · Pagadian City')
        ->and(dayOf($record, 30)['auto'][0])->toMatchArray(['detail' => 'TO 2026-6001 · flight to Manila', 'icon' => 'ri-plane-line'])
        ->and($record['summary']['unexplained'])->toBe(0);
});

it('checks a note', function () {
    $juan = Traveler::create(['name' => 'JUAN A. DELA CRUZ', 'position' => '', 'official_station' => '', 'division' => '']);
    $note = fn (array $fields) => $this->post(route('dtr.notes.store'), ['traveler_id' => $juan->id, 'starts_on' => '2026-09-07', 'slots' => [], 'reason' => 'forgot', ...$fields]);

    $note(['reason' => ''])->assertSessionHasErrors(['reason' => 'Pick why.']);
    $note(['reason' => 'other', 'details' => ''])->assertSessionHasErrors(['details' => 'Say what happened.']);
    $note(['ends_on' => '2026-09-01'])->assertSessionHasErrors(['ends_on' => 'The last day can\'t be before the first.']);
    $note(['ends_on' => '2026-12-31'])->assertSessionHasErrors(['ends_on' => 'One note covers two months at most.']);
    $note(['slots' => ['lunch']])->assertSessionHasErrors('slots.0');
    $note(['actual_time' => '7am'])->assertSessionHasErrors('actual_time');

    expect(DtrNote::count())->toBe(0);
});

it('changes and deletes a note', function () {
    $juan = Traveler::create(['name' => 'JUAN A. DELA CRUZ', 'position' => '', 'official_station' => '', 'division' => '']);
    $note = DtrNote::create(['traveler_id' => $juan->id, 'starts_on' => '2026-09-07', 'ends_on' => '2026-09-07', 'slots' => [], 'reason' => DtrReason::Forgot]);

    $this->put(route('dtr.notes.update', $note), ['starts_on' => '2026-09-07', 'slots' => ['pm_departure'], 'reason' => 'system_down', 'actual_time' => '18:02'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('message', 'Note updated.');
    expect($note->refresh())->reason->toBe(DtrReason::SystemDown)->slots->toBe(['pm_departure'])->actual_time->toBe('18:02')->traveler_id->toBe($juan->id);

    $this->delete(route('dtr.notes.destroy', $note))->assertInertiaFlash('message', 'Note deleted.');
    expect(DtrNote::count())->toBe(0);
});

it('opens on the DTR last worked on, and prints its explanations', function () {
    Traveler::create(['name' => 'AARON B. ABAD', 'position' => '', 'official_station' => '', 'division' => '']);
    importDtr(sampleBlanks());
    $juan = Traveler::firstWhere('name', 'JUAN A. DELA CRUZ');

    $this->get(route('dtr.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dtr/Index')
            ->where('person.id', $juan->id)
            ->where('month.key', '2026-11')
            ->where('today', '2026-11-16')
            ->has('people', 2)
            ->has('options.reasons', 10)
            // September still has blanks to explain.
            ->where('elsewhere.0', ['month' => '2026-09', 'label' => 'September 2026', 'days' => 6]));

    $this->get(route('dtr.index', ['person' => $juan->id, 'month' => '2026-09']))
        ->assertInertia(fn (Assert $page) => $page->has('record.days', 30)->where('record.summary.unexplained', 6)->where('elsewhere', []));

    $this->get(route('dtr.print', ['person' => $juan->id, 'month' => '2026-09']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Dtr/Print')->where('inCharge.name', 'RICARDO J. APOLINARIO III')->has('record.days', 30));
});

it('shows the DTR on the dashboard', function () {
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('dtr.month', null));

    importDtr(sampleBlanks());

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('dtr.month', '2026-09')
        ->where('dtr.unexplained', 6)
        ->where('dtr.person', 'JUAN A. DELA CRUZ'));
});
