<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Revoke means revoke": deletes session rows and rotates the remember token so a stolen
 * remember cookie dies with the session. Never writes audit rows; callers do.
 */
class SessionRevoker
{
    /** Sign the user out everywhere. Returns the number of session rows deleted. */
    public function revokeAll(User $user): int
    {
        return DB::transaction(function () use ($user): int {
            $deleted = DB::table('sessions')->where('user_id', $user->getKey())->delete();
            $this->rotateRememberToken($user);

            return $deleted;
        });
    }

    /** Sign out one session of this user. The row must match both id and user_id (no cross-user deletes). */
    public function revokeOne(User $user, string $sessionId): bool
    {
        return DB::transaction(function () use ($user, $sessionId): bool {
            $deleted = DB::table('sessions')
                ->where('id', $sessionId)
                ->where('user_id', $user->getKey())
                ->delete();
            $this->rotateRememberToken($user);

            return $deleted > 0;
        });
    }

    private function rotateRememberToken(User $user): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->save();
    }
}
