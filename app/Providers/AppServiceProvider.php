<?php

namespace App\Providers;

use App\Access\AccessGate;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(AccessGate::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(function () {
            $rule = Password::min(12);

            return $this->app->environment(['production', 'staging']) ? $rule->uncompromised() : $rule;
        });

        $this->configureRememberDuration();
        $this->configureLoginLimiter();
    }

    /** Remember cookie lives 30 days (the framework default is 400 days). */
    private function configureRememberDuration(): void
    {
        Auth::resolved(function ($auth) {
            $auth->guard('web')->setRememberDuration(43200);
        });
    }

    private function configureLoginLimiter(): void
    {
        $respond = function (Request $request, array $headers) {
            event(new Lockout($request));

            $seconds = (int) ($headers['Retry-After'] ?? 60);

            return back()
                ->withErrors(['throttle' => (string) max(1, (int) ceil($seconds / 60))])
                ->onlyInput('email');
        };

        RateLimiter::for('login', function (Request $request) use ($respond) {
            $email = Str::lower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip())->response($respond),
                Limit::perMinutes(15, 10)->by('email:'.$email)->response($respond),
                Limit::perMinute(30)->by('ip:'.$request->ip())->response($respond),
            ];
        });
    }
}
