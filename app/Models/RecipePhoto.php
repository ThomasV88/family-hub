<?php

namespace App\Models;

use Database\Factories\RecipePhotoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipePhoto extends Model
{
    /** @use HasFactory<RecipePhotoFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['recipe_id', 'path', 'thumb_path', 'position', 'is_main', 'version'];

    protected function casts(): array
    {
        return ['is_main' => 'boolean', 'position' => 'integer', 'version' => 'integer'];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}
