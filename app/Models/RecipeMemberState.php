<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Per-member favourite, note and chosen servings; keyed by (recipe, user). */
class RecipeMemberState extends Model
{
    public const CREATED_AT = null;

    public $incrementing = false;

    protected $primaryKey = null;

    /** @var list<string> */
    protected $fillable = ['recipe_id', 'user_id', 'favourite', 'note', 'servings'];

    protected function casts(): array
    {
        return ['favourite' => 'boolean', 'servings' => 'integer'];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
