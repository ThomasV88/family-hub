<?php

namespace App\Http\Controllers\Auth;

use App\Auth\GoogleLoginService;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class GoogleController extends Controller
{
    private const SESSION_KEY = 'google_invite_token';

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

    public function callback(Request $request, GoogleLoginService $google): RedirectResponse
    {
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

        Auth::login($user, false);
        $request->session()->regenerate();

        $redirect = redirect('/');

        return $outcome === 'created'
            ? $redirect->with('toast', ['key' => 'invite.accept.welcome', 'params' => ['name' => $user->name]])
            : $redirect;
    }

    private function failed(): RedirectResponse
    {
        return redirect('/login')->with('status', GoogleLoginService::FAILED);
    }
}
