<?php

namespace App\Auth;

use App\Models\User;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;

/**
 * Challenges every member who has ANY second factor (confirmed authenticator app or a passkey) after the
 * password. Fortify's own check only knows the TOTP secret, so a passkey-only member would skip the challenge.
 * A pending (unconfirmed) TOTP secret is not a factor.
 */
class RedirectIfSecondFactor extends RedirectIfTwoFactorAuthenticatable
{
    public function handle($request, $next)
    {
        // Keeps Fortify's credential check, the dummy-hash timing path and the Failed event.
        $user = $this->validateCredentials($request);

        if ($user instanceof User && $user->hasSecondFactor()) {
            return $this->twoFactorChallengeResponse($request, $user);
        }

        return $next($request);
    }

    protected function twoFactorChallengeResponse($request, $user)
    {
        // A fresh pending login: nothing from an earlier attempt may survive.
        $request->session()->put([
            'login.id' => $user->getKey(),
            'login.remember' => $request->boolean('remember'),
            'login.at' => now()->getTimestamp(),
        ]);

        TwoFactorAuthenticationChallenged::dispatch($user);

        return $request->wantsJson()
            ? response()->json(['two_factor' => true])
            : redirect()->route('two-factor.login');
    }
}
