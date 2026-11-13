<?php

namespace App\Auth;

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\GoogleLinkedNotification;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Decides what a Google identity may do. Accounts are only ever created from a valid invitation,
 * and only when Google vouches for the invited address. Tokens are never read or stored.
 */
class GoogleLoginService
{
    public const FAILED = 'google_failed';

    public const NOT_INVITED = 'google_not_invited';

    public const MISMATCH = 'google_mismatch';

    public function __construct(private readonly InviteService $invites, private readonly AuditLogger $audit) {}

    /**
     * @return array{0: string, 1: ?User} outcome ('login' | 'created' | google_failed | google_not_invited | google_mismatch) and the user
     */
    public function resolve(SocialiteUser $google, ?Invitation $invite): array
    {
        $raw = (array) ($google->user ?? []);
        $verified = filter_var($raw['email_verified'] ?? $raw['verified_email'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $sub = (string) $google->getId();
        $email = mb_strtolower(trim((string) $google->getEmail()));

        if (! $verified || $sub === '' || $email === '') {
            return [self::FAILED, null];
        }

        if ($invite !== null) {
            if ($invite->state() !== 'valid') {
                return [self::FAILED, null];
            }
            if (mb_strtolower($invite->email) !== $email) {
                return [self::MISMATCH, null];
            }
            if (User::query()->where('google_id', $sub)->exists()) {
                return [self::FAILED, null];
            }
            try {
                $name = trim((string) $google->getName()) !== '' ? mb_substr(trim((string) $google->getName()), 0, 120) : strstr($email, '@', true);

                return ['created', $this->invites->accept($invite, ['name' => $name], $sub)];
            } catch (InvalidInviteException) {
                return [self::FAILED, null];
            }
        }

        // The Google subject is the stable identity (Google accounts can change their email).
        $user = User::query()->where('google_id', $sub)->first();
        if ($user !== null) {
            return ['login', $user];
        }

        $byEmail = User::query()->whereRaw('lower(email) = ?', [$email])->first();
        if ($byEmail === null) {
            return [self::NOT_INVITED, null];
        }
        if ($byEmail->google_id !== null) {
            return [self::FAILED, null];
        }

        DB::transaction(function () use ($byEmail, $sub): void {
            $byEmail->forceFill(['google_id' => $sub])->save();
            $this->audit->record(AuditEvent::GoogleLinked, $byEmail);
        });

        // Linking by verified email is the agreed rule; the owner is told so a takeover cannot go unnoticed.
        $byEmail->notify(new GoogleLinkedNotification);

        return ['login', $byEmail];
    }
}
