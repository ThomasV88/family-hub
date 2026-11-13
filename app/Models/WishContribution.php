<?php

namespace App\Models;

use Database\Factories\WishContributionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One giver's share of a reservation (the organizer always has one). Only the Giving namespaces use it. */
class WishContribution extends Model
{
    /** @use HasFactory<WishContributionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['reservation_id', 'user_id', 'amount_minor'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(WishReservation::class, 'reservation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
