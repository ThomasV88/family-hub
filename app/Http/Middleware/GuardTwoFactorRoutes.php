<?php

namespace App\Http\Middleware;

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Models\User;
use App\Security\SafeIntended;
use App\Security\SensitiveSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hardening for Fortify's two-factor routes, switched on the route name at runtime (Fortify routes cannot
 * carry middleware from config). Recency for enable/confirm/regenerate comes from the `recent` access rule
 * (EnsureRouteDeclaresAccess runs first); this class adds the state-dependent rules.
 */
class GuardTwoFactorRoutes
{
    /** How long an unconfirmed authenticator secret stays readable and confirmable. */
    public const PENDING_TTL_MINUTES = 15;

    private const PENDING_KEYS = ['login.id', 'login.remember', 'login.at'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $name = (string) $request->route()?->getName();

        if ($name === 'login' && $request->isMethod('GET')) {
            // "Back to sign in" abandons the pending login.
            $request->session()->forget(self::PENDING_KEYS);

            return $next($request);
        }

        if ($name === 'two-factor.login' || $name === 'two-factor.login.store' || str_starts_with($name, 'two-factor.passkey.')) {
            if ($this->challengeExpired($request)) {
                $request->session()->forget(self::PENDING_KEYS);

                return $this->wantsJson($request)
                    ? response()->json(['error' => 'challenge_expired'], 410)
                    : redirect('/login')->with('status', 'challenge_expired');
            }

            if ($name === 'two-factor.login.store') {
                $this->rejectArrays($request, ['code', 'recovery_code']);
                $this->stripUnusableInput($request);
            }

            return $next($request);
        }

        $user = $request->user();
        if ($user === null) {
            return $next($request); // the access rule has already refused; unreachable for named routes below
        }

        return match ($name) {
            'two-factor.enable' => $this->confirmed($user)
                ? response()->json(['error' => 'already_enabled'], 409)
                : $next($request),
            'two-factor.qr-code', 'two-factor.secret-key' => $this->pending($user) ? $next($request) : abort(404),
            'two-factor.confirm' => $this->pending($user) ? $this->confirm($request, $next, $user) : abort(404),
            'two-factor.disable' => $this->disable($request, $next, $user),
            'two-factor.regenerate-recovery-codes' => $this->confirmed($user) ? $next($request) : abort(404),
            default => $next($request),
        };
    }

    private function confirm(Request $request, Closure $next, User $user): Response
    {
        $this->rejectArrays($request, ['code']);

        $key = 'tfa-confirm:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->audit->record(AuditEvent::Lockout, $user);
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

            return $this->wantsJson($request)
                ? response()->json(['error' => 'throttle', 'minutes' => $minutes], 429)
                : back()->withErrors(['throttle' => (string) $minutes]);
        }
        RateLimiter::hit($key, 60);

        return $next($request);
    }

    private function disable(Request $request, Closure $next, User $user): Response
    {
        $wasConfirmed = $this->confirmed($user);

        // Cancelling an unconfirmed setup never needs a confirmation; removing a live factor does.
        if ($wasConfirmed && ! app(SensitiveSession::class)->recentlyConfirmed()) {
            if ($this->wantsJson($request)) {
                return response()->json(['error' => 'confirm_required'], 423);
            }
            SafeIntended::remember($request, (string) url()->previous());

            return redirect(url('/confirm-access'));
        }

        // One transaction (06-REVIEW WR-04): if the audit row cannot be written, Fortify's change is rolled back,
        // so a failing audit write never leaves a disabled factor behind a 500 (fail closed, no state change).
        return DB::transaction(function () use ($request, $next, $user, $wasConfirmed) {
            $response = $next($request);

            if ($wasConfirmed && $response->getStatusCode() < 400) {
                $this->audit->record(AuditEvent::TwoFactorDisabled, $user);
            }

            return $response;
        });
    }

    private function challengeExpired(Request $request): bool
    {
        if (! $request->session()->has('login.id')) {
            return false;
        }

        $at = $request->session()->get('login.at');
        if (! is_int($at) && ! (is_string($at) && ctype_digit($at))) {
            return true;
        }

        return now()->getTimestamp() - (int) $at > (int) config('security.challenge_ttl_seconds');
    }

    /** A passkey-only member (or one with a pending secret) has no code to give: Fortify would decrypt null. */
    private function stripUnusableInput(Request $request): void
    {
        $user = User::find($request->session()->get('login.id'));

        if ($user !== null && ! $this->confirmed($user)) {
            $request->merge(['code' => null, 'recovery_code' => null]);
        }
    }

    /** @param list<string> $fields */
    private function rejectArrays(Request $request, array $fields): void
    {
        foreach ($fields as $field) {
            abort_if($request->has($field) && ! is_string($request->input($field)) && $request->input($field) !== null, 422);
        }
    }

    private function confirmed(User $user): bool
    {
        return $user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null;
    }

    /**
     * A started but unconfirmed TOTP secret. It lives for PENDING_TTL_MINUTES from the moment it was written
     * (Fortify saves the row on enable, so updated_at is that moment): an expired seed is wiped on read and no
     * longer served or confirmable (06-REVIEW WR-02).
     */
    private function pending(User $user): bool
    {
        if ($user->two_factor_secret === null || $user->two_factor_confirmed_at !== null) {
            return false;
        }

        if ($user->updated_at === null || $user->updated_at->lt(now()->subMinutes(self::PENDING_TTL_MINUTES))) {
            $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null])->saveQuietly();

            return false;
        }

        return true;
    }

    private function wantsJson(Request $request): bool
    {
        return $request->expectsJson() && ! $request->header('X-Inertia');
    }
}
