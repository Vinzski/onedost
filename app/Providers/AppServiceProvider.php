<?php

namespace App\Providers;

use App\Enums\ClaimKind;
use App\Models\Liquidation;
use App\Services\Travel\Destinations;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Reads the place list once per request.
        $this->app->singleton(Destinations::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Resources reach Inertia pages as plain objects. Paginated
        // collections still arrive as { data, links, meta }.
        JsonResource::withoutWrapping();

        // "/liquidations/{id}" only finds liquidations and "/reimbursements/{id}"
        // only reimbursements — the two kinds share one table.
        Route::bind('liquidation', fn (string $value, RoutingRoute $route) => Liquidation::query()
            ->ofKind(ClaimKind::fromRoute($route))
            ->findOrFail($value));

        if ($this->app->isProduction()) {
            // Live on HTTPS, every link and redirect says https — even if the
            // host hands the request on to PHP as plain http.
            if (str_starts_with((string) config('app.url'), 'https://')) {
                URL::forceScheme('https');
            }
            // The whole database is one file: no migrate:fresh, db:wipe or
            // migrate:reset by mistake on the live copy.
            DB::prohibitDestructiveCommands();
        }

        // `php artisan dev` shows logs through pail only where pcntl exists,
        // which PHP on Windows doesn't have: give it log:tail there instead.
        if (! function_exists('pcntl_fork')) {
            DevCommands::artisan('log:tail', 'logs');
        }
    }
}
