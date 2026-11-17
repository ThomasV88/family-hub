<?php

namespace App\Recipes;

use App\Models\Recipe;
use App\Models\ShoppingUnit;
use App\Models\User;
use App\Recipes\Images\RecipeImageStore;
use App\Recipes\Queries\RecipeQuery;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Recipe writes. Every write runs in a transaction under a row lock on the recipe and rebuilds the search keys.
 * Order of answers: 404 (hidden, unknown or no module view), 403, 422.
 */
final class RecipeService
{
    public function __construct(
        private readonly RecipeAccess $access,
        private readonly RecipeQuery $query,
        private readonly RecipeImageStore $images,
    ) {}

    /**
     * @param  array<string, mixed>  $data  may carry `client_token`: a retry with the same token returns the recipe the first
     *                                      request created (also when two requests race) instead of creating a second one
     */
    public function create(User $actor, array $data, ?string $importId = null): Recipe
    {
        abort_unless($this->access->hasEdit($actor), 403);
        $clean = $this->normalise($data);
        $token = isset($data['client_token']) && is_string($data['client_token']) && $data['client_token'] !== '' ? $data['client_token'] : null;
        if ($token !== null) {
            $existing = $this->byToken($actor, $token);
            if ($existing !== null) {
                return $existing;
            }
        }

        try {
            return $this->createNew($actor, $clean, $data, $importId, $token);
        } catch (UniqueConstraintViolationException $e) {
            // Two requests with the same token raced: the other one won.
            $winner = $token === null ? null : $this->byToken($actor, $token);
            if ($winner === null) {
                throw $e;
            }

            return $winner;
        }
    }

    private function byToken(User $actor, string $token): ?Recipe
    {
        return Recipe::query()->where('author_id', $actor->id)->where('client_token', $token)->first();
    }

    /**
     * @param  array<string, mixed>  $clean
     * @param  array<string, mixed>  $data
     */
    private function createNew(User $actor, array $clean, array $data, ?string $importId, ?string $token): Recipe
    {
        return DB::transaction(function () use ($actor, $clean, $data, $importId, $token): Recipe {
            // A ready draft of this member is consumed by the save; a pruned, foreign or unknown one just means "manual".
            $import = $importId !== null && Str::isUuid($importId)
                ? DB::table('recipe_imports')->where('id', $importId)->where('user_id', $actor->id)->where('status', 'ready')->lockForUpdate()->value('id')
                : null;
            $recipe = new Recipe;
            $recipe->forceFill(['author_id' => $actor->id, 'client_token' => $token, 'created_via' => $import !== null || ($data['created_via'] ?? 'manual') === 'url' ? 'url' : 'manual']);
            $this->fillHeader($recipe, $clean);
            $recipe->save();

            foreach ($clean['ingredients'] as $i => $line) {
                $recipe->ingredients()->create($this->ingredientRow($line, $i + 1));
            }
            foreach ($clean['steps'] as $i => $line) {
                $recipe->steps()->create(['position' => $i + 1, 'body' => $line['body']]);
            }
            $this->replaceCategories($recipe, $clean['categories']);
            if ($import !== null) {
                $now = Carbon::now();
                DB::table('recipe_imports')->where('id', $import)->where('status', 'ready')
                    ->update(['status' => 'used', 'payload' => null, 'updated_at' => $now]);
            }

            return $recipe;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, int $id, array $data): Recipe
    {
        return DB::transaction(function () use ($actor, $id, $data): Recipe {
            $recipe = $this->query->lockForActor($actor, $id);
            abort_unless($this->access->mayEdit($actor, $recipe), 403);
            $clean = $this->normalise($data);

            $existingIngredients = $recipe->ingredients()->pluck('id')->map(fn ($v) => (int) $v)->all();
            $existingSteps = $recipe->steps()->pluck('id')->map(fn ($v) => (int) $v)->all();
            $this->assertIds($clean['ingredients'], $existingIngredients);
            $this->assertIds($clean['steps'], $existingSteps);

            $this->fillHeader($recipe, $clean);
            $recipe->save();

            $this->diff($recipe, 'recipe_ingredients', $existingIngredients, $clean['ingredients'],
                fn (array $line, int $pos) => $this->ingredientRow($line, $pos));
            $this->diff($recipe, 'recipe_steps', $existingSteps, $clean['steps'],
                fn (array $line, int $pos) => ['position' => $pos, 'body' => $line['body']]);
            $this->replaceCategories($recipe, $clean['categories']);

            return $recipe->refresh();
        });
    }

    public function setVisibility(User $actor, int $id, RecipeVisibility $visibility): Recipe
    {
        return DB::transaction(function () use ($actor, $id, $visibility): Recipe {
            $recipe = $this->query->lockForActor($actor, $id);
            abort_unless($this->access->mayEdit($actor, $recipe), 403);
            $recipe->forceFill(['visibility' => $visibility])->save();

            return $recipe;
        });
    }

    public function delete(User $actor, int $id): void
    {
        DB::transaction(function () use ($actor, $id): void {
            $recipe = $this->query->lockForActor($actor, $id);
            abort_unless($this->access->mayEdit($actor, $recipe), 403);
            $recipe->delete();
            DB::afterCommit(fn () => $this->images->deleteDirectory($id));
        });
    }

    /**
     * Validates and normalises the payload.
     *
     * @param  array<string, mixed>  $data
     * @return array{title: string, description: ?string, servings: ?int, prep_minutes: ?int, cook_minutes: ?int, source_url: ?string, source_host: ?string, visibility: RecipeVisibility, categories: list<array{name: string, key: string}>, ingredients: list<array<string, mixed>>, steps: list<array<string, mixed>>}
     */
    private function normalise(array $data): array
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', (string) ($data['title'] ?? '')));
        if ($title === '' || mb_strlen($title) > (int) config('recipes.title_max')) {
            $this->fail('title', 'title_invalid');
        }
        $description = isset($data['description']) ? trim((string) $data['description']) : null;
        $description = $description === '' ? null : $description;
        if ($description !== null && mb_strlen($description) > (int) config('recipes.description_max')) {
            $this->fail('description', 'description_invalid');
        }

        $servings = $this->intOrNull($data['servings'] ?? null, (int) config('recipes.servings_min'), (int) config('recipes.servings_max'), 'servings');
        $prep = $this->intOrNull($data['prep_minutes'] ?? null, 0, (int) config('recipes.minutes_max'), 'prep_minutes');
        $cook = $this->intOrNull($data['cook_minutes'] ?? null, 0, (int) config('recipes.minutes_max'), 'cook_minutes');

        [$sourceUrl, $sourceHost] = $this->source($data['source_url'] ?? null);

        $visibility = $data['visibility'] ?? RecipeVisibility::Private;
        if (is_string($visibility)) {
            $visibility = RecipeVisibility::tryFrom($visibility) ?? $this->fail('visibility', 'visibility_invalid');
        }

        $categories = [];
        foreach ((array) ($data['categories'] ?? []) as $name) {
            $name = trim((string) preg_replace('/\s+/u', ' ', (string) $name));
            if ($name === '') {
                continue;
            }
            if (mb_strlen($name) > (int) config('recipes.category_max')) {
                $this->fail('categories', 'category_invalid');
            }
            $key = RecipeSearchKey::category($name);
            if (! isset($categories[$key])) {
                $categories[$key] = ['name' => $name, 'key' => $key];
            }
        }
        if (count($categories) > (int) config('recipes.categories_max')) {
            $this->fail('categories', 'categories_count');
        }

        $ingredients = [];
        foreach (array_values((array) ($data['ingredients'] ?? [])) as $line) {
            $line = (array) $line;
            $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($line['name'] ?? '')));
            if ($name === '' || mb_strlen($name) > (int) config('recipes.ingredient_name_max')) {
                $this->fail('ingredients', 'ingredient_invalid');
            }
            $note = isset($line['note']) ? trim((string) $line['note']) : null;
            $note = $note === '' ? null : $note;
            if ($note !== null && mb_strlen($note) > (int) config('recipes.ingredient_note_max')) {
                $this->fail('ingredients', 'ingredient_note_invalid');
            }
            $ingredients[] = [
                'id' => isset($line['id']) && $line['id'] !== '' ? (int) $line['id'] : null,
                'name' => $name,
                'quantity' => $this->quantity($line['quantity'] ?? null),
                'unit_id' => $this->unit($line['unit_id'] ?? null),
                'note' => $note,
            ];
        }
        if ($ingredients === []) {
            $this->fail('ingredients', 'ingredients_required');
        }
        if (count($ingredients) > (int) config('recipes.ingredients_max')) {
            $this->fail('ingredients', 'ingredients_count');
        }

        $steps = [];
        foreach (array_values((array) ($data['steps'] ?? [])) as $line) {
            $line = (array) $line;
            $body = trim((string) ($line['body'] ?? ''));
            if ($body === '' || mb_strlen($body) > (int) config('recipes.step_max')) {
                $this->fail('steps', 'step_invalid');
            }
            $steps[] = ['id' => isset($line['id']) && $line['id'] !== '' ? (int) $line['id'] : null, 'body' => $body];
        }
        if ($steps === []) {
            $this->fail('steps', 'steps_required');
        }
        if (count($steps) > (int) config('recipes.steps_max')) {
            $this->fail('steps', 'steps_count');
        }

        return [
            'title' => $title,
            'description' => $description,
            'servings' => $servings,
            'prep_minutes' => $prep,
            'cook_minutes' => $cook,
            'source_url' => $sourceUrl,
            'source_host' => $sourceHost,
            'visibility' => $visibility,
            'categories' => array_values($categories),
            'ingredients' => $ingredients,
            'steps' => $steps,
        ];
    }

    /** @param array<string, mixed> $clean */
    private function fillHeader(Recipe $recipe, array $clean): void
    {
        $recipe->forceFill([
            'title' => $clean['title'],
            'title_key' => RecipeSearchKey::title($clean['title']),
            'description' => $clean['description'],
            'servings' => $clean['servings'],
            'prep_minutes' => $clean['prep_minutes'],
            'cook_minutes' => $clean['cook_minutes'],
            'source_url' => $clean['source_url'],
            'source_host' => $clean['source_host'],
            'visibility' => $clean['visibility'],
            'search_key' => RecipeSearchKey::build(
                $clean['title'],
                $clean['description'],
                array_map(fn ($l) => $l['name'], $clean['ingredients']),
                array_map(fn ($c) => $c['name'], $clean['categories']),
            ),
        ]);
    }

    /** @param array<string, mixed> $line @return array<string, mixed> */
    private function ingredientRow(array $line, int $position): array
    {
        return [
            'position' => $position,
            'name' => $line['name'],
            'name_key' => RecipeSearchKey::ingredient($line['name']),
            'quantity' => $line['quantity'],
            'unit_id' => $line['unit_id'],
            'note' => $line['note'],
        ];
    }

    /** @param list<array{name: string, key: string}> $categories */
    private function replaceCategories(Recipe $recipe, array $categories): void
    {
        DB::table('recipe_categories')->where('recipe_id', $recipe->id)->delete();
        foreach ($categories as $i => $category) {
            DB::table('recipe_categories')->insert([
                'recipe_id' => $recipe->id,
                'name' => $category['name'],
                'name_key' => $category['key'],
                'position' => $i + 1,
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<int>  $existing
     */
    private function assertIds(array $lines, array $existing): void
    {
        $seen = [];
        foreach ($lines as $line) {
            $id = $line['id'];
            if ($id === null) {
                continue;
            }
            if (! in_array($id, $existing, true) || isset($seen[$id])) {
                $this->fail('lines', 'unknown_line');
            }
            $seen[$id] = true;
        }
    }

    /**
     * Updates the lines whose id is known in place, inserts lines without id and deletes the rest (never delete and
     * reinsert: ticks and done marks hang on the ids).
     *
     * @param  list<int>  $existing
     * @param  list<array<string, mixed>>  $lines
     * @param  callable(array<string, mixed>, int): array<string, mixed>  $row
     */
    private function diff(Recipe $recipe, string $table, array $existing, array $lines, callable $row): void
    {
        $keep = array_values(array_filter(array_map(fn ($l) => $l['id'], $lines)));
        $remove = array_values(array_diff($existing, $keep));
        if ($remove !== []) {
            DB::table($table)->where('recipe_id', $recipe->id)->whereIn('id', $remove)->delete();
        }
        foreach ($lines as $i => $line) {
            $values = $row($line, $i + 1);
            if ($line['id'] !== null) {
                DB::table($table)->where('recipe_id', $recipe->id)->where('id', $line['id'])->update($values);
            } else {
                DB::table($table)->insert($values + ['recipe_id' => $recipe->id]);
            }
        }
    }

    private function intOrNull(mixed $value, int $min, int $max, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value) || (float) $value !== (float) (int) $value) {
            $this->fail($field, $field.'_invalid');
        }
        $int = (int) $value;
        if ($int < $min || $int > $max) {
            $this->fail($field, $field.'_invalid');
        }

        return $int;
    }

    private function quantity(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = str_replace(',', '.', trim((string) $value));
        if (preg_match('/^\d{1,4}(\.\d{1,2})?$/', $text) !== 1 || (float) $text <= 0 || (float) $text > (float) config('recipes.quantity_max')) {
            $this->fail('ingredients', 'quantity_invalid');
        }

        return $text;
    }

    private function unit(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = (int) $value;
        if (! ShoppingUnit::query()->whereKey($id)->exists()) {
            $this->fail('ingredients', 'unit_unknown');
        }

        return $id;
    }

    /** @return array{0: ?string, 1: ?string} */
    private function source(mixed $url): array
    {
        $url = is_string($url) ? trim($url) : '';
        if ($url === '') {
            return [null, null];
        }
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        // Credentials in the address (https://trusted.example@evil.example/) are refused, like on the import path.
        if (mb_strlen($url) > (int) config('recipes.source_url_max') || ! in_array($scheme, ['http', 'https'], true) || $host === ''
            || isset($parts['user']) || isset($parts['pass'])) {
            $this->fail('source_url', 'source_url_invalid');
        }

        return [$url, $host];
    }

    private function fail(string $field, string $key): never
    {
        throw ValidationException::withMessages([$field => 'recipes.error.'.$key]);
    }
}
