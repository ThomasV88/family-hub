<?php

namespace App\Models;

use App\Wishlist\WishPriority;
use Database\Factories\WishFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Deliberately blind to reservations: no relation, no counter, no appended attribute.
 * owner_id, created_by_id, image_* and received_at are set with forceFill by services.
 */
class Wish extends Model
{
    /** @use HasFactory<WishFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['title', 'notes', 'price_minor', 'price_currency', 'size_notes', 'priority'];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'priority' => WishPriority::class,
            'received_at' => 'immutable_datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function urls(): HasMany
    {
        return $this->hasMany(WishUrl::class)->orderBy('position')->orderBy('id');
    }
}
