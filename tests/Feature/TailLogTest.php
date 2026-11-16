<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->logs = sys_get_temp_dir().'/onedost-log-tail-'.uniqid();
    File::ensureDirectoryExists("{$this->logs}/logs");
    $this->app->useStoragePath($this->logs);
});

afterEach(fn () => File::deleteDirectory($this->logs));

it('shows recent log entries one line each, without the stack traces', function () {
    File::put("{$this->logs}/logs/laravel.log", implode("\n", [
        '[2026-10-27 08:00:00] local.INFO: Booking request saved',
        '[2026-10-27 08:01:00] local.ERROR: Connection to SMTP refused {"exception":"[object] (RuntimeException(code: 0))"}',
        '[stacktrace]',
        '#0 C:\\Herd\\OneDOST\\vendor\\symfony\\mailer\\Transport.php(42)',
        '"}',
        '[2026-10-27 08:02:00] local.WARNING: Queue is slow',
    ])."\n");

    $this->artisan('log:tail', ['--recent' => 2, '--once' => true])
        ->expectsOutputToContain('local.ERROR: Connection to SMTP refused')
        ->expectsOutputToContain('local.WARNING: Queue is slow')
        ->doesntExpectOutputToContain('local.INFO: Cheque emailed')
        ->doesntExpectOutputToContain('#0 C:')
        ->assertSuccessful();
});

it('starts quiet: old entries stay out unless asked for', function () {
    File::put("{$this->logs}/logs/laravel.log", "[2026-10-27 08:00:00] local.ERROR: Something old\n");

    $this->artisan('log:tail', ['--once' => true])
        ->doesntExpectOutputToContain('Something old')
        ->assertSuccessful();
});

it('copes with no log file yet', function () {
    $this->artisan('log:tail', ['--recent' => 5, '--once' => true])->assertSuccessful();
});
