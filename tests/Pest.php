<?php

use App\Models\User;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests run against the Laravel TestCase. Add RefreshDatabase per
| file (->use(RefreshDatabase::class)) for any test that touches the DB.
|
| Everything is behind the login, so feature tests run signed in — as a
| user who isn't saved, so tests without a database work too. Tests of
| the login itself sign out first (tests/Feature/Auth).
|
*/

pest()->extend(TestCase::class)
    ->beforeEach(fn () => $this->actingAs(new User(['name' => 'Tester', 'email' => 'tester@example.com'])))
    ->in('Feature');
