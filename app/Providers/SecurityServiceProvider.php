<?php

namespace App\Providers;

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Models\User;
use App\Security\SensitiveSession;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class SecurityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(SensitiveSession::class);
        // Created by plan 06-01; referenced by class constant only.
        $this->app->scoped(\App\Security\AppSettings::class);
        $this->app->scoped(\App\Security\SecondFactorPolicy::class);
    }

    public function boot(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            $id = $request->hasSession() ? $request->session()->get('login.id') : null;
            $key = $id !== null ? 'user:'.$id : 'ip:'.$request->ip();
            $respond = $this->lockoutResponse($id !== null ? (int) $id : null);

            return [
                Limit::perMinute(5)->by('2fa-1m:'.$key)->response($respond),
                Limit::perMinutes(15, 10)->by('2fa-15m:'.$key)->response($respond),
            ];
        });

        RateLimiter::for('reauth', function (Request $request) {
            $id = $request->user()?->id;
            $key = $id !== null ? 'user:'.$id : 'ip:'.$request->ip();
            $respond = $this->lockoutResponse($id);

            return [
                Limit::perMinute(5)->by('reauth-1m:'.$key)->response($respond),
                Limit::perHour(20)->by('reauth-1h:'.$key)->response($respond),
            ];
        });
    }

    /** Audited 429 for JSON callers, back() with errors.throttle (minutes) for the rest. */
    private function lockoutResponse(?int $userId): \Closure
    {
        return function (Request $request, array $headers) use ($userId) {
            $member = $userId !== null ? User::find($userId) : null;
            app(AuditLogger::class)->record(AuditEvent::Lockout, $member);

            $minutes = max(1, (int) ceil(((int) ($headers['Retry-After'] ?? 60)) / 60));

            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['error' => 'throttle', 'minutes' => $minutes], 429, $headers);
            }

            return back()->withErrors(['throttle' => (string) $minutes]);
        };
    }
}
