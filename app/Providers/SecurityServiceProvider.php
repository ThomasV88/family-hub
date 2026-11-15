<?php

namespace App\Providers;

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Models\User;
use App\Security\AppSettings;
use App\Security\SecondFactorPolicy;
use App\Security\SensitiveSession;
use App\Security\UserScopedTwoFactorProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use PragmaRX\Google2FA\Google2FA;

class SecurityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(SensitiveSession::class);
        // Created by plan 06-01; referenced by class constant only.
        $this->app->scoped(AppSettings::class);
        $this->app->scoped(SecondFactorPolicy::class);
        // Replay marker per member secret instead of Fortify's global per-code key (06-REVIEW WR-06).
        $this->app->singleton(
            TwoFactorAuthenticationProvider::class,
            fn ($app) => new UserScopedTwoFactorProvider(
                $app->make(Google2FA::class),
                $app->make(Repository::class),
            ),
        );
    }

    public function boot(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            $id = $request->hasSession() ? $request->session()->get('login.id') : null;
            $key = $id !== null ? 'user:'.$id : 'ip:'.$request->ip();
            $respond = $this->lockoutResponse('two-factor:'.$key, $id !== null ? (int) $id : null);

            return [
                Limit::perMinute(5)->by('2fa-1m:'.$key)->response($respond),
                Limit::perMinutes(15, 10)->by('2fa-15m:'.$key)->response($respond),
            ];
        });

        RateLimiter::for('reauth', function (Request $request) {
            $id = $request->user()?->id;
            $key = $id !== null ? 'user:'.$id : 'ip:'.$request->ip();
            $respond = $this->lockoutResponse('reauth:'.$key, $id);

            return [
                Limit::perMinute(5)->by('reauth-1m:'.$key)->response($respond),
                Limit::perHour(20)->by('reauth-1h:'.$key)->response($respond),
            ];
        });
    }

    /** Audited 429 for JSON callers, back() with errors.throttle (minutes) for the rest. */
    private function lockoutResponse(string $subject, ?int $userId): \Closure
    {
        return function (Request $request, array $headers) use ($subject, $userId) {
            $retryAfter = max(1, (int) ($headers['Retry-After'] ?? 60));

            // At most one lockout row per subject and lockout window (06-REVIEW WR-05): requests made while
            // already locked out must not fill the audit table.
            if (Cache::add('lockout-audited:'.$subject, 1, $retryAfter)) {
                $member = $userId !== null ? User::find($userId) : null;
                app(AuditLogger::class)->record(AuditEvent::Lockout, $member);
            }

            $minutes = max(1, (int) ceil($retryAfter / 60));

            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['error' => 'throttle', 'minutes' => $minutes], 429, $headers);
            }

            return back()->withErrors(['throttle' => (string) $minutes]);
        };
    }
}
