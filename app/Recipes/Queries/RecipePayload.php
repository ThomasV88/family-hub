<?php

namespace App\Recipes\Queries;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipePhoto;
use App\Models\ShoppingUnit;
use App\Models\User;
use App\Recipes\Import\ImportReason;
use App\Recipes\Import\RecipeImportQuery;
use App\Recipes\Import\RecipeImportService;
use App\Recipes\RecipeAccess;
use App\Recipes\RecipeStateService;
use Illuminate\Support\Facades\DB;

/**
 * Inertia props for Recipes/Index, Recipes/Show and Recipes/Form. Explicit field lists only: names and ids, never an
 * e-mail address, a file path or another member's state.
 */
final class RecipePayload
{
    public function __construct(
        private readonly RecipeQuery $query,
        private readonly RecipeAccess $access,
        private readonly RecipeStateService $state,
        private readonly AccessGate $gate,
        private readonly RecipeImportService $imports,
        private readonly RecipeImportQuery $importQuery,
    ) {}

    /**
     * @param  array{q: string, ingredient: string, categories: list<string>, author: int|null, show: string, favourites: bool}  $filters
     * @return array<string, mixed>
     */
    public function index(User $user, array $filters, int $page): array
    {
        $page = max(1, $page);
        $result = $this->query->index($user, $filters, $page);
        $ids = $result['items']->pluck('id')->all();
        $favourites = $ids === [] ? [] : DB::table('recipe_member_states')
            ->where('user_id', $user->id)->where('favourite', true)->whereIn('recipe_id', $ids)
            ->pluck('recipe_id')->map(fn ($v) => (int) $v)->all();

        return [
            'recipes' => $result['items']->map(fn (Recipe $r) => [
                'id' => $r->id,
                'title' => $r->title,
                'mainPhoto' => $this->mainPhoto($r),
                'prepMinutes' => $r->prep_minutes,
                'cookMinutes' => $r->cook_minutes,
                'servings' => $r->servings,
                'visibility' => $r->visibility->value,
                'categories' => $r->categories->pluck('name')->values()->all(),
                'author' => $this->author($user, $r),
                'favourite' => in_array($r->id, $favourites, true),
            ])->values()->all(),
            'hasMore' => $result['hasMore'],
            'page' => $page,
            'filters' => $filters,
            'filterOptions' => $this->query->filterOptions($user),
            'canCreate' => $this->access->hasEdit($user),
            'import' => $this->imports->noticeState($user),
            'drafts' => $this->drafts($user),
        ];
    }

    /** @return array<string, mixed> */
    public function show(User $user, Recipe $recipe): array
    {
        return [
            'recipe' => $this->recipe($user, $recipe),
            'state' => $this->state->stateFor($user, $recipe),
            'abilities' => $this->access->abilities($user, $recipe),
            'units' => $this->units(),
        ];
    }

    /** @return array<string, mixed> */
    public function form(User $user, ?Recipe $recipe): array
    {
        return [
            'recipe' => $recipe === null ? null : $this->recipe($user, $recipe),
            'defaults' => ['servings' => (int) config('recipes.servings_default'), 'visibility' => 'private'],
            'units' => $this->units(),
            'categorySuggestions' => $this->query->categorySuggestions($user),
            'starterCategories' => array_values((array) config('recipes.starter_categories')),
            'limits' => [
                'titleMax' => (int) config('recipes.title_max'),
                'descriptionMax' => (int) config('recipes.description_max'),
                'ingredientNameMax' => (int) config('recipes.ingredient_name_max'),
                'ingredientNoteMax' => (int) config('recipes.ingredient_note_max'),
                'stepMax' => (int) config('recipes.step_max'),
                'ingredientsMax' => (int) config('recipes.ingredients_max'),
                'stepsMax' => (int) config('recipes.steps_max'),
                'categoriesMax' => (int) config('recipes.categories_max'),
                'categoryMax' => (int) config('recipes.category_max'),
                'servingsMin' => (int) config('recipes.servings_min'),
                'servingsMax' => (int) config('recipes.servings_max'),
                'minutesMax' => (int) config('recipes.minutes_max'),
                'quantityMax' => (int) config('recipes.quantity_max'),
                'sourceUrlMax' => (int) config('recipes.source_url_max'),
                'photosMax' => (int) config('recipes.photos.max_count'),
                'photoMaxBytes' => (int) config('recipes.photos.max_bytes'),
            ],
            'isShared' => $recipe !== null && $recipe->visibility->value === 'shared',
            'canAddToShopping' => $this->gate->allows($user, Module::Shopping, AccessLevel::Edit),
            'import' => $this->imports->noticeState($user),
        ];
    }

    /**
     * The member's own pending, ready and failed imports: id, status, host and a coarse reason, nothing else.
     *
     * @return list<array{id: string, status: string, host: string, reason: string|null}>
     */
    private function drafts(User $user): array
    {
        return array_map(fn (array $d) => [
            'id' => $d['id'],
            'status' => $d['status'],
            'host' => $d['host'],
            'reason' => $d['status'] === 'failed' ? ImportReason::for($d['failureCode']) : null,
        ], $this->importQuery->draftsFor($user));
    }

    /** @return array<string, mixed> */
    private function recipe(User $user, Recipe $recipe): array
    {
        $recipe->loadMissing(['ingredients', 'steps', 'photos', 'categories', 'author:id,name']);

        return [
            'id' => $recipe->id,
            'title' => $recipe->title,
            'description' => $recipe->description,
            'servings' => $recipe->servings,
            'prepMinutes' => $recipe->prep_minutes,
            'cookMinutes' => $recipe->cook_minutes,
            'visibility' => $recipe->visibility->value,
            'sourceUrl' => $recipe->source_url,
            'sourceHost' => $recipe->source_host,
            'createdVia' => $recipe->created_via,
            'author' => $this->author($user, $recipe),
            'categories' => $recipe->categories->pluck('name')->values()->all(),
            'ingredients' => $recipe->ingredients->map(fn (RecipeIngredient $i) => [
                'id' => $i->id,
                'name' => $i->name,
                'quantity' => $this->quantity($i->quantity),
                'unitId' => $i->unit_id,
                'note' => $i->note,
            ])->values()->all(),
            'steps' => $recipe->steps->map(fn ($s) => ['id' => $s->id, 'body' => $s->body])->values()->all(),
            'photos' => $recipe->photos->map(fn (RecipePhoto $p) => [
                'id' => $p->id,
                'position' => $p->position,
                'isMain' => $p->is_main,
                'version' => $p->version,
                'thumbUrl' => "/recipes/{$recipe->id}/photos/{$p->id}/thumb?v={$p->version}",
                'fullUrl' => "/recipes/{$recipe->id}/photos/{$p->id}/full?v={$p->version}",
            ])->values()->all(),
        ];
    }

    /** @return array{id: int, name: string, isMe: bool} */
    private function author(User $user, Recipe $recipe): array
    {
        return ['id' => $recipe->author_id, 'name' => (string) ($recipe->author?->name ?? ''), 'isMe' => $recipe->author_id === $user->id];
    }

    /** @return array{id: int, version: int}|null */
    private function mainPhoto(Recipe $recipe): ?array
    {
        $photo = $recipe->photos->firstWhere('is_main', true) ?? $recipe->photos->first();

        return $photo === null ? null : ['id' => $photo->id, 'version' => $photo->version];
    }

    /** "1.00" becomes "1", "0.50" becomes "0.5". */
    private function quantity(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = (string) $value;

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /** @return list<array<string, mixed>> */
    private function units(): array
    {
        return ShoppingUnit::query()->orderBy('position')->orderBy('id')->get()
            ->map(fn (ShoppingUnit $u) => [
                'id' => $u->id,
                'key' => $u->key,
                'names' => $u->key === null ? ['en' => $u->name_en, 'sk' => $u->name_sk, 'pl' => $u->name_pl] : null,
            ])->values()->all();
    }
}
