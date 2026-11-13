<?php

namespace App\Security;

/**
 * Server-session timestamps for the sensitive idle gate and the security confirm window.
 * The only place that reads or writes `sensitive.active_at` and `auth.password_confirmed_at`.
 */
class SensitiveSession
{
    public const ACTIVE_KEY = 'sensitive.active_at';

    public const CONFIRMED_KEY = 'auth.password_confirmed_at';

    /** Last sensitive-module activity is within the idle window. */
    public function fresh(): bool
    {
        return $this->within(self::ACTIVE_KEY, (int) config('security.idle_seconds'));
    }

    /** Record sensitive-module activity now. */
    public function touch(): void
    {
        $this->session()->put(self::ACTIVE_KEY, now()->getTimestamp());
    }

    /** A successful interactive proof of identity: refreshes both windows. */
    public function confirm(): void
    {
        $this->touch();
        $this->session()->put(self::CONFIRMED_KEY, now()->getTimestamp());
    }

    /** The last proof of identity is within the security confirm window. */
    public function recentlyConfirmed(): bool
    {
        return $this->within(self::CONFIRMED_KEY, (int) config('security.confirm_window_seconds'));
    }

    private function within(string $key, int $seconds): bool
    {
        $at = $this->session()->get($key);
        if (! is_int($at) && ! (is_string($at) && ctype_digit($at))) {
            return false;
        }

        $age = now()->getTimestamp() - (int) $at;

        return $age >= 0 && $age <= $seconds;
    }

    private function session(): \Illuminate\Contracts\Session\Session
    {
        return app('request')->session();
    }
}
