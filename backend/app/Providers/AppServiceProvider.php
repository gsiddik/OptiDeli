<?php

namespace App\Providers;

use App\Domain\Dispatch\FleetAvailability;
use App\Domain\Dispatch\LocalFleetAvailability;
use App\Domain\Dispatch\OptiFleetAvailability;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FleetAvailability::class, fn ($app) => config('optideli.fleet.driver') === 'optifleet'
            ? $app->make(OptiFleetAvailability::class)
            : $app->make(LocalFleetAvailability::class));
    }

    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $r) => [Limit::perMinute(5)->by(hash('sha256', strtolower((string) $r->input('email')).'|'.$r->ip())), Limit::perMinute(30)->by($r->ip())]);
    }
}
