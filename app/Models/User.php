<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Contracts\FailedTwoFactorLoginResponse;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passkeys\Contracts\PasskeyUser;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'google_id', 'birth_date', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'])]
class User extends Authenticatable implements HasLocalePreference, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'birth_date' => 'date',
            'two_factor_confirmed_at' => 'datetime',
            'password_set' => 'boolean',
        ];
    }

    /** A confirmed authenticator app or at least one passkey (a pending TOTP secret does not count). */
    public function hasSecondFactor(): bool
    {
        // Checked explicitly (secret AND confirmation) so it holds whether or not Fortify's confirm option is on.
        return ($this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null) || $this->passkeys()->exists();
    }

    /**
     * A used recovery code is removed, not swapped for a new unseen one (Fortify's default), so the member
     * is told the truthful number of codes they still hold. Regeneration creates a full set again.
     */
    public function replaceRecoveryCode($code)
    {
        // Consumed under a row lock, re-reading the stored codes (06-REVIEW WR-06): of two concurrent requests with
        // the same code, the second finds it already gone and fails like any wrong code instead of also logging in.
        DB::transaction(function () use ($code) {
            $stored = static::query()->whereKey($this->getKey())->lockForUpdate()->value('two_factor_recovery_codes');
            $codes = $stored !== null ? (array) json_decode(Fortify::currentEncrypter()->decrypt($stored), true) : [];

            $present = false;
            $remaining = [];
            foreach ($codes as $c) {
                if (hash_equals((string) $c, (string) $code)) {
                    $present = true;

                    continue;
                }
                $remaining[] = $c;
            }

            if (! $present) {
                throw new HttpResponseException(app(FailedTwoFactorLoginResponse::class)->toResponse(request()));
            }

            $this->forceFill([
                'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode($remaining)),
            ])->save();
        });

        RecoveryCodeReplaced::dispatch($this, $code);
    }

    public function preferredLocale(): ?string
    {
        return in_array($this->locale, ['sk', 'pl', 'en'], true) ? $this->locale : null;
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification((string) $token));
    }

    public function modulePermissions(): HasMany
    {
        return $this->hasMany(ModulePermission::class);
    }
}
