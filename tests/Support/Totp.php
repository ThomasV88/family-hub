<?php

namespace Tests\Support;

use App\Models\User;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

/** TOTP helpers for tests, built on Fortify's own actions. */
class Totp
{
    /** Enables two-factor for the user (and confirms it unless told otherwise); returns the plain secret. */
    public static function enable(User $user, bool $confirm = true): string
    {
        app(EnableTwoFactorAuthentication::class)($user);
        $user->refresh();
        $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);

        if ($confirm) {
            app(ConfirmTwoFactorAuthentication::class)($user, self::code($secret));
            $user->refresh();
        }

        return $secret;
    }

    /** Fortify rejects a replayed code, so use a different $offset for a second verify in the same 30s window. */
    public static function code(string $secret, int $offset = 0): string
    {
        return app(Google2FA::class)->oathTotp($secret, intdiv(time(), 30) + $offset);
    }

    /** @return list<string> */
    public static function recoveryCodes(User $user): array
    {
        return $user->refresh()->recoveryCodes();
    }
}
