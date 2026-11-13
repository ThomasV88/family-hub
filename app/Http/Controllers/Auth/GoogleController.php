<?php

namespace App\Http\Controllers\Auth;

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Auth\GoogleLoginService;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use App\Security\ReauthMethods;
use App\Security\SafeIntended;
use App\Security\SensitiveSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class GoogleController extends Controller
{
    private const SESSION_KEY = 'google_invite_token';

    private const REAUTH_KEY = 'reauth.google';

    public function redirect(Request $request): Response
    {
        if (! InviteAcceptController::googleEnabled()) {
            return $this->failed();
        }

        $request->session()->forget(self::SESSION_KEY);
        $token = (string) $request->query('invite', '');
        if ($token !== '') {
            $invitation = Invitation::findByToken($token);
            if ($invitation === null || $invitation->state() !== 'valid') {
                return redirect('/invite/'.rawurlencode($token));
            }
            // Kept server-side; never forwarded to Google.
            $request->session()->put(self::SESSION_KEY, $token);
        }

        return Socialite::driver('google')->redirect();
    }

    /** "Sign in with Google again" for a Google-only member: 404 unless ReauthMethods::googleAvailable. */
    public function reauthRedirect(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        if (! ReauthMethods::googleAvailable($user) || ! InviteAcceptController::googleEnabled()) {
            abort(404);
        }

        $request->session()->put(self::REAUTH_KEY, ['user' => $user->id, 'at' => now()->getTimestamp()]);

        // [ASSUMED] Google honouring prompt/max_age is unverified; 06-13 records the observed behaviour with the owner.
        return Socialite::driver('google')->with(['prompt' => 'select_account', 'max_age' => 0])->redirect();
    }

    public function callback(Request $request, GoogleLoginService $google): RedirectResponse
    {
        if (Auth::check()) {
            return $this->reauthCallback($request);
        }

        $token = $request->session()->pull(self::SESSION_KEY);
        $invitation = is_string($token) && $token !== '' ? Invitation::findByToken($token) : null;

        if (! InviteAcceptController::googleEnabled() || (is_string($token) && $token !== '' && $invitation === null)) {
            return $this->failed();
        }

        try {
            $identity = Socialite::driver('google')->user();
            [$outcome, $user] = $google->resolve($identity, $invitation);
        } catch (Throwable) {
            return $this->failed();
        }
        // The provider access token is not read or kept; only the verified identity was used.
        unset($identity);

        if ($outcome === GoogleLoginService::MISMATCH && $token !== null) {
            return redirect('/invite/'.rawurlencode((string) $token))->with('status', GoogleLoginService::MISMATCH);
        }
        if ($user === null) {
            return redirect('/login')->with('status', $outcome);
        }

        if ($user->hasSecondFactor()) {
            // Not signed in yet: the Login event and login_success row fire only when the challenge passes.
            $request->session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => false,
                'login.at' => now()->getTimestamp(),
            ]);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user, false);
        $request->session()->regenerate();
        app(SensitiveSession::class)->confirm();

        $redirect = redirect(SafeIntended::pull($request));

        return $outcome === 'created'
            ? $redirect->with('toast', ['key' => 'invite.accept.welcome', 'params' => ['name' => $user->name]])
            : $redirect;
    }

    private function reauthCallback(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $flag = $request->session()->pull(self::REAUTH_KEY);
        if (! is_array($flag)) {
            return redirect('/');
        }

        $fresh = isset($flag['at'], $flag['user'])
            && (int) $flag['user'] === (int) $user->id
            && now()->getTimestamp() - (int) $flag['at'] >= 0
            && now()->getTimestamp() - (int) $flag['at'] <= 600;

        $ok = false;
        // Re-checked now: the flag turned off or a factor enrolled mid-flow lands on the failure path.
        if ($fresh && ReauthMethods::googleAvailable($user) && InviteAcceptController::googleEnabled()) {
            try {
                // Identity only: never resolve(), so nothing is linked or created here.
                $identity = Socialite::driver('google')->user();
                $ok = $user->google_id !== null && hash_equals((string) $user->google_id, (string) $identity->getId());
            } catch (Throwable) {
                $ok = false;
            }
            unset($identity);
        }

        $audit = app(AuditLogger::class);
        if (! $ok) {
            $audit->record(AuditEvent::SecondFactorFailed, $user, ['method' => 'google', 'context' => 'reauth']);

            return redirect('/confirm-access')->with('status', 'google_reauth_failed');
        }

        app(SensitiveSession::class)->confirm();
        $audit->record(AuditEvent::ReauthConfirmed, $user, ['method' => 'google']);

        return redirect(SafeIntended::pull($request), 303);
    }

    private function failed(): RedirectResponse
    {
        return redirect('/login')->with('status', GoogleLoginService::FAILED);
    }
}
