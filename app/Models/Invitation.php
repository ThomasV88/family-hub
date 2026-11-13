<?php

namespace App\Models;

use App\Access\Preset;
use Illuminate\Database\Eloquent\Model;

class Invitation extends Model
{
    protected $fillable = ['email', 'token_hash', 'preset', 'locale', 'invited_by', 'expires_at', 'accepted_at', 'revoked_at', 'mail_failed_at', 'accepted_user_id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'preset' => Preset::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'mail_failed_at' => 'datetime',
        ];
    }

    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public static function findByToken(string $plain): ?self
    {
        return self::query()->where('token_hash', self::hashToken($plain))->first();
    }

    /** @return 'valid'|'expired'|'used'|'revoked' */
    public function state(): string
    {
        if ($this->accepted_at !== null) {
            return 'used';
        }
        if ($this->revoked_at !== null) {
            return 'revoked';
        }

        return $this->expires_at->isPast() ? 'expired' : 'valid';
    }

    /** Pending or expired, not revoked and not accepted: may be resent, re-linked or revoked. */
    public function isOpen(): bool
    {
        return in_array($this->state(), ['valid', 'expired'], true);
    }
}
