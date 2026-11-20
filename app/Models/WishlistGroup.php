<?php

namespace App\Models;

use App\Wishlist\Groups\GroupMode;
use Database\Factories\WishlistGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Deliberately blind to reservations (no relation to them; arch-tested).
 * mode and created_by_id are set with forceFill by services.
 */
class WishlistGroup extends Model
{
    /** @use HasFactory<WishlistGroupFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['name'];

    protected function casts(): array
    {
        return ['mode' => GroupMode::class];
    }

    public function members(): HasMany
    {
        return $this->hasMany(WishlistGroupMember::class, 'group_id');
    }

    public function memberUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'wishlist_group_members', 'group_id', 'user_id')->withPivot('created_at');
    }

    public function wishes(): HasMany
    {
        return $this->hasMany(Wish::class, 'group_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
