<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Every column is set with forceFill by services (empty $fillable). */
class WishlistGroupMember extends Model
{
    /** @var list<string> */
    protected $fillable = [];

    public function group(): BelongsTo
    {
        return $this->belongsTo(WishlistGroup::class, 'group_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_id');
    }
}
