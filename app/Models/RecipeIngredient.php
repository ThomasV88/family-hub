<?php

namespace App\Models;

use Database\Factories\RecipeIngredientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeIngredient extends Model
{
    /** @use HasFactory<RecipeIngredientFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['recipe_id', 'position', 'name', 'name_key', 'quantity', 'unit_id', 'note'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'position' => 'integer'];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ShoppingUnit::class, 'unit_id');
    }
}
