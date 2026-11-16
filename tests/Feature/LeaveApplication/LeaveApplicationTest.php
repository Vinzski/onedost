<?php

use App\Enums\LeaveDetail;
use App\Enums\LeaveOutcome;
use App\Enums\LeaveType;
use App\Models\LeaveApplication;
use App\Models\Traveler;
use App\Services\LeaveApplication\LeaveApplicationForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

/** A leave application as the form submits it: the region's own sample. */
function leavePayload(array $overrides = []): array
{
    return array_replace_recursive([
        'traveler_id' => null,
        'office' => 'DOST IX - TOS',
        'last_name' => 'Monopollo',
        'first_name' => 'John Carlo',
        'middle_name' => 'W.',
        'position' => 'PTA III',
        'salary' => null,
        'filed_on' => '2026-09-28',
        'leave_type' => 'vacation',
        'leave_type_other' => null,
        'detail' => 'within_philippines',
        'detail_text' => 'Zamboanga City',
        'working_days' => 2,
        'start_date' => '2026-10-07',
        'end_date' => '2026-10-08',
        'inclusive_dates' => null,
        'commutation_requested' => false,
        'signatories' => config('onedost.leave.signatories'),
        'submitted_on' => null,
        'outcome' => null,
    ], $overrides);
}

/** Every text a generated PDF draws, its streams inflated. */
function pdfText(string $pdf): string
{
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);

    return collect($streams[1])->map(fn (string $stream) => @gzuncompress($stream) ?: '')->implode("\n");
}

it('saves a leave application and remembers the employee for next time', function () {
    $response = $this->post(route('leave-applications.store'), leavePayload());

    $leave = LeaveApplication::sole();
    $response->assertRedirect(route('leave-applications.edit', $leave))->assertInertiaFlash('message', 'Leave application saved.');

    expect($leave)
        ->leave_type->toBe(LeaveType::Vacation)
        ->detail->toBe(LeaveDetail::WithinPhilippines)
        ->status()->toBe('draft')
        ->fullName()->toBe('John Carlo W. Monopollo')
        ->daysApplied()->toBe('2 DAYS')
        ->inclusiveDates()->toBe('October 7-8, 2026')
        ->and(Traveler::sole())
        ->name->toBe('JOHN CARLO W. MONOPOLLO')
        ->middle_name->toBe('W.')
        ->position->toBe('PTA III')
        ->id->toBe($leave->traveler_id);

    $this->get(route('leave-applications.edit', $leave))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('LeaveApplication/Edit')
            ->where('leaveApplication.last_name', 'Monopollo')
            ->where('leaveApplication.detail', 'within_philippines')
            ->where('leaveApplication.status', 'draft')
            ->where('travelers.0.middle_name', 'W.')
            ->has('options.types', 14));
});

it('finds a saved traveller by name and keeps their saved position', function () {
    $vince = Traveler::create(['name' => 'VINCE BRADLEY C. MULOC', 'position' => 'Project Technical Assistant II', 'official_station' => 'DOST- Zamboanga City', 'division' => 'TOS']);

    $this->post(route('leave-applications.store'), leavePayload([
        'last_name' => 'Muloc', 'first_name' => 'Vince Bradley', 'middle_name' => 'C.', 'position' => 'PTA II',
    ]))->assertSessionHasNoErrors();

    expect(LeaveApplication::sole()->traveler_id)->toBe($vince->id)
        ->and($vince->refresh())
        ->middle_name->toBe('C.')
        ->first_name->toBe('Vince Bradley')
        // The itinerary keeps its wording; the leave form keeps its own.
        ->position->toBe('Project Technical Assistant II')
        ->and(LeaveApplication::sole()->position)->toBe('PTA II')
        ->and(Traveler::count())->toBe(1);
});

it('keeps only what the form prints for the leave picked', function () {
    $this->post(route('leave-applications.store'), leavePayload([
        'leave_type' => 'study', 'detail' => 'masters_degree', 'detail_text' => 'Zamboanga City', 'leave_type_other' => 'Leftover',
    ]))->assertSessionHasNoErrors();

    expect(LeaveApplication::sole())
        ->detail->toBe(LeaveDetail::MastersDegree)
        ->detail_text->toBeNull()
        ->leave_type_other->toBeNull();
});

it('checks what the form needs', function () {
    $this->post(route('leave-applications.store'), leavePayload(['detail' => null]))
        ->assertSessionHasErrors(['detail' => 'Tick one of the boxes for this leave.']);

    $this->post(route('leave-applications.store'), leavePayload(['leave_type' => 'sick', 'detail' => 'abroad']))
        ->assertSessionHasErrors(['detail' => 'This box doesn\'t go with the leave picked.']);

    $this->post(route('leave-applications.store'), leavePayload(['detail' => 'abroad', 'detail_text' => '']))
        ->assertSessionHasErrors(['detail_text' => 'Say which country.']);

    $this->post(route('leave-applications.store'), leavePayload(['leave_type' => 'others', 'detail' => null]))
        ->assertSessionHasErrors(['leave_type_other' => 'Say what kind of leave.']);

    $this->post(route('leave-applications.store'), leavePayload(['end_date' => '2026-10-01', 'working_days' => 1.25]))
        ->assertSessionHasErrors([
            'end_date' => 'The leave can\'t end before it starts.',
            'working_days' => 'Use whole or half days.',
        ]);

    // Maternity leave has no 6.B box to tick.
    $this->post(route('leave-applications.store'), leavePayload(['leave_type' => 'maternity', 'detail' => null, 'working_days' => 105, 'end_date' => '2027-01-19']))
        ->assertSessionHasNoErrors();
});

it('words the days and dates as the form prints them', function (float $days, string $from, string $to, string $printedDays, string $printedDates) {
    $leave = LeaveApplication::factory()->make(['working_days' => $days, 'start_date' => $from, 'end_date' => $to]);

    expect($leave->daysApplied())->toBe($printedDays)
        ->and($leave->inclusiveDates())->toBe($printedDates);
})->with([
    'half a day' => [0.5, '2026-10-07', '2026-10-07', '0.5 DAY', 'October 7, 2026'],
    'one day' => [1, '2026-10-07', '2026-10-07', '1 DAY', 'October 7, 2026'],
    'same month' => [2, '2026-10-07', '2026-10-08', '2 DAYS', 'October 7-8, 2026'],
    'across months' => [1.5, '2026-10-30', '2026-11-02', '1.5 DAYS', 'October 30 - November 2, 2026'],
    'across years' => [4, '2026-12-28', '2027-01-02', '4 DAYS', 'December 28, 2026 - January 2, 2027'],
]);

it('prints dates typed in by hand instead', function () {
    $leave = LeaveApplication::factory()->make(['inclusive_dates' => 'October 7, 9 and 12, 2026']);

    expect($leave->inclusiveDates())->toBe('October 7, 9 and 12, 2026');
});

it('lists leave applications by status and type', function () {
    LeaveApplication::factory()->create(['last_name' => 'DRAFTED']);
    LeaveApplication::factory()->create(['last_name' => 'SENT', 'submitted_on' => now()]);
    LeaveApplication::factory()->create(['last_name' => 'GRANTED', 'submitted_on' => now(), 'outcome' => LeaveOutcome::Approved]);
    LeaveApplication::factory()->create(['last_name' => 'SICK', 'leave_type' => LeaveType::Sick, 'detail' => LeaveDetail::OutPatient, 'outcome' => LeaveOutcome::Disapproved]);

    $this->get(route('leave-applications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('LeaveApplication/Index')
            ->has('leaveApplications.data', 4)
            ->where('summary', ['total' => 4, 'draft' => 1, 'submitted' => 1, 'approved' => 1]));

    $this->get(route('leave-applications.index', ['status' => 'submitted']))
        ->assertInertia(fn (Assert $page) => $page->has('leaveApplications.data', 1)->where('leaveApplications.data.0.status', 'submitted'));

    $this->get(route('leave-applications.index', ['type' => 'sick']))
        ->assertInertia(fn (Assert $page) => $page->has('leaveApplications.data', 1)->where('leaveApplications.data.0.status', 'disapproved'));

    $this->get(route('leave-applications.index', ['search' => 'granted']))
        ->assertInertia(fn (Assert $page) => $page->has('leaveApplications.data', 1)->where('leaveApplications.data.0.status', 'approved'));
});

it('fills the Application for Leave', function () {
    $this->post(route('leave-applications.store'), leavePayload(['salary' => 35097]));
    $leave = LeaveApplication::sole();

    $response = $this->get(route('leave-applications.download', $leave))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))->toStartWith('inline; filename="Leave - MONOPOLLO, JOHN CARLO W. - October 7-8, 2026.pdf"');

    $text = pdfText(app(LeaveApplicationForm::class)->render($leave));
    expect($text)
        ->toContain('(DOST IX - TOS) Tj')
        ->toContain('(MONOPOLLO,) Tj')
        ->toContain('(JOHN CARLO) Tj')
        ->toContain('(W.) Tj')
        ->toContain('(September 28, 2026) Tj')
        ->toContain('(PTA III) Tj')
        ->toContain('(35,097.00) Tj')
        ->toContain('(Zamboanga City) Tj')
        ->toContain('(2 DAYS) Tj')
        ->toContain('(October 7-8, 2026) Tj')
        ->toContain('(RONNEL B. GUNDOY) Tj')
        ->toContain('(\(OIC-ARD FASS\)) Tj')
        ->toContain('(RICARDO J. APOLINARIO III) Tj')
        ->toContain('(ROSEMARIE S. SALAZAR) Tj')
        // Ticked: Vacation Leave, Within the Philippines, commutation not requested.
        ->and(substr_count($text, '(4) Tj'))->toBe(3);
});

it('writes "Others" on its line instead of ticking a box', function () {
    $this->post(route('leave-applications.store'), leavePayload([
        'leave_type' => 'others', 'leave_type_other' => 'Monetization (10 days VL)', 'detail' => 'monetization', 'commutation_requested' => true,
    ]))->assertSessionHasNoErrors();

    $text = pdfText(app(LeaveApplicationForm::class)->render(LeaveApplication::sole()));

    expect($text)->toContain('(Monetization \(10 days VL\)) Tj')
        // Ticked: Monetization of Leave Credits, commutation requested.
        ->and(substr_count($text, '(4) Tj'))->toBe(2);
});

it('deletes a leave application', function () {
    $leave = LeaveApplication::factory()->create();

    $this->delete(route('leave-applications.destroy', $leave))
        ->assertRedirect(route('leave-applications.index'))
        ->assertInertiaFlash('message', 'Leave application deleted.');

    expect(LeaveApplication::count())->toBe(0);
});
