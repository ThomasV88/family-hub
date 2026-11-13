<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
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
     *
     * Fortify runs headless with no features in plan 02-01. Plan 02 registers the login
     * view, authenticateUsing and the login rate limiter; plan 04 registers the reset views.
     */
    public function boot(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
    }
}
