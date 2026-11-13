<?php

namespace App\Security;

use App\Models\User;

/** The single place the re-authentication methods shape is computed. */
class ReauthMethods
{
    /** @return array{password: bool, totp: bool, passkey: bool, google: bool} */
    public static function for(User $user): array
    {
        return [
            'password' => (bool) $user->password_set,
            'totp' => self::hasTotp($user),
            'passkey' => (bool) config('security.passkeys_enabled') && $user->passkeys()->exists(),
            'google' => self::googleAvailable($user),
        ];
    }

    /** Offered only behind the flag, and only to a Google-linked member with no stronger method. */
    public static function googleAvailable(User $user): bool
    {
        return (bool) config('security.google_reauth')
            && $user->google_id !== null
            && ! $user->password_set
            && ! $user->hasSecondFactor();
    }

    public static function hasTotp(User $user): bool
    {
        return $user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null;
    }
}
