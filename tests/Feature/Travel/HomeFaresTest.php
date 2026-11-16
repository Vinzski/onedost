<?php

use App\Models\Traveler;
use App\Services\Travel\HomeFares;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
});

/** A one-day trip as the cash advance form submits it, with the traveller's fare details. */
function fareTrip(array $overrides = []): array
{
    return [
        'traveler_name' => 'EVA MAE LARGO',
        'position' => 'PTA III',
        'official_station' => 'DOST- Zamboanga City',
        'division' => 'Technical Operations Services',
        'home_area' => 'zamboanga',
        'office_distance' => '8–15 km',
        'terminal_distance' => 'Beyond 15 km',
        'travel_order_no' => '2026-8001',
        'departure_date' => '2026-11-10',
        'return_date' => '2026-11-10',
        'venue' => 'Ipil, Zamboanga Sibugay',
        'purpose' => 'Site visit.',
        'fund_cluster' => 'Regular Fund',
        'requested_on' => '2026-11-03',
        'items' => [['date' => '2026-11-10', 'place' => 'Residence to DOST IX Office', 'transport_means' => 'Public', 'transport_cost' => '250']],
        'signatories' => config('onedost.liquidation.signatories'),
        ...$overrides,
    ];
}

it('reads the fares memo by where the traveller lives and how far', function (string $area, string $distance, string $to, ?float $fare) {
    expect(app(HomeFares::class)->fare($area, $distance, $to))->toBe($fare);
})->with([
    'Zamboanga, office, 1–7 km' => ['zamboanga', '1–7 km', 'office', 150.0],
    'Zamboanga, office, 8–15 km' => ['zamboanga', '8–15 km', 'office', 250.0],
    'Zamboanga, office, beyond 15 km' => ['zamboanga', 'Beyond 15 km', 'office', 400.0],
    'Zamboanga, airport/wharf/terminal, 8–15 km' => ['zamboanga', '8–15 km', 'terminal', 300.0],
    'Zamboanga, airport/wharf/terminal, beyond 15 km' => ['zamboanga', 'Beyond 15 km', 'terminal', 500.0],
    'Pagadian, office, beyond 7 km' => ['pagadian', 'Beyond 7 km', 'office', 150.0],
    'Dipolog, terminal, first 10 km' => ['dipolog', 'First 10 km', 'terminal', 200.0],
    'Ipil, terminal, beyond 10 km' => ['ipil', 'Beyond 10 km', 'terminal', 150.0],
    'a distance the area does not have' => ['pagadian', '8–15 km', 'office', null],
]);

it('only puts the memo fare on legs between home and the office or a terminal', function (string $place, ?string $kind) {
    expect(HomeFares::legKind($place))->toBe($kind);
})->with([
    ['Residence to DOST IX Office', 'office'],
    ['DOST IX Office to Residence', 'office'],
    ['Residence to Zamboanga International Airport', 'terminal'],
    ['Zamboanga City Port to Residence', 'terminal'],
    ['Residence to IBT', 'terminal'],
    ['ZC IBT to Residence', 'terminal'],
    // Already at the destination: entered by hand.
    ['Hotel to Pagadian City IBT', null],
    ['Residence at Cogon, Dipolog City to Top Plaza Hotel', null],
    ['DOST IX Office to Pagadian', null],
    ['Still in Pagadian', null],
]);

it('saves where the traveller lives and how far, for their next trips', function () {
    $this->post(route('cash-advances.store'), fareTrip())->assertSessionHasNoErrors();

    expect(Traveler::sole())
        ->home_area->toBe('zamboanga')
        ->office_distance->toBe('8–15 km')
        ->terminal_distance->toBe('Beyond 15 km')
        ->and(app(HomeFares::class)->forTraveler(Traveler::sole(), HomeFares::OFFICE))->toBe(250.0)
        ->and(app(HomeFares::class)->forTraveler(Traveler::sole(), HomeFares::TERMINAL))->toBe(500.0);

    $this->get(route('cash-advances.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('travelers.0.office_distance', '8–15 km')
            ->where('options.homeFares.zamboanga.office.8–15 km', 250)
            ->where('options.terminals.airport', 'Zamboanga International Airport'));
});

it('only takes distances the memo lists for that area', function () {
    $this->post(route('cash-advances.store'), fareTrip(['home_area' => 'pagadian', 'office_distance' => '8–15 km', 'terminal_distance' => null]))
        ->assertSessionHasErrors(['office_distance']);

    $this->post(route('cash-advances.store'), fareTrip(['home_area' => 'manila']))
        ->assertSessionHasErrors(['home_area']);
});
