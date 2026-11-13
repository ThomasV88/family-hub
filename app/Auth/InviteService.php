<?php

namespace App\Auth;

use App\Access\Preset;
use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InviteNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** The plain token is returned to the caller once and is never stored or logged; only its SHA-256 hash is. */
class InviteService
{
    public const TTL_DAYS = 7;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{invitation: Invitation, token: string} */
    public function create(User $admin, string $email, Preset $preset, string $locale): array
    {
        $email = mb_strtolower(trim($email));

        if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => 'invite.dialog.error.exists']);
        }

        $token = Str::random(40);

        $invitation = DB::transaction(function () use ($admin, $email, $preset, $locale, $token) {
            $open = Invitation::query()->whereRaw('lower(email) = ?', [$email])
                ->whereNull('accepted_at')->whereNull('revoked_at')->lockForUpdate()->get();
            foreach ($open as $old) {
                $old->forceFill(['revoked_at' => now()])->save();
                $this->audit->record(AuditEvent::InviteRevoked, $admin, ['email' => $old->email, 'preset' => $old->preset->value]);
            }

            $invitation = Invitation::create([
                'email' => $email,
                'token_hash' => hash('sha256', $token),
                'preset' => $preset->value,
                'locale' => $locale,
                'invited_by' => $admin->id,
                'expires_at' => now()->addDays(self::TTL_DAYS),
            ]);
            $this->audit->record(AuditEvent::InviteCreated, $admin, ['email' => $email, 'preset' => $preset->value]);

            return $invitation;
        });

        $this->queueMail($invitation, $admin, $token);

        return ['invitation' => $invitation, 'token' => $token];
    }

    /** New token, fresh expiry, new mail. Returns null when the invitation is no longer open. */
    public function resend(User $admin, Invitation $invitation): ?string
    {
        $token = $this->rotate($admin, $invitation, clearMailFailed: true);
        if ($token !== null) {
            $this->queueMail($invitation->refresh(), $admin, $token);
        }

        return $token;
    }

    /** New token and expiry without a mail (Copy link). Returns null when the invitation is no longer open. */
    public function rotateLink(User $admin, Invitation $invitation): ?string
    {
        return $this->rotate($admin, $invitation, clearMailFailed: false);
    }

    public function revoke(User $admin, Invitation $invitation): bool
    {
        return DB::transaction(function () use ($admin, $invitation) {
            $locked = Invitation::query()->lockForUpdate()->find($invitation->id);
            if ($locked === null || ! $locked->isOpen()) {
                return false;
            }
            $locked->forceFill(['revoked_at' => now()])->save();
            $this->audit->record(AuditEvent::InviteRevoked, $admin, ['email' => $locked->email, 'preset' => $locked->preset->value]);

            return true;
        });
    }

    private function rotate(User $admin, Invitation $invitation, bool $clearMailFailed): ?string
    {
        $token = Str::random(40);

        $done = DB::transaction(function () use ($admin, $invitation, $token, $clearMailFailed) {
            $locked = Invitation::query()->lockForUpdate()->find($invitation->id);
            if ($locked === null || ! $locked->isOpen()) {
                return false;
            }
            $attributes = [
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(self::TTL_DAYS),
            ];
            if ($clearMailFailed) {
                $attributes['mail_failed_at'] = null;
            }
            $locked->forceFill($attributes)->save();
            $this->audit->record(AuditEvent::InviteResent, $admin, ['email' => $locked->email, 'preset' => $locked->preset->value]);

            return true;
        });

        return $done ? $token : null;
    }

    private function queueMail(Invitation $invitation, User $admin, string $token): void
    {
        Notification::route('mail', $invitation->email)
            ->notify(new InviteNotification($invitation->id, $admin->name, $invitation->locale, $token));
    }

    /**
     * Turns an invitation into an account. Row lock plus state re-check make the link single-use under races.
     *
     * @param  array{name: string, password?: string}  $attrs
     *
     * @throws InvalidInviteException when the invitation is no longer valid or the email already has an account
     */
    public function accept(Invitation $invitation, array $attrs, ?string $googleId = null): User
    {
        return DB::transaction(function () use ($invitation, $attrs, $googleId) {
            $locked = Invitation::query()->lockForUpdate()->find($invitation->id);
            if ($locked === null || $locked->state() !== 'valid') {
                throw new InvalidInviteException;
            }
            if (User::query()->whereRaw('lower(email) = ?', [mb_strtolower($locked->email)])->exists()) {
                throw new InvalidInviteException;
            }

            $user = new User;
            $user->forceFill([
                'name' => $attrs['name'],
                'email' => mb_strtolower($locked->email),
                'password' => Hash::make($googleId !== null ? Str::random(64) : (string) ($attrs['password'] ?? Str::random(64))),
                'locale' => $locked->locale,
                'email_verified_at' => now(),
                'google_id' => $googleId,
                'is_admin' => false,
            ])->save();

            foreach ($locked->preset->grants() as $module => $level) {
                $user->modulePermissions()->updateOrCreate(['module' => $module], ['level' => $level]);
            }

            $locked->forceFill(['accepted_at' => now(), 'accepted_user_id' => $user->id])->save();
            $this->audit->record(AuditEvent::InviteAccepted, $user, ['email' => $locked->email, 'preset' => $locked->preset->value]);

            return $user;
        });
    }

    public static function link(string $token): string
    {
        return url('/invite/'.$token);
    }
}
