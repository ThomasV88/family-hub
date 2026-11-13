<?php

namespace App\Security;

use App\Models\User;

/** The single place the re-authentication methods shape is computed. */
class ReauthMethods
{
    /** @return array{password: bool, totp: bool, passkey: bool} */
    public static function for(User $user): array
    {
        return [
            // A member who has a second factor never confirms with the password alone (06-REVIEW CR-03/WR-01):
            // the password is offered only when there is no authenticator code or passkey to ask for instead.
            'password' => self::passwordAllowed($user),
            'totp' => self::hasTotp($user),
            'passkey' => (bool) config('security.passkeys_enabled') && $user->passkeys()->exists(),
        ];
    }

    /** The password may open the sensitive and security windows only for a member with no second factor. */
    public static function passwordAllowed(User $user): bool
    {
        return (bool) $user->password_set && ! $user->hasSecondFactor();
    }

    public static function hasTotp(User $user): bool
    {
        return $user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null;
    }
}
