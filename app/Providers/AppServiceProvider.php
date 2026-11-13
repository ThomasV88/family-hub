<?php

namespace App\Providers;

use App\Models\Task;
use App\Observers\TaskReminderObserver;
use App\Recurrence\RecurrenceEngine;
use App\Recurrence\RRuleRecurrenceEngine;
use App\Support\RequestInput;
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
        $this->app->singleton(RecurrenceEngine::class, RRuleRecurrenceEngine::class);
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

        Task::observe(TaskReminderObserver::class);

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
            $email = RequestInput::email($request);

            // The strict limits are keyed on email AND ip, so a stranger hammering a known address from
            // elsewhere cannot lock the real owner out. Only a much higher cross-IP per-email ceiling
            // remains (distributed guessing); hitting it fires Lockout, which is audited.
            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip())->response($respond),
                Limit::perMinutes(15, 10)->by('email-ip-15m:'.$email.'|'.$request->ip())->response($respond),
                Limit::perHour(50)->by('email:'.$email)->response($respond),
                Limit::perMinute(30)->by('ip:'.$request->ip())->response($respond),
            ];
        });
    }
}
