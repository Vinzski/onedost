<?php

use App\Models\Liquidation;
use App\Models\LiquidationPhoto;
use App\Models\Traveler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('local');
});

/** A complete liquidation as the form submits it: a 2-day trip, ₱150 fares each way. */
function liquidationPayload(array $overrides = []): array
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
        'return_date' => '2026-09-21',
        'venue' => 'Pagadian City, Zamboanga del Sur',
        'purpose' => 'For on-site deployment to Pagadian City.',
        'fund_cluster' => 'Regular Fund',
        'items' => [
            ['date' => '2026-09-20', 'place' => 'Residence to DOST IX Office', 'departure_time' => '09:00', 'arrival_time' => '10:00', 'transport_means' => 'Public', 'transport_cost' => '150', 'per_diem' => '1500', 'other_cost' => null],
            ['date' => '2026-09-21', 'place' => 'DOST IX Office to Residence', 'departure_time' => '14:00', 'arrival_time' => null, 'transport_means' => 'Public', 'transport_cost' => '150', 'per_diem' => '750', 'other_cost' => null],
        ],
        'persons_contacted' => [['name' => 'CHRISTIAN CARL R. RESENTE', 'designation' => 'OIC-PSTD, DOST-ZDS']],
        'cash_advance_amount' => '2550',
        'compliance' => 'strict',
        'evidence' => ['Certificate of Appearance', 'Travel Accomplishment Report'],
        'signatories' => config('onedost.liquidation.signatories'),
    ], $overrides);
}

it('lists liquidations with their deadlines and the summary', function () {
    Liquidation::factory()->create(['return_date' => now()->subDays(40), 'departure_date' => now()->subDays(42)]);
    Liquidation::factory()->create(['submitted_on' => now()->subDay()]);

    $this->get(route('liquidations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Liquidation/Index')
            ->has('liquidations.data', 2)
            ->where('summary.pending', 1)
            ->where('summary.overdue', 1)
            ->where('summary.submitted', 1)
            ->where('liquidations.data.0.status', 'overdue'));

    $this->get(route('liquidations.index', ['status' => 'overdue']))
        ->assertInertia(fn (Assert $page) => $page->has('liquidations.data', 1));
});

it('starts a new liquidation from the defaults and saved travellers', function () {
    Traveler::create(['name' => 'EVA MAE LARGO', 'position' => 'Science Research Specialist I', 'official_station' => 'DOST- Zamboanga City', 'division' => 'Technical Operations Services']);

    $this->get(route('liquidations.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Liquidation/Edit')
            ->where('liquidation', null)
            ->where('defaults.signatories.director_name', 'ROSEMARIE S. SALAZAR')
            ->where('travelers.0.name', 'EVA MAE LARGO')
            ->where('options.perDiemRate', 1500)
            ->where('defaults.fund_cluster', 'Regular Fund')
            ->where('options.fundClusters', ['Regular Fund', 'Scholarship Fund', 'Trust Fund', 'MDS Trust Fund'])
            ->where('options.transportMeans', ['Public', 'Official Vehicle', 'Airplane', 'Ferry', 'Tricycle', 'Grab']));
});

it('saves a liquidation, remembers the traveller and opens it for editing', function () {
    $response = $this->post(route('liquidations.store'), liquidationPayload());

    $liquidation = Liquidation::sole();
    $response->assertRedirect(route('liquidations.edit', $liquidation))->assertInertiaFlash('status', true);

    expect($liquidation->items)->toHaveCount(2)
        ->and($liquidation->items[0]->place)->toBe('Residence to DOST IX Office')
        ->and($liquidation->items[1]->arrival_time)->toBeNull()
        ->and($liquidation->travelExpenses())->toBe(2550.0)
        ->and($liquidation->refund())->toBe(0.0)
        ->and($liquidation->traveler->employee_no)->toBe('VCM-0920');
});

it('saves photos picked before the first save', function () {
    $this->post(route('liquidations.store'), liquidationPayload([
        'photos' => [UploadedFile::fake()->image('flag-raising.jpg', 2400, 1600), UploadedFile::fake()->image('bloodletting.png', 600, 400)],
    ]))->assertInertiaFlash('status', true);

    $photos = Liquidation::sole()->photos;
    expect($photos)->toHaveCount(2)
        ->and($photos->pluck('original_name')->all())->toBe(['flag-raising.jpg', 'bloodletting.png'])
        ->and(getimagesizefromstring(Storage::disk('local')->get($photos[0]->path))[0])->toBe(1600);
});

it('rejects the first save when a picked file is not a photo, storing nothing', function () {
    $this->post(route('liquidations.store'), liquidationPayload([
        'photos' => [UploadedFile::fake()->image('ok.jpg'), UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf')],
    ]))->assertSessionHasErrors('photos.1');

    expect(Liquidation::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('ignores photos sent with an update; those go through the uploader', function () {
    $this->post(route('liquidations.store'), liquidationPayload());
    $liquidation = Liquidation::sole();

    $this->put(route('liquidations.update', $liquidation), liquidationPayload(['photos' => [UploadedFile::fake()->image('x.jpg')]]))
        ->assertInertiaFlash('status', true);

    expect($liquidation->photos()->count())->toBe(0);
});

it('saves CENRR certificates and opens them again', function () {
    $this->post(route('liquidations.store'), liquidationPayload(['receipt_certificates' => [
        ['lines' => [['particulars' => 'Tricycle, Residence to Zamboanga Airport', 'amount' => '150'], ['particulars' => 'Tricycle, Zamboanga Airport to Residence', 'amount' => '150']]],
        ['lines' => [['particulars' => 'Porterage', 'amount' => '100']]],
    ]]))->assertInertiaFlash('status', true);

    $liquidation = Liquidation::sole();
    expect($liquidation->receiptCertificates())->toHaveCount(2)
        ->and($liquidation->receiptCertificates()[0]['total'])->toBe(300.0);

    $this->get(route('liquidations.edit', $liquidation))
        ->assertInertia(fn (Assert $page) => $page
            ->has('liquidation.receipt_certificates', 2)
            ->where('liquidation.receipt_certificates.1.lines.0.particulars', 'Porterage')
            ->where('options.cenrrMaxAmount', 300));
});

it('keeps each CENRR within three items and ₱300', function () {
    $this->post(route('liquidations.store'), liquidationPayload(['receipt_certificates' => [
        ['lines' => [['particulars' => 'Fare A', 'amount' => '200'], ['particulars' => 'Fare B', 'amount' => '150']]],
        ['lines' => array_fill(0, 4, ['particulars' => 'Fare', 'amount' => '10'])],
        ['lines' => [['particulars' => 'Long trip', 'amount' => '301']]],
        ['lines' => [['particulars' => '', 'amount' => '50']]],
    ]]))->assertSessionHasErrors([
        'receipt_certificates.0' => 'A certificate can total at most ₱300. Move an item to another certificate.',
        'receipt_certificates.1.lines' => 'A certificate holds 3 items at most.',
        'receipt_certificates.2.lines.0.amount' => "One item can't be more than ₱300.",
        'receipt_certificates.3.lines.0.particulars' => 'Describe the expense.',
    ]);

    expect(Liquidation::count())->toBe(0);
});

it('tracks which papers are still missing', function () {
    $liquidation = Liquidation::factory()->withItinerary()->create(['activity_body' => null]);

    expect($liquidation->missingPapers())->toBe(['Travel Order', 'Activity Report', 'Certificate of Appearance'])
        ->and(collect($liquidation->checklist())->firstWhere('key', 'cenrr')['status'])->toBe('not_needed');

    $this->get(route('liquidations.index'))
        ->assertInertia(fn (Assert $page) => $page->where('liquidations.data.0.missing_papers', ['Travel Order', 'Activity Report', 'Certificate of Appearance']));

    $liquidation->update(['has_travel_order' => true, 'has_certificate_of_appearance' => true, 'activity_body' => 'We attended.']);

    expect($liquidation->refresh()->missingPapers())->toBe([]);
});

it('records the Travel Order and Certificate of Appearance as received', function () {
    $this->post(route('liquidations.store'), liquidationPayload(['has_travel_order' => true, 'has_certificate_of_appearance' => false]));

    expect(Liquidation::sole())
        ->has_travel_order->toBeTrue()
        ->has_certificate_of_appearance->toBeFalse();
});

it('records whether lunch was provided at the activity', function () {
    $this->post(route('liquidations.store'), liquidationPayload());
    expect(Liquidation::sole()->lunch_provided)->toBeFalse();

    $this->put(route('liquidations.update', Liquidation::sole()), liquidationPayload(['lunch_provided' => true]));
    expect(Liquidation::sole()->lunch_provided)->toBeTrue();

    $this->get(route('liquidations.edit', Liquidation::sole()))
        ->assertInertia(fn (Assert $page) => $page->where('liquidation.lunch_provided', true));
});

it('opens a saved liquidation with its itinerary, photos and deadline', function () {
    $liquidation = Liquidation::factory()->withItinerary()->create(['return_date' => '2026-09-22', 'departure_date' => '2026-09-20']);

    $this->get(route('liquidations.edit', $liquidation))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Liquidation/Edit')
            ->where('liquidation.id', $liquidation->id)
            ->where('liquidation.traveler_name', $liquidation->traveler_name)
            ->has('liquidation.items', 5)
            ->where('liquidation.items.0.place', 'Residence to DOST IX Office')
            ->has('liquidation.photos', 0)
            ->where('liquidation.deadline', '2026-10-22'));
});

it('keeps itinerary dates inside the trip and asks why a trip changed', function () {
    $this->post(route('liquidations.store'), liquidationPayload([
        'items' => [['date' => '2026-09-25']],
        'compliance' => 'cut_short',
    ]))->assertSessionHasErrors(['items.0.date', 'compliance_note']);

    $empty = liquidationPayload(['return_date' => '2026-09-19']);
    $empty['items'] = [];
    $this->post(route('liquidations.store'), $empty)->assertSessionHasErrors(['items', 'return_date']);

    expect(Liquidation::count())->toBe(0);
});

it('replaces the itinerary on update and records submission', function () {
    $this->post(route('liquidations.store'), liquidationPayload());
    $liquidation = Liquidation::sole();

    $payload = liquidationPayload(['submitted_on' => '2026-10-01']);
    $payload['items'] = [$payload['items'][1]];
    $this->put(route('liquidations.update', $liquidation), $payload)->assertInertiaFlash('status', true);

    $liquidation->refresh();
    expect($liquidation->items)->toHaveCount(1)
        ->and($liquidation->status())->toBe('submitted')
        ->and($liquidation->refund())->toBe(1650.0);
});

it('works out refund, reimbursement and the 30-day deadline', function () {
    $liquidation = Liquidation::factory()->withItinerary()->create([
        'departure_date' => '2026-09-20', 'return_date' => '2026-09-22', 'cash_advance_amount' => 5000,
    ]);

    expect($liquidation->totalSpent())->toBe(3300.0)
        ->and($liquidation->refund())->toBe(1700.0)
        ->and($liquidation->reimbursement())->toBe(0.0)
        ->and($liquidation->deadline()->toDateString())->toBe('2026-10-22');

    $liquidation->update(['cash_advance_amount' => 3000, 'supplies_amount' => 200]);
    expect($liquidation->refresh()->reimbursement())->toBe(500.0);
});

it('writes names, dates and amounts the way the forms do', function () {
    $liquidation = Liquidation::factory()->make([
        'traveler_name' => 'RICARDO J. APOLINARIO III', 'departure_date' => '2026-09-30', 'return_date' => '2026-10-02',
    ]);

    expect($liquidation->initials())->toBe('RJA')
        ->and($liquidation->travelDates())->toBe('30 September - 2 October 2026')
        ->and($liquidation->travelDatesMonthFirst())->toBe('September 30 - October 2, 2026')
        ->and(Liquidation::factory()->make(['traveler_name' => 'VINCE BRADLEY C. MULOC'])->initials())->toBe('VCM');
});

it('adds others on the same travel order, with the reports, photos and their own fares', function () {
    $source = Liquidation::factory()->withItinerary()->create(['dv_no' => 'DV-1', 'submitted_on' => now()]);
    $this->post(route('liquidations.photos.store', $source), ['photos' => [UploadedFile::fake()->image('team.jpg', 800, 600)]]);
    Traveler::create([
        'name' => 'EVA MAE LARGO', 'position' => 'PTA III', 'official_station' => 'DOST- Zamboanga City', 'division' => 'TOS',
        'home_area' => 'zamboanga', 'office_distance' => 'Beyond 15 km',
    ]);

    $this->post(route('liquidations.travelers.store', $source), ['travelers' => ['EVA MAE LARGO']])
        ->assertRedirect()
        ->assertInertiaFlash('message', "Added EVA MAE LARGO to TO {$source->travel_order_no}.");

    $copy = Liquidation::latest('id')->first();
    expect($copy->id)->not->toBe($source->id)
        ->and($copy->traveler_name)->toBe('EVA MAE LARGO')
        ->and($copy->position)->toBe('PTA III')
        ->and($copy->dv_no)->toBeNull()
        ->and($copy->submitted_on)->toBeNull()
        ->and($copy->purpose)->toBe($source->purpose)
        ->and($copy->items)->toHaveCount(5)
        ->and($copy->items->pluck('transport_cost')->map(fn ($fare) => (float) $fare)->all())->toBe([400.0, 0.0, 0.0, 0.0, 400.0])
        ->and($copy->photos)->toHaveCount(1)
        ->and($copy->photos[0]->path)->not->toBe($source->photos[0]->path);
    Storage::disk('local')->assertExists($copy->photos[0]->path);

    $this->get(route('liquidations.edit', $copy))
        ->assertInertia(fn (Assert $page) => $page
            ->has('team', 2)
            ->where('team', fn ($team) => collect($team)->firstWhere('id', $source->id)['submitted'] === true));
});

it('warns when someone added has no fares saved', function () {
    $source = Liquidation::factory()->withItinerary()->create();
    Traveler::create(['name' => 'KARL AUSTHYNE D. BANUA', 'position' => 'PTA III', 'official_station' => 'DOST- Zamboanga City', 'division' => 'TOS']);

    $this->post(route('liquidations.travelers.store', $source), ['travelers' => ['KARL AUSTHYNE D. BANUA']])
        ->assertInertiaFlash('message', "Added KARL AUSTHYNE D. BANUA to TO {$source->travel_order_no}. No fares saved for KARL AUSTHYNE D. BANUA — their residence legs kept the original amounts.")
        ->assertInertiaFlash('info', 'warning');

    expect((float) Liquidation::latest('id')->first()->items[0]->transport_cost)->toBe(150.0);

    $this->post(route('liquidations.travelers.store', $source), ['travelers' => []])->assertSessionHasErrors(['travelers' => 'Pick at least one traveller.']);
});

it('stores activity photos scaled down, serves them and removes them', function () {
    $liquidation = Liquidation::factory()->create();

    $this->post(route('liquidations.photos.store', $liquidation), [
        'photos' => [UploadedFile::fake()->image('wide.jpg', 3200, 2000), UploadedFile::fake()->image('small.png', 400, 300)],
    ])->assertInertiaFlash('message', '2 photos added.');

    $photo = $liquidation->photos()->first();
    [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($photo->path));
    expect([$width, $height])->toBe([1600, 1000]);

    $this->get(route('liquidations.photos.show', [$liquidation, $photo]))->assertOk();

    $this->delete(route('liquidations.photos.destroy', [$liquidation, $photo]))->assertInertiaFlash('status', true);
    Storage::disk('local')->assertMissing($photo->path);
    expect($liquidation->photos()->count())->toBe(1);
});

it('caps photos per report and keeps them to their own liquidation', function () {
    $liquidation = Liquidation::factory()->create();
    $other = Liquidation::factory()->create();

    $this->post(route('liquidations.photos.store', $liquidation), [
        'photos' => array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg"), range(1, 7)),
    ])->assertSessionHasErrors('photos');

    $this->post(route('liquidations.photos.store', $other), ['photos' => [UploadedFile::fake()->image('x.jpg')]]);
    $foreign = LiquidationPhoto::sole();

    $this->get(route('liquidations.photos.show', [$liquidation, $foreign]))->assertNotFound();
});

it('deletes a liquidation with its photos', function () {
    $liquidation = Liquidation::factory()->withItinerary()->create();
    $this->post(route('liquidations.photos.store', $liquidation), ['photos' => [UploadedFile::fake()->image('a.jpg')]]);
    $path = $liquidation->photos()->value('path');

    $this->delete(route('liquidations.destroy', $liquidation))->assertRedirect(route('liquidations.index'));

    $this->assertModelMissing($liquidation);
    Storage::disk('local')->assertMissing($path);
});
