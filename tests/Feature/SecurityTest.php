<?php

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

it('tells browsers not to frame, sniff, leak or index the app', function () {
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'same-origin')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        // HTTPS-only is for the live site, not this machine.
        ->assertHeaderMissing('Strict-Transport-Security');

    expect(file_get_contents(public_path('robots.txt')))->toContain('Disallow: /');
});

it('keeps the live site on HTTPS', function () {
    config(['app.url' => 'https://onedost.example.com']);
    $this->app['env'] = 'production';

    $this->get(route('dashboard'))->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

it('sends a page that sat open too long back, saying so', function () {
    Route::middleware('web')->post('/_expired', fn () => abort(419));

    $this->from('/dtr')->post('/_expired')
        ->assertRedirect('/dtr')
        ->assertInertiaFlash('message', 'The page had expired, so nothing was saved');
});

it('refuses to wipe the live database', function () {
    $this->app['env'] = 'production';
    (new AppServiceProvider($this->app))->boot();

    $this->artisan('db:wipe', ['--force' => true])->assertFailed();

    DB::prohibitDestructiveCommands(false);
});
