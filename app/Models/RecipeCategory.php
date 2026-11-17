<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeCategory extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    /** @var list<string> */
    protected $fillable = ['recipe_id', 'name', 'name_key', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}
