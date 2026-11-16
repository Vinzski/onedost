<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    // Signed out: tests/Pest.php signs every feature test in.
    $this->app['auth']->forgetGuards();
});

function owner(): User
{
    return User::create(['name' => 'Juan', 'email' => 'juan@example.com', 'password' => 'a long enough password']);
}

it('sends anyone signed out to the login, and hides the app\'s routes from them', function () {
    foreach (['/', '/dashboard', '/dtr', '/backup', '/leave-applications/1/download'] as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }
    $this->post(route('dtr.notes.store'))->assertRedirect(route('login'));

    $html = $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/Login'))
        ->getContent();

    expect($html)->toContain('"login.store"')->not->toContain('dtr.import')->not->toContain('backup');
});

it('logs in, back to the page that was asked for', function () {
    owner();
    $this->get(route('dtr.index'));

    $this->post(route('login.store'), ['email' => 'juan@example.com', 'password' => 'a long enough password'])
        ->assertRedirect(route('dtr.index'));

    $this->assertAuthenticated();
    $this->get(route('login'))->assertRedirect(route('dashboard'));
    // The layout gets the name and email, nothing more.
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('auth.user', ['name' => 'Juan', 'email' => 'juan@example.com']));
});

it('gives the same answer for a wrong email as for a wrong password', function () {
    owner();

    $this->post(route('login.store'), ['email' => 'juan@example.com', 'password' => 'wrong'])
        ->assertSessionHasErrors(['email' => 'That email and password don\'t match.']);
    $this->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'wrong'])
        ->assertSessionHasErrors(['email' => 'That email and password don\'t match.']);

    $this->assertGuest();
});

it('makes a guesser wait after five wrong passwords', function () {
    owner();
    foreach (range(1, 5) as $try) {
        $this->post(route('login.store'), ['email' => 'juan@example.com', 'password' => "wrong {$try}"]);
    }

    // Even the right password waits now.
    $this->post(route('login.store'), ['email' => 'juan@example.com', 'password' => 'a long enough password'])
        ->assertSessionHasErrors(['email' => 'Too many tries. Try again in 1 minute.']);
    $this->assertGuest();
});

it('logs out', function () {
    $this->actingAs(owner());

    $this->post(route('logout'))->assertRedirect(route('login'));

    $this->assertGuest();
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

it('makes the login, and sets a new password, from the command line', function () {
    $this->artisan('app:user', ['email' => 'Juan@Example.com', '--name' => 'Juan'])
        ->expectsQuestion('Password for juan@example.com', 'a long enough password')
        ->expectsQuestion('Type it again', 'a long enough password')
        ->expectsOutput('Login made for juan@example.com.')
        ->assertSuccessful();

    expect(Auth::attempt(['email' => 'juan@example.com', 'password' => 'a long enough password']))->toBeTrue();
    Auth::logout();

    $this->artisan('app:user', ['email' => 'juan@example.com'])
        ->expectsQuestion('New password for juan@example.com', 'another long password')
        ->expectsQuestion('Type it again', 'a typo')
        ->expectsOutput('The passwords don\'t match. Nothing changed.')
        ->assertFailed();

    $this->artisan('app:user', ['email' => 'juan@example.com'])
        ->expectsQuestion('New password for juan@example.com', 'another long password')
        ->expectsQuestion('Type it again', 'another long password')
        ->expectsOutput('New password set for juan@example.com.')
        ->assertSuccessful();

    expect(User::sole())->name->toBe('Juan')
        ->and(Auth::attempt(['email' => 'juan@example.com', 'password' => 'another long password']))->toBeTrue();
});
