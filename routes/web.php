<?php

use App\Enums\ClaimKind;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\BookingRequest\BookingRequestController;
use App\Http\Controllers\BookingRequest\BookingRequestDocumentController;
use App\Http\Controllers\CashAdvance\CashAdvanceController;
use App\Http\Controllers\CashAdvance\CashAdvanceDocumentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Dtr\DtrController;
use App\Http\Controllers\Dtr\DtrImportController;
use App\Http\Controllers\Dtr\DtrNoteController;
use App\Http\Controllers\LeaveApplication\LeaveApplicationController;
use App\Http\Controllers\LeaveApplication\LeaveApplicationDocumentController;
use App\Http\Controllers\Liquidation\LiquidationController;
use App\Http\Controllers\Liquidation\LiquidationDocumentController;
use App\Http\Controllers\Liquidation\LiquidationPhotoController;
use App\Services\CashAdvance\CashAdvanceDocuments;
use App\Services\Liquidation\LiquidationDocuments;
use Illuminate\Support\Facades\Route;

// Signing in. There is no sign-up: the login is made with `php artisan app:user`.
Route::middleware('guest')->controller(LoginController::class)->group(function () {
    Route::get('/login', 'create')->name('login');
    Route::post('/login', 'store')->name('login.store');
});
Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

// Everything else is behind the login.
Route::middleware('auth')->group(function () {
    Route::get('/', fn () => redirect()->route('dashboard'));
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Everything saved, in one download: the database and the uploaded photos.
    Route::get('/backup', [BackupController::class, 'show'])->name('backup');

    // Airline Booking Request (to the Supply Unit)
    Route::prefix('booking-requests')->name('booking-requests.')->group(function () {
        Route::controller(BookingRequestController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/create', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::get('/{bookingRequest}/edit', 'edit')->name('edit');
            Route::put('/{bookingRequest}', 'update')->name('update');
            Route::delete('/{bookingRequest}', 'destroy')->name('destroy');
        });

        Route::get('/{bookingRequest}/download', [BookingRequestDocumentController::class, 'show'])->name('download');
    });

    // Application for Leave (CSC Form No. 6)
    Route::prefix('leave-applications')->name('leave-applications.')->group(function () {
        Route::controller(LeaveApplicationController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/create', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::get('/{leaveApplication}/edit', 'edit')->name('edit');
            Route::put('/{leaveApplication}', 'update')->name('update');
            Route::delete('/{leaveApplication}', 'destroy')->name('destroy');
        });

        Route::get('/{leaveApplication}/download', [LeaveApplicationDocumentController::class, 'show'])->name('download');
    });

    // Daily Time Record: imported DTRs, and why their blanks are blank
    Route::prefix('dtr')->name('dtr.')->group(function () {
        Route::get('/', [DtrController::class, 'index'])->name('index');
        Route::get('/print', [DtrController::class, 'print'])->name('print');
        Route::post('/import', [DtrImportController::class, 'store'])->name('import');

        Route::controller(DtrNoteController::class)->group(function () {
            Route::post('/notes', 'store')->name('notes.store');
            Route::put('/notes/{dtrNote}', 'update')->name('notes.update');
            Route::delete('/notes/{dtrNote}', 'destroy')->name('notes.destroy');
        });
    });

    // Cash Advance (before travel)
    Route::prefix('cash-advances')->name('cash-advances.')->group(function () {
        Route::controller(CashAdvanceController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/create', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::get('/{cashAdvance}/edit', 'edit')->name('edit');
            Route::put('/{cashAdvance}', 'update')->name('update');
            Route::delete('/{cashAdvance}', 'destroy')->name('destroy');
            Route::post('/{cashAdvance}/travelers', 'addTravelers')->name('travelers.store');
            Route::post('/{cashAdvance}/liquidate', 'liquidate')->name('liquidate');
        });

        Route::get('/{cashAdvance}/download/{document}', [CashAdvanceDocumentController::class, 'show'])
            ->whereIn('document', CashAdvanceDocuments::TYPES)
            ->name('download');
    });

    // Travel Liquidation and Reimbursement (after travel): the same claim, two kinds.
    // "/liquidations/…" and "/reimbursements/…" only ever bind their own kind
    // (see the `liquidation` binding in AppServiceProvider).
    foreach (ClaimKind::cases() as $kind) {
        Route::prefix($kind->routePrefix())->name($kind->routePrefix().'.')->group(function () {
            Route::controller(LiquidationController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('/create', 'create')->name('create');
                Route::post('/', 'store')->name('store');
                Route::get('/{liquidation}/edit', 'edit')->name('edit');
                Route::put('/{liquidation}', 'update')->name('update');
                Route::delete('/{liquidation}', 'destroy')->name('destroy');
                Route::post('/{liquidation}/travelers', 'addTravelers')->name('travelers.store');
            });

            Route::get('/{liquidation}/download/{document}', [LiquidationDocumentController::class, 'show'])
                ->whereIn('document', LiquidationDocuments::TYPES)
                ->name('download');

            Route::controller(LiquidationPhotoController::class)->scopeBindings()->group(function () {
                Route::post('/{liquidation}/photos', 'store')->name('photos.store');
                Route::get('/{liquidation}/photos/{photo}', 'show')->name('photos.show');
                Route::delete('/{liquidation}/photos/{photo}', 'destroy')->name('photos.destroy');
            });
        });
    }
});
