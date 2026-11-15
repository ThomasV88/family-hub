<?php

namespace App\Models;

use Database\Factories\WishOccasionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stored occasion: a member's name-day snapshot (calendar, name, month, day) or a custom occasion.
 * owner_id and created_by_id are set with forceFill by the controllers.
 */
class WishOccasion extends Model
{
    /** @use HasFactory<WishOccasionFactory> */
    use HasFactory;

    public const NAMEDAY = 'nameday';

    public const CUSTOM = 'custom';

    /** @var list<string> */
    protected $fillable = ['title', 'month', 'day', 'year', 'calendar', 'name'];

    protected function casts(): array
    {
        return ['month' => 'integer', 'day' => 'integer', 'year' => 'integer'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
