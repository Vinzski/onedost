<?php

use App\Models\CashAdvance;
use App\Models\Liquidation;
use App\Services\Travel\Destinations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
});

/** A one-leg trip to $destination, as either travel form submits it. */
function tripTo(?string $destination, array $overrides = []): array
{
    return [
        'traveler_name' => 'VINCE BRADLEY C. MULOC',
        'position' => 'Project Technical Assistant II',
        'official_station' => 'DOST- Zamboanga City',
        'division' => 'Technical Operations Services',
        'travel_order_no' => '2026-7001',
        'departure_date' => '2026-11-03',
        'return_date' => '2026-11-04',
        'venue' => 'Elena O. Diola Hall, DOST 7, Lahug, Cebu City',
        'destination_code' => $destination,
        'purpose' => 'Regional planning workshop.',
        'fund_cluster' => 'Regular Fund',
        'items' => [['date' => '2026-11-03', 'place' => 'DOST IX Office to Cebu City', 'transport_means' => 'Airplane', 'per_diem' => '1800']],
        'compliance' => 'strict',
        'signatories' => config('onedost.liquidation.signatories'),
        ...$overrides,
    ];
}

it('gives every region in the place list an EO 77 rate', function () {
    $regions = array_map('strval', array_keys(app(Destinations::class)->list()['regions']));
    $rated = array_map('strval', array_keys(config('onedost.liquidation.dte_rates')));

    expect(array_diff($regions, $rated))->toBe([])
        ->and(count(app(Destinations::class)->list()['places']))->toBeGreaterThan(1700);
});

it('sets the per diem by the region the trip goes to', function (string $code, float $rate) {
    $destinations = app(Destinations::class);

    expect($destinations->has($code))->toBeTrue()
        ->and($destinations->rate($code))->toBe($rate);
})->with([
    'Zamboanga City (IX)' => ['0931700000', 1500.0],
    'Pagadian City (IX)' => ['0907322000', 1500.0],
    'Isabela City, Basilan (IX)' => ['0990101000', 1500.0],
    'Cotabato City (BARMM)' => ['1908703000', 1500.0],
    'Cebu City (VII)' => ['0730600000', 1800.0],
    'Davao City (XI)' => ['1130700000', 1800.0],
    'Baguio City (CAR)' => ['1430300000', 1800.0],
    'Manila (NCR)' => ['1380600000', 2200.0],
    'Puerto Princesa City (IV-B)' => ['1731500000', 2200.0],
]);

it('falls back to the default rate while no destination is picked', function () {
    expect(app(Destinations::class)->rate(null))->toBe(1500.0)
        ->and(app(Destinations::class)->has(null))->toBeFalse();
});

it('keeps the destination on cash advances and claims, and hands the forms the rates', function () {
    $this->post(route('cash-advances.store'), tripTo('0730600000', ['requested_on' => '2026-10-20']))->assertSessionHasNoErrors();
    $this->post(route('reimbursements.store'), tripTo('0730600000'))->assertSessionHasNoErrors();

    expect(CashAdvance::sole()->destination_code)->toBe('0730600000')
        ->and(Liquidation::sole()->destination_code)->toBe('0730600000');

    $this->get(route('cash-advances.edit', CashAdvance::sole()))
        ->assertInertia(fn (Assert $page) => $page
            ->where('cashAdvance.destination_code', '0730600000')
            ->where('options.dteRates.07', 1800)
            ->where('options.dteRates.13', 2200)
            ->where('options.officialStationPlace', '0931700000'));

    $this->get(route('reimbursements.edit', Liquidation::sole()))
        ->assertInertia(fn (Assert $page) => $page
            ->where('liquidation.destination_code', '0730600000')
            ->where('options.dteRates.09', 1500));
});

it('saves a trip with no destination picked', function () {
    $this->post(route('reimbursements.store'), tripTo(null))->assertSessionHasNoErrors();

    expect(Liquidation::sole()->destination_code)->toBeNull();
});

it('only takes a destination from the list', function () {
    $this->post(route('liquidations.store'), tripTo('0999999999'))
        ->assertSessionHasErrors(['destination_code' => 'Pick the destination from the list.']);

    expect(Liquidation::count())->toBe(0);
});

it('rebuilds the place list from the PSGC', function () {
    Http::fake([
        'psgc.cloud/api/regions' => Http::response([
            ['code' => '0900000000', 'name' => 'Region IX (Zamboanga Peninsula)'],
            ['code' => '1300000000', 'name' => 'National Capital Region (NCR)'],
            ['code' => '1700000000', 'name' => 'MIMAROPA Region'],
            ['code' => '2000000000', 'name' => 'Some New Region'],
        ]),
        'psgc.cloud/api/provinces' => Http::response([
            ['code' => '0907300000', 'name' => 'Zamboanga del Sur'],
        ]),
        'psgc.cloud/api/cities-municipalities' => Http::response([
            ['code' => '0907322000', 'name' => 'City of Pagadian', 'type' => 'City'],
            ['code' => '0907305000', 'name' => 'Dumalinao ', 'type' => 'Mun'],
            ['code' => '0931700000', 'name' => 'City of Zamboanga', 'type' => 'City'],
            ['code' => '1380600000', 'name' => 'City of Manila', 'type' => 'City'],
            ['code' => '1380601000', 'name' => 'Tondo I/II', 'type' => 'SubMun'],
            ['code' => '1380200000', 'name' => 'City of Las PiÃ±as', 'type' => 'City'],
        ]),
    ]);
    $path = tempnam(sys_get_temp_dir(), 'places');

    $this->artisan('travel:sync-places', ['--output' => $path])
        ->expectsOutputToContain('Saved 6 places in 4 regions')
        ->expectsOutputToContain('for region 20')
        ->assertSuccessful();

    $list = json_decode(file_get_contents($path), true);
    unlink($path);

    expect($list['regions'])->toMatchArray(['09' => 'Region IX (Zamboanga Peninsula)', '17' => 'Region IV-B (MIMAROPA)'])
        ->and($list['places'])->toBe([
            ['0907305000', 'Dumalinao', 'Zamboanga del Sur', 'municipality'],
            ['1380200000', 'Las Piñas City', '', 'city'],
            ['1380600000', 'Manila', '', 'city'],
            ['0907322000', 'Pagadian City', 'Zamboanga del Sur', 'city'],
            ['0931700000', 'Zamboanga City', '', 'city'],
            ['0907300000', 'Zamboanga del Sur', '', 'province'],
        ]);
});
