<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

it('redirects the home page to the dashboard', function () {
    $this->get('/')->assertRedirect(route('dashboard'));
});

it('renders the dashboard with each module\'s headline figures', function () {
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('cashAdvances.total', 0)
            ->where('liquidations.pending', 0)
            ->where('reimbursements.pending', 0)
            ->where('leaveApplications.draft', 0)
            ->missing('chequeTracker'));
});
