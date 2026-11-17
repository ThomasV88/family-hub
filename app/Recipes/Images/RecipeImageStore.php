<?php

namespace App\Recipes\Images;

use Illuminate\Support\Facades\Storage;

/** Private storage for recipe photos on the local disk (the persisted, backed-up uploads volume). Writes arrive with 12-04. */
class RecipeImageStore
{
    public const PREFIX = 'wish-images/recipes';

    public function directory(int $recipeId): string
    {
        return self::PREFIX.'/'.$recipeId;
    }

    /** Removes every photo file of one recipe (call after the commit that deleted the row). */
    public function deleteDirectory(int $recipeId): void
    {
        Storage::disk('local')->deleteDirectory($this->directory($recipeId));
    }
}
