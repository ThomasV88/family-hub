<?php

namespace App\Actions\Fortify;

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Auth\SessionRevoker;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ], [
            'password.required' => 'invite.accept.error.password_short',
            'password.min' => 'invite.accept.error.password_short',
            'password.confirmed' => 'invite.accept.error.password_mismatch',
            'password.uncompromised' => 'auth.error.password_compromised',
        ])->validate();

        // One transaction: password change, session revoke and audit row commit together or not at all.
        DB::transaction(function () use ($user, $input): void {
            $user->forceFill([
                'password' => Hash::make($input['password']),
            ])->save();

            // Revoke means revoke: other devices are signed out.
            app(SessionRevoker::class)->revokeAll($user);
            app(AuditLogger::class)->record(AuditEvent::PasswordReset, $user);
        });
    }
}
