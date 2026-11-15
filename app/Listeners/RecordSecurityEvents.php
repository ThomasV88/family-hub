<?php

namespace App\Listeners;

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Models\User;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;

/**
 * Turns Fortify and passkey events into content-free audit rows. Never passes passkey names, codes,
 * credential ids or secrets. Disable is audited by the controller (it knows whether the factor was confirmed).
 */
class RecordSecurityEvents
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handleTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->audit->record(AuditEvent::TwoFactorEnabled, $this->user($event->user));
    }

    public function handleRecoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        $user = $this->user($event->user);

        // Codes created while setting up (not yet confirmed) are not a regeneration.
        if ($user?->two_factor_confirmed_at === null) {
            return;
        }

        $this->audit->record(AuditEvent::RecoveryCodesRegenerated, $user);
    }

    public function handleRecoveryCodeReplaced(RecoveryCodeReplaced $event): void
    {
        $this->audit->record(AuditEvent::RecoveryCodeUsed, $this->user($event->user));
    }

    public function handleTwoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        $method = request()->filled('recovery_code') ? 'recovery' : 'totp';

        $this->audit->record(AuditEvent::SecondFactorFailed, $this->user($event->user), ['method' => $method, 'context' => 'login']);
    }

    public function handlePasskeyRegistered(PasskeyRegistered $event): void
    {
        $this->audit->record(AuditEvent::PasskeyAdded, $this->user($event->user));
    }

    public function handlePasskeyDeleted(PasskeyDeleted $event): void
    {
        $this->audit->record(AuditEvent::PasskeyRemoved, $this->user($event->user));
    }

    private function user(mixed $user): ?User
    {
        return $user instanceof User ? $user : null;
    }
}
