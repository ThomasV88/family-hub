<?php

namespace App\Security;

use Illuminate\Support\Facades\DB;

/**
 * One-time backfill for users.password_set. Google invites store a random 64-character password nobody
 * knows, so an existing account with a google_id is marked "no known password" unless the append-only
 * audit log shows it later linked Google to a password account (google_linked) or completed a reset
 * (password_reset). Uncertain cases default to false, which only hides the password option at re-auth.
 */
class PasswordSetBackfill
{
    /** @return int number of rows set to false */
    public static function run(): int
    {
        return DB::affectingStatement(<<<'SQL'
UPDATE users SET password_set = false
WHERE password_set = true
  AND google_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM audit_logs a
      WHERE a.event IN ('google_linked', 'password_reset')
        AND (a.actor_id = users.id OR a.target_user_id = users.id)
  )
SQL);
    }
}
