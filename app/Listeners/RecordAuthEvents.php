<?php

namespace App\Listeners;

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Models\User;
use App\Support\RequestInput;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Str;

/** Account events (password_reset is audited inside ResetUserPassword's transaction). Never stores typed emails or passwords: only the id of a matching user. */
class RecordAuthEvents
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handleLogin(Login $event): void
    {
        if ($event->remember) {
            session(['auth_remember' => true]);
        }

        $this->audit->record(AuditEvent::LoginSuccess, $event->user instanceof User ? $event->user : null);
    }

    public function handleFailed(Failed $event): void
    {
        // Fortify's custom authenticator fires Failed without a user, so resolve the actor from the typed
        // email. Only the id is kept; the typed email and password are never stored.
        $user = $event->user instanceof User ? $event->user : $this->findByEmail(is_string($event->credentials['email'] ?? null) ? $event->credentials['email'] : '');

        $this->audit->record(AuditEvent::LoginFailed, $user);
    }

    public function handleLockout(Lockout $event): void
    {
        $this->audit->record(AuditEvent::Lockout, $this->findByEmail(RequestInput::string($event->request, 'email')));
    }

    private function findByEmail(string $email): ?User
    {
        $email = Str::lower(trim($email));

        return $email !== '' ? User::whereRaw('lower(email) = ?', [$email])->first() : null;
    }
}
