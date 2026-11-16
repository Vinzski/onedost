<?php

use App\Enums\FlightTrip;
use App\Models\BookingPassenger;
use App\Models\BookingRequest;
use App\Models\CashAdvance;
use App\Models\Liquidation;
use App\Models\Traveler;
use App\Services\BookingRequest\BookingRequestWorkbook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

/** A booking request as the form submits it: the Cebu RD's meeting, three passengers. */
function bookingPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'travel_order_no' => '2026-5667',
        'purpose' => 'To present the updates of the PRISM during the 3rd Quarter Regional Director\'s Meeting.',
        'fund_source' => 'Regular Fund',
        'project' => null,
        'trip' => 'return',
        'airline' => 'cebu_pacific',
        'origin' => 'Zamboanga',
        'destination' => 'Cebu',
        'departure_date' => '2026-09-14',
        'departure_etd' => '12:50 pm - 2:30 pm',
        'return_date' => '2026-09-16',
        'return_etd' => '6:40 am - 8:20 am',
        'boarding_passes_submitted' => true,
        'requested_by' => 'VINCE BRADLEY C. MULOC',
        'requested_on' => '2026-09-09',
        'signatories' => config('onedost.booking.signatories'),
        'passengers' => [
            ['title' => 'Ms.', 'first_name' => 'Mary Grace', 'last_name' => 'Torres', 'remarks' => 'RP for STi Congress', 'contact_number' => '09170000001',
                'email' => 'grace@example.com', 'birth_date' => '1990-01-15', 'going_baggage' => true, 'return_baggage' => true],
            ['title' => 'Mr.', 'first_name' => 'VINCE BRADLEY', 'last_name' => 'MULOC', 'contact_number' => '09170000002',
                'email' => 'vince@example.com', 'birth_date' => '1995-03-10', 'going_baggage' => true, 'return_baggage' => false],
            ['title' => 'Mr.', 'first_name' => 'PETER JUSTIN', 'last_name' => 'DELOS REYES', 'contact_number' => '09170000003',
                'email' => 'peter@example.com', 'birth_date' => '1998-07-25', 'going_baggage' => false, 'return_baggage' => false],
        ],
    ], $overrides);
}

it('saves a booking request and remembers each passenger for next time', function () {
    $vince = Traveler::create(['name' => 'VINCE BRADLEY C. MULOC', 'position' => 'PTA II', 'official_station' => 'DOST- Zamboanga City', 'division' => 'TOS']);
    $payload = bookingPayload();
    $payload['passengers'][1]['traveler_id'] = $vince->id;

    $response = $this->post(route('booking-requests.store'), $payload);

    $booking = BookingRequest::sole();
    $response->assertRedirect(route('booking-requests.edit', $booking))->assertInertiaFlash('message', 'Booking request saved.');

    expect($booking)
        ->trip->toBe(FlightTrip::Return)
        ->status()->toBe('draft')
        ->flightRoute()->toBe('Zamboanga ⇄ Cebu')
        ->missing()->toBe([])
        ->and($booking->passengers)->toHaveCount(3)
        ->and($booking->passengers[1]->traveler_id)->toBe($vince->id);

    // The IOT traveller gets his booking details; someone new becomes a saved traveller.
    expect($vince->refresh())
        ->first_name->toBe('VINCE BRADLEY')
        ->last_name->toBe('MULOC')
        ->email->toBe('vince@example.com')
        ->birth_date->toDateString()->toBe('1995-03-10')
        ->position->toBe('PTA II')
        ->and(Traveler::where('name', 'MARY GRACE TORRES')->sole())
        ->title->toBe('Ms.')
        ->contact_number->toBe('09170000001')
        ->and(Traveler::count())->toBe(3);

    $this->get(route('booking-requests.edit', $booking))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('BookingRequest/Edit')
            ->where('bookingRequest.passengers.0.first_name', 'Mary Grace')
            ->where('bookingRequest.return_date', '2026-09-16')
            ->where('travelers', fn ($travelers) => collect($travelers)->firstWhere('name', 'VINCE BRADLEY C. MULOC')['birth_date'] === '1995-03-10')
            ->where('travelOrders', []));
});

it('never erases saved details with a blank', function () {
    $this->post(route('booking-requests.store'), bookingPayload());
    $payload = bookingPayload(['travel_order_no' => '2026-6000']);
    $payload['passengers'] = [['first_name' => 'Mary Grace', 'last_name' => 'Torres', 'email' => null, 'contact_number' => '']];

    $this->post(route('booking-requests.store'), $payload)->assertSessionHasNoErrors();

    expect(Traveler::where('name', 'MARY GRACE TORRES')->sole()->email)->toBe('grace@example.com')
        ->and(BookingRequest::latest('id')->first()->missing())->toBe(['Passenger 1: contact number, email, date of birth']);
});

it('drops the return flight from a one-way booking', function () {
    $this->post(route('booking-requests.store'), bookingPayload(['trip' => 'one_way']))->assertSessionHasNoErrors();

    $booking = BookingRequest::sole();
    expect($booking)
        ->return_date->toBeNull()
        ->return_etd->toBeNull()
        ->flightRoute()->toBe('Zamboanga → Cebu')
        ->and($booking->passengers->pluck('return_baggage')->unique()->all())->toBe([false]);
});

it('checks what the form needs', function () {
    $this->post(route('booking-requests.store'), bookingPayload([
        'passengers' => [['first_name' => '', 'email' => 'not-an-email', 'birth_date' => now()->addDay()->toDateString()]],
        'return_date' => '2026-09-01',
    ]))->assertSessionHasErrors([
        'passengers.0.first_name' => 'First name, as on their ID.',
        'passengers.0.email',
        'passengers.0.birth_date' => 'This birth date is in the future.',
        'return_date' => 'The return flight can\'t be before the departure.',
    ]);

    $payload = bookingPayload();
    $payload['passengers'] = [];
    $this->post(route('booking-requests.store'), $payload)->assertSessionHasErrors(['passengers' => 'Add at least one passenger.']);
});

it('fills the Request for Airline Booking, three passengers to a sheet', function () {
    $payload = bookingPayload();
    $payload['passengers'][] = ['title' => 'Ms.', 'first_name' => 'EVA MAE', 'last_name' => 'LARGO', 'contact_number' => '09170000000', 'email' => 'eva@example.com', 'birth_date' => '1995-01-02'];
    $this->post(route('booking-requests.store'), $payload);

    $book = app(BookingRequestWorkbook::class)->build(BookingRequest::sole());
    [$first, $second] = $book->getAllSheets();
    $value = fn ($sheet, string $cell) => (string) $sheet->getCell($cell)->getValue();

    expect($book->getSheetNames())->toBe(['Passengers 1-3', 'Passenger 4'])
        // Passenger 1
        ->and($value($first, 'G6'))->toBe('☑ Ms.    ☐ Mr.')
        ->and($value($first, 'G7'))->toBe('MARY GRACE')
        ->and($value($first, 'H7'))->toBe('TORRES')
        ->and($value($first, 'G9'))->toBe('RP for STi Congress')
        ->and($value($first, 'G11'))->toBe('☑ Cebu Pacific')
        ->and($value($first, 'G12'))->toBe('☐ PAL Express')
        ->and($value($first, 'G15'))->toBe("☐ One-way\n☑ Return Flight\n☐ Multi City")
        ->and($value($first, 'G18'))->toBe('September 14, 2026')
        ->and($value($first, 'G20'))->toBe('☑ YES    ☐ NO')
        ->and($value($first, 'G22'))->toBe('September 16, 2026')
        ->and($value($first, 'G29'))->toBe('09170000001')
        ->and($value($first, 'G31'))->toBe('January 15, 1990')
        // Passenger 2 keeps their own baggage choice; passenger 3 is in the third column.
        ->and($value($first, 'J24'))->toBe('☐ YES    ☑ NO')
        ->and($value($first, 'M7'))->toBe('PETER JUSTIN')
        ->and($value($first, 'N7'))->toBe('DELOS REYES')
        ->and($value($first, 'C34'))->toContain('☑ YES    ☐ NO')
        ->and($value($first, 'E38'))->toBe('VINCE BRADLEY C. MULOC')
        ->and($value($first, 'J38'))->toBe('9 September 2026')
        ->and($value($first, 'J41'))->toBe('ROSEMARIE S. SALAZAR')
        // The second sheet numbers on from 4 and leaves its empty columns blank.
        ->and($value($second, 'F7'))->toBe('4')
        ->and($value($second, 'G5'))->toBe('PASSENGER 4')
        ->and($value($second, 'M46'))->toBe('PASSENGER 6')
        ->and($value($second, 'G7'))->toBe('EVA MAE')
        ->and($value($second, 'J7'))->toBe('')
        ->and($value($second, 'J11'))->toBe('')
        ->and($value($second, 'I7'))->toBe('5');
});

it('downloads the filled form', function () {
    $booking = BookingRequest::factory()->withPassengers(2)->create(['travel_order_no' => '2026-5667']);

    $response = $this->get(route('booking-requests.download', $booking))
        ->assertOk()
        ->assertDownload('TO 2026-5667 - Airline Booking Request.xlsx');

    expect(IOFactory::load($response->getFile()->getPathname())->getSheetNames())->toBe(['Booking Request']);
});

it('lists booking requests by where they stand', function () {
    BookingRequest::factory()->withPassengers()->create(['travel_order_no' => '2026-1111']);
    BookingRequest::factory()->withPassengers()->create(['submitted_on' => now()]);
    BookingRequest::factory()->withPassengers()->create(['submitted_on' => now(), 'booking_reference' => 'PAL-ABC123']);

    $this->get(route('booking-requests.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('BookingRequest/Index')
            ->has('bookingRequests.data', 3)
            ->where('summary.draft', 1)
            ->where('summary.submitted', 1)
            ->where('summary.booked', 1));

    $this->get(route('booking-requests.index', ['status' => 'booked']))
        ->assertInertia(fn (Assert $page) => $page->has('bookingRequests.data', 1)->where('bookingRequests.data.0.status', 'booked'));
    $this->get(route('booking-requests.index', ['search' => '1111']))
        ->assertInertia(fn (Assert $page) => $page->has('bookingRequests.data', 1)->where('bookingRequests.data.0.travel_order_no', '2026-1111'));
});

it('offers each travel order with everyone on it as a starting point', function () {
    CashAdvance::factory()->withItinerary()->create(['travel_order_no' => '2026-5698', 'traveler_name' => 'EVA MAE LARGO', 'destination_code' => '0730600000', 'departure_date' => '2026-12-01', 'return_date' => '2026-12-03']);
    CashAdvance::factory()->withItinerary()->create(['travel_order_no' => '2026-5698', 'traveler_name' => 'KARL AUSTHYNE D. BANUA', 'departure_date' => '2026-12-01', 'return_date' => '2026-12-03']);
    Liquidation::factory()->reimbursement()->create(['travel_order_no' => '2026-4000', 'traveler_name' => 'VINCE BRADLEY C. MULOC', 'venue' => 'Elena O. Diola Hall, Lahug, Cebu City.', 'departure_date' => '2026-11-01', 'return_date' => '2026-11-02']);
    BookingRequest::factory()->withPassengers()->create(['travel_order_no' => '2026-4000']);

    $this->get(route('booking-requests.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('travelOrders', 2)
            ->where('travelOrders.0.travel_order_no', '2026-5698')
            ->where('travelOrders.0.destination', 'Cebu')
            ->where('travelOrders.0.departure_date', '2026-12-01')
            ->where('travelOrders.0.travelers', ['EVA MAE LARGO', 'KARL AUSTHYNE D. BANUA'])
            ->where('travelOrders.0.has_booking', false)
            ->where('travelOrders.1.destination', 'Cebu')
            ->where('travelOrders.1.has_booking', true)
            ->where('options.airlines.0.label', 'Cebu Pacific'));
});

it('deletes a booking request with its passengers', function () {
    $booking = BookingRequest::factory()->withPassengers(2)->create();

    $this->delete(route('booking-requests.destroy', $booking))->assertRedirect(route('booking-requests.index'));

    $this->assertModelMissing($booking);
    expect(BookingPassenger::count())->toBe(0);
});

it('shows booking requests on the dashboard', function () {
    BookingRequest::factory()->withPassengers()->create();

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('bookingRequests.draft', 1));
});
