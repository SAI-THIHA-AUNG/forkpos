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
        // 5 attempts/minute per IP + identity. Stops PIN/password brute force;
        // a 4-digit PIN has only 10,000 combinations.
        RateLimiter::for('login', function (Request $request) {
            $who = strtolower((string) ($request->input('email') ?: 'pin:'.$request->input('user_id')));

            return Limit::perMinute(5)->by($request->ip().'|'.$who);
        });
    }
}
