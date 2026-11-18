<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // CU6: límites de los endpoints públicos, independientes entre sí y por IP
        RateLimiter::for('portal-seguimiento', fn (Request $request) => Limit::perMinute(10)->by('seguimiento|'.$request->ip()));
        RateLimiter::for('validador', fn (Request $request) => Limit::perMinute(30)->by('validador|'.$request->ip()));
        RateLimiter::for('validador-archivo', fn (Request $request) => Limit::perMinute(10)->by('validador-archivo|'.$request->ip()));
    }
}
