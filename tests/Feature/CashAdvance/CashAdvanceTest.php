<?php

use App\Models\CashAdvance;
use App\Models\Liquidation;
use App\Models\Traveler;
use App\Services\CashAdvance\AmountInWords;
use App\Services\CashAdvance\CashAdvanceDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('local');
});

/** A cash advance as the form submits it: the Pagadian deployment, ₱7,050 planned. */
function cashAdvancePayload(array $overrides = []): array
{
    return array_replace_recursive([
        'traveler_name' => 'VINCE BRADLEY C. MULOC',
        'position' => 'Project Technical Assistant II',
        'employee_no' => 'VCM-0920',
        'official_station' => 'DOST- Zamboanga City',
        'division' => 'Technical Operations Services',
        'travel_order_no' => '2026-5698',
        'travel_order_date' => '2026-09-08',
        'departure_date' => '2026-09-20',
        'return_date' => '2026-09-24',
        'venue' => 'Pagadian City, Zamboanga del Sur',
        'destination_code' => '0907322000',
        'purpose' => 'For on-site deployment of Regional Office personnel to Pagadian City on September 20-24, 2026.',
        'fund_cluster' => 'Regular Fund',
        'has_travel_order' => true,
        'requested_on' => '2026-09-09',
        'items' => [
            ['date' => '2026-09-20', 'place' => 'Residence to DOST IX Office', 'departure_time' => '09:00', 'arrival_time' => '10:00', 'transport_means' => 'Public', 'transport_cost' => '150', 'per_diem' => '1500'],
            ['date' => '2026-09-21', 'place' => 'Still in Pagadian City', 'departure_time' => '07:00', 'arrival_time' => '18:00', 'transport_means' => 'Official Vehicle', 'per_diem' => '1500'],
            ['date' => '2026-09-22', 'place' => 'Still in Pagadian City', 'departure_time' => '07:00', 'arrival_time' => '18:00', 'transport_means' => 'Official Vehicle', 'per_diem' => '1500'],
            ['date' => '2026-09-23', 'place' => 'Still in Pagadian City', 'departure_time' => '07:00', 'arrival_time' => '18:00', 'transport_means' => 'Official Vehicle', 'per_diem' => '1500'],
            ['date' => '2026-09-24', 'place' => 'Pagadian City to DOST IX Office', 'departure_time' => '08:00', 'arrival_time' => '14:00', 'transport_means' => 'Official Vehicle', 'per_diem' => '750'],
            ['date' => '2026-09-24', 'place' => 'DOST IX Office to Residence', 'departure_time' => '14:00', 'arrival_time' => '15:00', 'transport_means' => 'Public', 'transport_cost' => '150'],
        ],
        'signatories' => config('onedost.liquidation.signatories'),
    ], $overrides);
}

it('saves a cash advance for exactly the itinerary total', function () {
    $this->post(route('cash-advances.store'), cashAdvancePayload())
        ->assertRedirect(route('cash-advances.edit', CashAdvance::sole()))
        ->assertInertiaFlash('status', true);

    $cashAdvance = CashAdvance::sole();
    expect($cashAdvance->items)->toHaveCount(6)
        ->and($cashAdvance->requestedAmount())->toBe(7050.0)
        ->and($cashAdvance->traveler->employee_no)->toBe('VCM-0920')
        ->and($cashAdvance->missingPapers())->toBe([]);

    // There is no separate amount: a typed one is ignored, and editing the itinerary moves it.
    $payload = cashAdvancePayload(['amount' => '5000']);
    $payload['items'][0]['per_diem'] = '1000';
    $this->put(route('cash-advances.update', $cashAdvance), $payload);
    expect($cashAdvance->refresh()->requestedAmount())->toBe(6550.0);
});

it('checks the planned itinerary against the trip dates', function () {
    $this->post(route('cash-advances.store'), cashAdvancePayload(['items' => [['date' => '2026-09-30']], 'requested_on' => null]))
        ->assertSessionHasErrors(['items.0.date', 'requested_on']);

    expect(CashAdvance::count())->toBe(0);
});

it('lets itinerary legs leave the date blank to continue the day above', function () {
    $payload = cashAdvancePayload();
    $payload['items'][5]['date'] = null;   // "DOST IX Office to Residence", same day as the leg above
    $payload['items'][2]['date'] = '';

    $this->post(route('cash-advances.store'), $payload)->assertSessionHasNoErrors();

    $cashAdvance = CashAdvance::sole();
    expect($cashAdvance->items[5]->date)->toBeNull()
        ->and($cashAdvance->items[2]->date)->toBeNull()
        ->and($cashAdvance->requestedAmount())->toBe(7050.0);

    $file = app(CashAdvanceDocuments::class)->make($cashAdvance, CashAdvanceDocuments::ITINERARY);
    $iot = IOFactory::load($file['path'])->getSheet(0);
    unlink($file['path']);

    // Legs 2 and 3 (21 Sep + undated) share one date cell, as do legs 5 and 6 (24 Sep + undated).
    expect($iot->getMergeCells())->toContain('B20:B21', 'B23:B24')
        ->and($iot->getCell('B20')->getFormattedValue())->toBe('21-Sep-26')
        ->and($iot->getCell('B23')->getFormattedValue())->toBe('24-Sep-26')
        ->and($iot->getCell('M25')->getCalculatedValue())->toEqual(7050);
});

it('opens the form with defaults and the trip options', function () {
    $this->get(route('cash-advances.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('CashAdvance/Edit')
            ->where('cashAdvance', null)
            ->where('defaults.requested_on', now()->toDateString())
            ->where('defaults.signatories.accountant_name', 'INGRID T. ABELLA-COLCOL')
            ->where('options.vramsUrl', 'https://vrams.dost9.ph/'));
});

it('lists cash advances by how their liquidation stands', function () {
    $overdue = CashAdvance::factory()->withItinerary()->create(['departure_date' => now()->subDays(45), 'return_date' => now()->subDays(43), 'traveler_name' => 'OVERDUE']);
    $liquidated = CashAdvance::factory()->withItinerary()->create(['departure_date' => now()->subDays(20), 'return_date' => now()->subDays(18), 'traveler_name' => 'DONE']);
    Liquidation::factory()->create(['cash_advance_id' => $liquidated->id, 'submitted_on' => now()]);
    CashAdvance::factory()->withItinerary()->create(['traveler_name' => 'UPCOMING']);

    $this->get(route('cash-advances.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('CashAdvance/Index')
            ->has('cashAdvances.data', 3)
            ->where('summary.pending', 2)
            ->where('summary.overdue', 1)
            ->where('summary.liquidated', 1));

    expect($overdue->status())->toBe('overdue')
        ->and($liquidated->refresh()->status())->toBe('liquidated');

    $this->get(route('cash-advances.index', ['status' => 'overdue']))
        ->assertInertia(fn (Assert $page) => $page->has('cashAdvances.data', 1)->where('cashAdvances.data.0.traveler_name', 'OVERDUE'));
});

it('starts the liquidation from the cash advance, copying the trip and itinerary', function () {
    $this->post(route('cash-advances.store'), cashAdvancePayload());
    $cashAdvance = CashAdvance::sole();

    $response = $this->post(route('cash-advances.liquidate', $cashAdvance));

    $liquidation = Liquidation::sole();
    $response->assertRedirect(route('liquidations.edit', $liquidation))->assertInertiaFlash('status', true);

    expect($liquidation)
        ->cash_advance_id->toBe($cashAdvance->id)
        ->traveler_name->toBe('VINCE BRADLEY C. MULOC')
        ->travel_order_no->toBe('2026-5698')
        ->purpose->toBe($cashAdvance->purpose)
        ->destination_code->toBe('0907322000')
        ->fund_cluster->toBe('Regular Fund')
        ->has_travel_order->toBeTrue()
        ->cash_advance_amount->toBe('7050.00')
        ->and($liquidation->departure_date->toDateString())->toBe('2026-09-20')
        ->and($liquidation->items)->toHaveCount(6)
        ->and($liquidation->items[5]->place)->toBe('DOST IX Office to Residence')
        ->and($liquidation->travelExpenses())->toBe(7050.0)
        ->and($liquidation->refund())->toBe(0.0)
        ->and($liquidation->reimbursement())->toBe(0.0);

    $this->get(route('liquidations.edit', $liquidation))
        ->assertInertia(fn (Assert $page) => $page->where('liquidation.cash_advance.id', $cashAdvance->id));

    // Clicking again opens the same liquidation instead of making a second.
    $this->post(route('cash-advances.liquidate', $cashAdvance))->assertRedirect(route('liquidations.edit', $liquidation));
    expect(Liquidation::count())->toBe(1);
});

it('keeps lunch provided at the activity through to the liquidation and the team', function () {
    // Lunch on the three days there takes ₱150 off each; the days going and coming back keep theirs.
    $payload = cashAdvancePayload(['lunch_provided' => true]);
    foreach ([1, 2, 3] as $i) {
        $payload['items'][$i]['per_diem'] = '1350';
    }
    $this->post(route('cash-advances.store'), $payload)->assertSessionHasNoErrors();
    $cashAdvance = CashAdvance::sole();

    expect($cashAdvance->lunch_provided)->toBeTrue()
        ->and($cashAdvance->requestedAmount())->toBe(6600.0);

    $this->get(route('cash-advances.edit', $cashAdvance))
        ->assertInertia(fn (Assert $page) => $page
            ->where('cashAdvance.lunch_provided', true)
            ->where('options.lunchDeduction', 150));

    $this->post(route('cash-advances.liquidate', $cashAdvance));
    $this->post(route('cash-advances.travelers.store', $cashAdvance), ['travelers' => ['EVA MAE LARGO']]);

    expect(Liquidation::sole()->lunch_provided)->toBeTrue()
        ->and(Liquidation::sole()->travelExpenses())->toBe(6600.0)
        ->and(CashAdvance::where('traveler_name', 'EVA MAE LARGO')->sole()->lunch_provided)->toBeTrue();

    // Unticked, it stays off.
    $this->put(route('cash-advances.update', $cashAdvance), cashAdvancePayload(['lunch_provided' => false]));
    expect($cashAdvance->refresh()->lunch_provided)->toBeFalse();
});

it('adds others to the travel order, each with their own details and fares', function () {
    $this->post(route('cash-advances.store'), cashAdvancePayload());
    $source = CashAdvance::sole();
    $this->post(route('cash-advances.liquidate', $source));
    $eva = Traveler::create([
        'name' => 'EVA MAE LARGO', 'position' => 'PTA III', 'employee_no' => 'EML-1', 'official_station' => 'DOST- Zamboanga City',
        'division' => 'Technical Operations Services', 'home_area' => 'zamboanga', 'office_distance' => '8–15 km', 'terminal_distance' => '1–7 km',
    ]);

    // Already on the travel order, or typed twice: added once.
    $this->post(route('cash-advances.travelers.store', $source), ['travelers' => ['Eva Mae Largo', 'NEW PERSON', 'new person', 'VINCE BRADLEY C. MULOC']])
        ->assertRedirect()
        ->assertInertiaFlash('message', 'Added 2 travellers to TO 2026-5698. Open NEW PERSON to fill in position and fares.')
        ->assertInertiaFlash('info', 'warning');

    $copy = CashAdvance::where('traveler_name', 'EVA MAE LARGO')->sole();
    expect($copy)
        ->traveler_id->toBe($eva->id)
        ->position->toBe('PTA III')
        ->employee_no->toBe('EML-1')
        ->travel_order_no->toBe('2026-5698')
        ->purpose->toBe($source->purpose)
        ->destination_code->toBe('0907322000')
        ->and($copy->items)->toHaveCount(6)
        // Her own fare (8–15 km) on both residence legs; everything else as planned.
        ->and((float) $copy->items[0]->transport_cost)->toBe(250.0)
        ->and((float) $copy->items[5]->transport_cost)->toBe(250.0)
        ->and($copy->requestedAmount())->toBe(7250.0)
        // Her own liquidation is still to come; the original's stays with the original.
        ->and($copy->liquidation)->toBeNull()
        ->and($source->refresh()->liquidation)->not->toBeNull();

    $unsaved = CashAdvance::where('traveler_name', 'NEW PERSON')->sole();
    expect($unsaved)
        ->position->toBe('')
        ->official_station->toBe('DOST- Zamboanga City')
        ->and($unsaved->requestedAmount())->toBe(7050.0)
        ->and(CashAdvance::count())->toBe(3);

    $this->get(route('cash-advances.edit', $source))
        ->assertInertia(fn (Assert $page) => $page
            ->has('team', 3)
            ->where('team.0.traveler_name', 'EVA MAE LARGO')
            ->where('team.0.amount', fn ($amount) => (float) $amount === 7250.0));
});

it('downloads everyone\'s itinerary on the travel order in one workbook', function () {
    $this->post(route('cash-advances.store'), cashAdvancePayload());
    $source = CashAdvance::sole();
    Traveler::create(['name' => 'EVA MAE LARGO', 'position' => 'PTA III', 'official_station' => 'DOST- Zamboanga City', 'division' => 'TOS', 'home_area' => 'zamboanga', 'office_distance' => '8–15 km']);
    $this->post(route('cash-advances.travelers.store', $source), ['travelers' => ['EVA MAE LARGO', 'KARL AUSTHYNE D. BANUA']]);
    CashAdvance::factory()->withItinerary()->create(['travel_order_no' => '2026-9999', 'traveler_name' => 'SOMEONE ELSE']);

    $response = $this->get(route('cash-advances.download', [$source, 'team-itineraries']))
        ->assertOk()
        ->assertDownload('TO 2026-5698 - Itineraries of Travel.xlsx');

    $book = IOFactory::load($response->getFile()->getPathname());
    $name = fn (string $sheet) => $book->getSheetByName($sheet)->getCell('D13')->getValue();

    expect($book->getSheetNames())->toBe(['IOT EL', 'IOT KDB', 'IOT VCM'])
        ->and($name('IOT EL'))->toBe('EVA MAE LARGO')
        ->and($name('IOT VCM'))->toBe('VINCE BRADLEY C. MULOC')
        ->and($book->getSheetByName('IOT EL')->getCell('J19')->getValue())->toEqual(250)
        ->and($book->getSheetByName('IOT EL')->getCell('M25')->getCalculatedValue())->toEqual(7250)
        ->and($book->getSheetByName('IOT VCM')->getCell('M25')->getCalculatedValue())->toEqual(7050);
});

it('offers cash advances without a liquidation when starting a new liquidation', function () {
    $free = CashAdvance::factory()->withItinerary()->create(['traveler_name' => 'FREE']);
    $taken = CashAdvance::factory()->withItinerary()->create();
    Liquidation::factory()->create(['cash_advance_id' => $taken->id]);

    $this->get(route('liquidations.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('cashAdvances', 1)
            ->where('cashAdvances.0.id', $free->id)
            ->where('cashAdvances.0.amount', fn ($amount) => (float) $amount === 3300.0));
});

it('does not carry the cash advance over when copying a liquidation for another traveller', function () {
    $cashAdvance = CashAdvance::factory()->withItinerary()->create();
    $liquidation = Liquidation::factory()->withItinerary()->create(['cash_advance_id' => $cashAdvance->id]);

    $this->post(route('liquidations.travelers.store', $liquidation), ['travelers' => ['SOMEONE ELSE']])->assertRedirect();

    expect(Liquidation::latest('id')->first())
        ->traveler_name->toBe('SOMEONE ELSE')
        ->cash_advance_id->toBeNull();
});

it('keeps the liquidation when its cash advance is deleted', function () {
    $cashAdvance = CashAdvance::factory()->withItinerary()->create();
    $liquidation = Liquidation::factory()->create(['cash_advance_id' => $cashAdvance->id]);

    $this->delete(route('cash-advances.destroy', $cashAdvance))->assertRedirect(route('cash-advances.index'));

    $this->assertModelMissing($cashAdvance);
    expect($liquidation->refresh()->cash_advance_id)->toBeNull();
});

it('generates the itinerary of travel on its own', function () {
    $this->post(route('cash-advances.store'), cashAdvancePayload());
    $file = app(CashAdvanceDocuments::class)->make(CashAdvance::sole(), CashAdvanceDocuments::ITINERARY);

    $book = IOFactory::load($file['path']);
    $iot = $book->getSheet(0);
    $text = fn (string $cell) => ($v = $iot->getCell($cell)->getValue()) instanceof RichText ? $v->getPlainText() : (string) $v;
    unlink($file['path']);

    expect($file['name'])->toBe('VCM 2026-5698 - Itinerary of Travel.xlsx')
        ->and($book->getSheetNames())->toBe(['IOT VCM'])
        ->and($text('D13'))->toBe('VINCE BRADLEY C. MULOC')
        ->and($text('K10'))->toBe('DOST TO No.: 2026-5698')
        ->and($text('I13'))->toBe('Date of Travel :  20 - 24 September 2026')
        ->and($text('B25'))->toBe('TOTAL')
        ->and($iot->getCell('M25')->getCalculatedValue())->toEqual(7050);
});

it('fills the no pending cash advance certification on the letterhead', function () {
    $this->post(route('cash-advances.store'), cashAdvancePayload(['traveler_name' => 'EVA MAE LARGO', 'requested_on' => '2026-08-03', 'departure_date' => '2026-08-10', 'return_date' => '2026-08-14', 'items' => [['date' => '2026-08-10'], ['date' => '2026-08-11'], ['date' => '2026-08-12'], ['date' => '2026-08-13'], ['date' => '2026-08-14'], ['date' => '2026-08-14']]]));
    $file = app(CashAdvanceDocuments::class)->make(CashAdvance::sole(), CashAdvanceDocuments::NO_PENDING);

    $zip = new ZipArchive;
    $zip->open($file['path']);
    $xml = $zip->getFromName('word/document.xml');
    $hasLetterhead = $zip->locateName('word/header2.xml') !== false;
    $zip->close();
    unlink($file['path']);

    $plain = strip_tags($xml);
    expect($plain)->toContain('This is to certify that EVA MAE LARGO has no pending cash advance as of August 03, 2026')
        ->toContain('the cash advance requested dated August 03, 2026 in the amount of SEVEN THOUSAND FIFTY PESOS ONLY (P7,050.00)')
        ->toContain('INGRID T. ABELLA-COLCOL')
        ->not->toContain('${')
        ->and(substr_count($plain, 'EVA MAE LARGO'))->toBe(2)
        ->and($hasLetterhead)->toBeTrue()
        ->and(AmountInWords::pesos(7050.5))->toBe('SEVEN THOUSAND FIFTY PESOS AND FIFTY CENTAVOS ONLY');
});

it('downloads each paper, or both as a zip', function () {
    $this->post(route('cash-advances.store'), cashAdvancePayload());
    $cashAdvance = CashAdvance::sole();

    $this->get(route('cash-advances.download', [$cashAdvance, 'itinerary']))->assertOk()->assertDownload('VCM 2026-5698 - Itinerary of Travel.xlsx');
    $this->get(route('cash-advances.download', [$cashAdvance, 'no-pending']))->assertDownload('VCM 2026-5698 - No Pending Cash Advance.docx');

    $response = $this->get(route('cash-advances.download', [$cashAdvance, 'all']))->assertDownload('VCM 2026-5698 - Cash Advance.zip');
    $zip = new ZipArchive;
    $zip->open($response->getFile()->getPathname());
    $names = array_map(fn ($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
    $zip->close();

    expect($names)->toBe(['VCM 2026-5698 - Itinerary of Travel.xlsx', 'VCM 2026-5698 - No Pending Cash Advance.docx']);
    $this->get('/cash-advances/'.$cashAdvance->id.'/download/liquidation')->assertNotFound();
});
