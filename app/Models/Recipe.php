<?php

namespace App\Models;

use App\Recipes\RecipeVisibility;
use Database\Factories\RecipeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Author, visibility, source columns and the search key are set by services with forceFill. */
class Recipe extends Model
{
    /** @use HasFactory<RecipeFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['title', 'description', 'servings', 'prep_minutes', 'cook_minutes'];

    protected function casts(): array
    {
        return [
            'visibility' => RecipeVisibility::class,
            'servings' => 'integer',
            'prep_minutes' => 'integer',
            'cook_minutes' => 'integer',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class)->orderBy('position')->orderBy('id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(RecipeStep::class)->orderBy('position')->orderBy('id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(RecipePhoto::class)->orderBy('position')->orderBy('id');
    }

    public function categories(): HasMany
    {
        return $this->hasMany(RecipeCategory::class)->orderBy('position');
    }

    public function memberStates(): HasMany
    {
        return $this->hasMany(RecipeMemberState::class);
    }

    /** The user's own recipes and every shared recipe, never another member's private one. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->where('recipes.author_id', $user->id)
                ->orWhere('recipes.visibility', RecipeVisibility::Shared->value);
        });
    }
}
