<?php

namespace App\Models;

use Database\Factories\WishlistManagerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WishlistManager extends Model
{
    /** @use HasFactory<WishlistManagerFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['owner_id', 'manager_id', 'created_by_id'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }
}
