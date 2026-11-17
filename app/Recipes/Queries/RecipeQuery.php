<?php

namespace App\Recipes\Queries;

use App\Models\Recipe;
use App\Models\User;
use App\Recipes\RecipeAccess;
use App\Recipes\RecipeVisibility;
use App\Recipes\RecipeSearchKey;
use App\Shopping\NameKey;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Every query starts from Recipe::visibleTo, so a private recipe of another member never leaks. */
final class RecipeQuery
{
    public function __construct(private readonly RecipeAccess $access) {}

    /**
     * @param  array{q?: string, ingredient?: string, categories?: list<string>, author?: int|null, show?: string, favourites?: bool}  $filters
     * @return array{items: Collection<int, Recipe>, hasMore: bool}
     */
    public function index(User $actor, array $filters, int $page = 1): array
    {
        $this->requireView($actor);

        $q = $this->tokens((string) ($filters['q'] ?? ''));
        $ingredient = $this->tokens((string) ($filters['ingredient'] ?? ''));
        $categories = array_values(array_filter(array_map(fn ($c) => RecipeSearchKey::category((string) $c), (array) ($filters['categories'] ?? [])), fn ($c) => $c !== ''));
        $show = $filters['show'] ?? 'all';

        $query = Recipe::query()->visibleTo($actor);

        foreach ($q as $token) {
            $query->whereRaw("recipes.search_key ilike ? escape '\\'", [$this->like($token)]);
        }
        foreach ($ingredient as $token) {
            $query->whereExists(function ($sub) use ($token) {
                $sub->select(DB::raw(1))->from('recipe_ingredients as ri')
                    ->whereColumn('ri.recipe_id', 'recipes.id')
                    ->whereRaw("ri.name_key ilike ? escape '\\'", [$this->like($token)]);
            });
        }
        if ($categories !== []) {
            $query->whereExists(function ($sub) use ($categories) {
                $sub->select(DB::raw(1))->from('recipe_categories as rc')
                    ->whereColumn('rc.recipe_id', 'recipes.id')
                    ->whereIn('rc.name_key', $categories);
            });
        }
        if (! empty($filters['author'])) {
            $query->where('recipes.author_id', (int) $filters['author']);
        }
        if ($show === 'mine') {
            $query->where('recipes.author_id', $actor->id);
        } elseif ($show === 'shared') {
            $query->where('recipes.visibility', RecipeVisibility::Shared->value)->where('recipes.author_id', '!=', $actor->id);
        }
        if (! empty($filters['favourites'])) {
            $query->whereExists(function ($sub) use ($actor) {
                $sub->select(DB::raw(1))->from('recipe_member_states as ms')
                    ->whereColumn('ms.recipe_id', 'recipes.id')
                    ->where('ms.user_id', $actor->id)
                    ->where('ms.favourite', true);
            });
        }

        if ($q !== []) {
            $conditions = implode(' and ', array_fill(0, count($q), "recipes.title_key ilike ? escape '\\'"));
            $query->orderByRaw("case when {$conditions} then 0 else 1 end", array_map(fn ($t) => $this->like($t), $q));
        }
        $query->orderByDesc('recipes.created_at')->orderByDesc('recipes.id');

        $perPage = (int) config('recipes.per_page');
        // Bounded so a crafted page number cannot push the OFFSET out of bigint range (HTTP 500).
        $page = min(1000, max(1, $page));
        $rows = $query->with(['author:id,name', 'categories', 'photos'])
            ->offset(($page - 1) * $perPage)->limit($perPage + 1)->get();

        return ['items' => $rows->take($perPage)->values(), 'hasMore' => $rows->count() > $perPage];
    }

    /** @return array{categories: list<array{name: string, key: string}>, authors: list<array{id: int, name: string}>} */
    public function filterOptions(User $actor): array
    {
        $this->requireView($actor);

        $categories = $this->visibleCategoryRows($actor)->map(fn ($r) => ['name' => (string) $r->name, 'key' => (string) $r->name_key])->all();

        $authors = DB::table('users')
            ->whereIn('id', Recipe::query()->visibleTo($actor)->select('recipes.author_id'))
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => (string) $u->name])->all();

        return ['categories' => $categories, 'authors' => $authors];
    }

    /** @return list<string> */
    public function categorySuggestions(User $actor): array
    {
        $this->requireView($actor);

        return $this->visibleCategoryRows($actor)->map(fn ($r) => (string) $r->name)->all();
    }

    public function find(User $actor, int $id): Recipe
    {
        $this->requireView($actor);

        return Recipe::query()->visibleTo($actor)
            ->with(['ingredients.unit', 'steps', 'photos', 'categories', 'author:id,name'])
            ->findOrFail($id);
    }

    /** Loads a visible recipe under a row lock (hidden or unknown = 404). */
    public function lockForActor(User $actor, int $id): Recipe
    {
        $this->requireView($actor);
        $recipe = Recipe::query()->visibleTo($actor)->whereKey($id)->lockForUpdate()->first();
        abort_if($recipe === null, 404);

        return $recipe;
    }

    /** Loads a visible recipe without a lock (hidden or unknown = 404). */
    public function visible(User $actor, int $id): Recipe
    {
        $this->requireView($actor);
        $recipe = Recipe::query()->visibleTo($actor)->whereKey($id)->first();
        abort_if($recipe === null, 404);

        return $recipe;
    }

    /** @return Collection<int, object> */
    private function visibleCategoryRows(User $actor)
    {
        return DB::table('recipe_categories as rc')
            ->joinSub(Recipe::query()->visibleTo($actor)->select('recipes.id'), 'v', 'v.id', '=', 'rc.recipe_id')
            ->groupBy('rc.name_key')
            ->orderByRaw('count(*) desc')->orderBy('rc.name_key')
            ->selectRaw('min(rc.name) as name, rc.name_key')
            ->get();
    }

    private function requireView(User $actor): void
    {
        abort_unless($this->access->hasView($actor), 404);
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        $folded = NameKey::from($text);

        return $folded === '' ? [] : array_values(array_filter(explode(' ', $folded), fn ($t) => $t !== ''));
    }

    private function like(string $token): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $token).'%';
    }
}
