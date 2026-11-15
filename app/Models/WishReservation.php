<?php

namespace App\Models;

use Database\Factories\WishReservationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A giver's claim on someone else's wish. Only the Giving namespaces may use this model (arch-tested).
 * No $touches: reservation writes must never move wishes.updated_at.
 */
class WishReservation extends Model
{
    /** @use HasFactory<WishReservationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['wish_id', 'user_id', 'cobuy_state'];

    public function wish(): BelongsTo
    {
        return $this->belongsTo(Wish::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(WishContribution::class, 'reservation_id');
    }
}
