<?php

namespace App\Security;

use Laravel\Fortify\TwoFactorAuthenticationProvider;

/**
 * Fortify's provider remembers a used code under `fortify.2fa_codes.md5(code)`: one global key, so two members who
 * happen to enter the same six digits inside the window reject each other (and any code can be probed across
 * accounts). This keys the replay marker by the member's own secret instead (06-REVIEW WR-06). The verification
 * itself is Fortify's, unchanged.
 */
class UserScopedTwoFactorProvider extends TwoFactorAuthenticationProvider
{
    /** The replay-cache key for this secret and code (the secret is hashed; it is never stored in the cache key). */
    public static function replayKey(string $secret, string $code): string
    {
        return 'fortify.2fa_codes.'.hash('sha256', $secret).'.'.md5($code);
    }

    public function verify($secret, $code)
    {
        if (is_int($customWindow = config('fortify-options.two-factor-authentication.window'))) {
            $this->engine->setWindow($customWindow);
        }

        $key = self::replayKey((string) $secret, (string) $code);

        $timestamp = $this->engine->verifyKeyNewer($secret, $code, optional($this->cache)->get($key));

        if ($timestamp === false) {
            return false;
        }

        if ($timestamp === true) {
            $timestamp = $this->engine->getTimestamp();
        }

        optional($this->cache)->put($key, $timestamp, ($this->engine->getWindow() ?: 1) * 60);

        return true;
    }
}
