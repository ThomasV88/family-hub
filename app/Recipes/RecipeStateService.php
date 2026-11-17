<?php

namespace App\Recipes;

use App\Models\Recipe;
use App\Models\User;
use App\Recipes\Queries\RecipeQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Per-member cooking state (ticks, done steps, favourite, note, servings). Every call is absolute and idempotent and
 * only ever touches the actor's own rows. The recipe is loaded through visibleTo, so a hidden recipe is a 404.
 */
final class RecipeStateService
{
    public function __construct(private readonly RecipeQuery $query) {}

    public function tick(User $actor, int $recipeId, int $ingredientId, bool $ticked): void
    {
        $recipe = $this->query->visible($actor, $recipeId);
        abort_unless($recipe->ingredients()->whereKey($ingredientId)->exists(), 404);

        if ($ticked) {
            DB::table('recipe_ingredient_ticks')->insertOrIgnore(['ingredient_id' => $ingredientId, 'user_id' => $actor->id, 'created_at' => now()]);
        } else {
            DB::table('recipe_ingredient_ticks')->where('ingredient_id', $ingredientId)->where('user_id', $actor->id)->delete();
        }
    }

    public function stepDone(User $actor, int $recipeId, int $stepId, bool $done): void
    {
        $recipe = $this->query->visible($actor, $recipeId);
        abort_unless($recipe->steps()->whereKey($stepId)->exists(), 404);

        if ($done) {
            DB::table('recipe_step_done')->insertOrIgnore(['step_id' => $stepId, 'user_id' => $actor->id, 'created_at' => now()]);
        } else {
            DB::table('recipe_step_done')->where('step_id', $stepId)->where('user_id', $actor->id)->delete();
        }
    }

    public function favourite(User $actor, int $recipeId, bool $favourite): void
    {
        $this->upsert($actor, $recipeId, ['favourite' => $favourite]);
    }

    public function note(User $actor, int $recipeId, ?string $note): void
    {
        $note = $note === null ? null : trim($note);
        $note = $note === '' ? null : $note;
        if ($note !== null && mb_strlen($note) > (int) config('recipes.member_note_max')) {
            throw ValidationException::withMessages(['note' => 'recipes.error.note_too_long']);
        }
        $this->upsert($actor, $recipeId, ['note' => $note]);
    }

    public function servings(User $actor, int $recipeId, ?int $servings): void
    {
        if ($servings !== null && ($servings < (int) config('recipes.servings_min') || $servings > (int) config('recipes.servings_max'))) {
            throw ValidationException::withMessages(['servings' => 'recipes.error.servings_invalid']);
        }
        $this->upsert($actor, $recipeId, ['servings' => $servings]);
    }

    /** Clears the actor's ticks and done marks of this recipe; favourite, note and servings stay. */
    public function reset(User $actor, int $recipeId): void
    {
        $recipe = $this->query->visible($actor, $recipeId);

        DB::transaction(function () use ($actor, $recipe): void {
            DB::table('recipe_ingredient_ticks')->where('user_id', $actor->id)
                ->whereIn('ingredient_id', DB::table('recipe_ingredients')->where('recipe_id', $recipe->id)->select('id'))->delete();
            DB::table('recipe_step_done')->where('user_id', $actor->id)
                ->whereIn('step_id', DB::table('recipe_steps')->where('recipe_id', $recipe->id)->select('id'))->delete();
        });
    }

    /** @return array{favourite: bool, note: ?string, servings: ?int, tickedIngredientIds: list<int>, doneStepIds: list<int>} */
    public function stateFor(User $actor, Recipe $recipe): array
    {
        $state = DB::table('recipe_member_states')->where('recipe_id', $recipe->id)->where('user_id', $actor->id)->first();

        return [
            'favourite' => (bool) ($state->favourite ?? false),
            'note' => $state->note ?? null,
            'servings' => isset($state->servings) ? (int) $state->servings : null,
            'tickedIngredientIds' => DB::table('recipe_ingredient_ticks')->where('user_id', $actor->id)
                ->whereIn('ingredient_id', DB::table('recipe_ingredients')->where('recipe_id', $recipe->id)->select('id'))
                ->orderBy('ingredient_id')->pluck('ingredient_id')->map(fn ($v) => (int) $v)->all(),
            'doneStepIds' => DB::table('recipe_step_done')->where('user_id', $actor->id)
                ->whereIn('step_id', DB::table('recipe_steps')->where('recipe_id', $recipe->id)->select('id'))
                ->orderBy('step_id')->pluck('step_id')->map(fn ($v) => (int) $v)->all(),
        ];
    }

    /** @param array<string, mixed> $values */
    private function upsert(User $actor, int $recipeId, array $values): void
    {
        $recipe = $this->query->visible($actor, $recipeId);

        DB::table('recipe_member_states')->insertOrIgnore(['recipe_id' => $recipe->id, 'user_id' => $actor->id, 'favourite' => false, 'updated_at' => now()]);
        DB::table('recipe_member_states')->where('recipe_id', $recipe->id)->where('user_id', $actor->id)
            ->update($values + ['updated_at' => now()]);
    }
}
