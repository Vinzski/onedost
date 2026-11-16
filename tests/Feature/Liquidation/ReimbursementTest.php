<?php

use App\Enums\ClaimKind;
use App\Models\CashAdvance;
use App\Models\Liquidation;
use App\Services\Liquidation\LiquidationWorkbook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('local');
});

/** A reimbursement as the form submits it: a 2-day trip, ₱2,550 out of pocket. */
function reimbursementPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'traveler_name' => 'VINCE BRADLEY C. MULOC',
        'position' => 'Project Technical Assistant II',
        'employee_no' => 'VCM-0920',
        'official_station' => 'DOST- Zamboanga City',
        'division' => 'Technical Operations Services',
        'travel_order_no' => '2026-6001',
        'departure_date' => '2026-09-20',
        'return_date' => '2026-09-21',
        'venue' => 'Pagadian City, Zamboanga del Sur',
        'purpose' => 'For on-site deployment to Pagadian City.',
        'fund_cluster' => 'Regular Fund',
        'items' => [
            ['date' => '2026-09-20', 'place' => 'Residence to DOST IX Office', 'transport_means' => 'Public', 'transport_cost' => '150', 'per_diem' => '1500'],
            ['date' => '2026-09-21', 'place' => 'DOST IX Office to Residence', 'transport_means' => 'Public', 'transport_cost' => '150', 'per_diem' => '750'],
        ],
        'compliance' => 'strict',
        'signatories' => config('onedost.liquidation.signatories'),
    ], $overrides);
}

it('saves a reimbursement that claims back the whole amount spent', function () {
    $response = $this->post(route('reimbursements.store'), reimbursementPayload([
        // Sent anyway — a reimbursement never keeps cash advance details.
        'cash_advance_amount' => '5000', 'dv_no' => 'DV-1', 'or_no' => 'OR-1',
        'supplies_amount' => '200',
    ]));

    $claim = Liquidation::sole();
    $response->assertRedirect(route('reimbursements.edit', $claim))->assertInertiaFlash('message', 'Reimbursement saved.');

    expect($claim)
        ->kind->toBe(ClaimKind::Reimbursement)
        ->cash_advance_amount->toBeNull()
        ->dv_no->toBeNull()
        ->or_no->toBeNull()
        ->and($claim->totalSpent())->toBe(2750.0)
        ->and($claim->reimbursement())->toBe(2750.0)
        ->and($claim->refund())->toBe(0.0);
});

it('keeps reimbursements and liquidations in their own lists', function () {
    Liquidation::factory()->withItinerary()->create(['traveler_name' => 'LIQUIDATION ONE']);
    Liquidation::factory()->reimbursement()->withItinerary()->create(['traveler_name' => 'REIMBURSEMENT ONE']);
    Liquidation::factory()->reimbursement()->withItinerary()->create(['traveler_name' => 'REIMBURSEMENT TWO', 'submitted_on' => now()]);

    $this->get(route('reimbursements.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Liquidation/Index')
            ->where('kind', 'reimbursement')
            ->where('routePrefix', 'reimbursements')
            ->has('liquidations.data', 2)
            ->where('summary.pending', 1)
            ->where('summary.submitted', 1)
            ->where('summary.overdue', 0)
            ->where('summary.amount_pending', fn ($amount) => (float) $amount === 3300.0));

    $this->get(route('liquidations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('kind', 'liquidation')
            ->has('liquidations.data', 1)
            ->where('liquidations.data.0.traveler_name', 'LIQUIDATION ONE'));
});

it('only opens each kind under its own address', function () {
    $liquidation = Liquidation::factory()->withItinerary()->create();
    $reimbursement = Liquidation::factory()->reimbursement()->withItinerary()->create();

    $this->get(route('reimbursements.edit', $reimbursement))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('kind', 'reimbursement')->where('liquidation.kind', 'reimbursement'));
    $this->get(route('liquidations.edit', $liquidation))->assertOk();

    $this->get('/liquidations/'.$reimbursement->id.'/edit')->assertNotFound();
    $this->get('/reimbursements/'.$liquidation->id.'/edit')->assertNotFound();
    $this->get('/liquidations/'.$reimbursement->id.'/download/forms')->assertNotFound();
});

it('has no liquidation report among its papers or sheets', function () {
    $claim = Liquidation::factory()->reimbursement()->withItinerary()->create([
        'traveler_name' => 'VINCE BRADLEY C. MULOC', 'travel_order_no' => '2026-6001',
        'receipt_certificates' => [['lines' => [['particulars' => 'Tricycle', 'amount' => 100]]]],
    ]);

    $sheets = array_map(fn ($s) => $s->getTitle(), app(LiquidationWorkbook::class)->build($claim)->getAllSheets());

    expect($sheets)->toBe(['IOT VCM', 'TAR VCM', 'CTC VCM', 'CENRR VCM'])
        ->and(collect($claim->checklist())->pluck('key')->all())->not->toContain('liquidation')
        ->and(collect($claim->checklist())->pluck('key')->all())->toContain('itinerary', 'cenrr', 'activity', 'appearance');

    $this->get(route('reimbursements.download', [$claim, 'forms']))->assertOk()->assertDownload('VCM 2026-6001 - Reimbursement Forms.xlsx');
    $this->get(route('reimbursements.download', [$claim, 'all']))->assertDownload('VCM 2026-6001 - Reimbursement.zip');
});

it('has no 30-day deadline — it is simply submitted or not', function () {
    $claim = Liquidation::factory()->reimbursement()->create(['departure_date' => now()->subDays(92), 'return_date' => now()->subDays(90)]);

    expect($claim->status())->toBe('pending');

    $claim->update(['submitted_on' => now()]);
    expect($claim->refresh()->status())->toBe('submitted');
});

it('never offers or links a cash advance', function () {
    CashAdvance::factory()->withItinerary()->create();

    $this->get(route('reimbursements.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Liquidation/Edit')
            ->where('kind', 'reimbursement')
            ->where('liquidation', null)
            ->has('cashAdvances', 0));

    $this->get(route('liquidations.create'))
        ->assertInertia(fn (Assert $page) => $page->has('cashAdvances', 1));
});

it('adds others on the travel order as reimbursements', function () {
    $claim = Liquidation::factory()->reimbursement()->withItinerary()->create();
    // A liquidation on the same travel order is a different list.
    Liquidation::factory()->withItinerary()->create(['travel_order_no' => $claim->travel_order_no, 'traveler_name' => 'ON THE LIQUIDATION SIDE']);

    $this->from(route('reimbursements.edit', $claim))
        ->post(route('reimbursements.travelers.store', $claim), ['travelers' => ['ON THE LIQUIDATION SIDE']])
        ->assertRedirect(route('reimbursements.edit', $claim));

    expect(Liquidation::latest('id')->first())
        ->kind->toBe(ClaimKind::Reimbursement)
        ->traveler_name->toBe('ON THE LIQUIDATION SIDE');

    $this->get(route('reimbursements.edit', $claim))->assertInertia(fn (Assert $page) => $page->has('team', 2));
});

it('takes activity photos under the reimbursement address', function () {
    $claim = Liquidation::factory()->reimbursement()->create();

    $this->post(route('reimbursements.photos.store', $claim), ['photos' => [UploadedFile::fake()->image('venue.jpg')]])
        ->assertInertiaFlash('status', true);

    $photo = $claim->photos()->sole();
    $this->get(route('reimbursements.photos.show', [$claim, $photo]))->assertOk();
    $this->get(route('reimbursements.edit', $claim))
        ->assertInertia(fn (Assert $page) => $page->where('liquidation.photos.0.url', route('reimbursements.photos.show', [$claim, $photo])));
});

it('shows reimbursements on the dashboard', function () {
    Liquidation::factory()->reimbursement()->withItinerary()->create();

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('reimbursements.pending', 1)
            ->where('liquidations.pending', 0));
});
