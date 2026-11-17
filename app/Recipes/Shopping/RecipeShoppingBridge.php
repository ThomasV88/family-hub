<?php

namespace App\Recipes\Shopping;

use App\Models\ShoppingCatalogItem;
use App\Models\ShoppingItem;
use App\Models\ShoppingList;
use App\Models\ShoppingStore;
use App\Models\ShoppingUnit;
use App\Models\User;
use App\Recipes\Queries\RecipeQuery;
use App\Shopping\ItemService;
use App\Shopping\ItemState;
use App\Shopping\ListService;
use App\Shopping\ListStatus;
use App\Shopping\Queries\ListQuery;
use App\Shopping\ShoppingAccess;
use Illuminate\Support\Facades\DB;

/** "Add to shopping list": review data for the dialog and an all-or-nothing commit through the Shopping services. */
final class RecipeShoppingBridge
{
    public function __construct(
        private readonly RecipeQuery $recipes,
        private readonly CatalogMatcher $matcher,
        private readonly ShoppingAccess $access,
        private readonly ListService $lists,
        private readonly ItemService $items,
    ) {}

    /** @return array<string, mixed> */
    public function preview(User $actor, int $recipeId): array
    {
        $recipe = $this->recipes->find($actor, $recipeId);

        $catalog = ShoppingCatalogItem::query()->orderBy('name_key')->orderBy('id')
            ->get(['id', 'name', 'name_key', 'default_unit_id', 'image_path', 'image_version']);
        $units = ShoppingUnit::query()->orderBy('position')->orderBy('id')->get();
        $pcs = $units->firstWhere('key', 'pcs')?->id;
        $defaults = $catalog->mapWithKeys(fn ($c) => [$c->id => $c->default_unit_id]);

        $rows = $recipe->ingredients->sortBy('position')->values()->map(function ($i) use ($catalog, $defaults, $pcs) {
            $match = $this->matcher->match((string) $i->name, $catalog);
            $quantity = $i->quantity === null ? null : $this->trim((string) $i->quantity);

            return [
                'ingredientId' => $i->id,
                'name' => $i->name,
                'quantity' => $quantity,
                'unitId' => $i->unit_id,
                'note' => $i->note,
                'match' => $match,
                'suggestedUnitId' => $i->unit_id
                    ?? ($match['catalogItemId'] !== null ? $defaults[$match['catalogItemId']] ?? null : null)
                    ?? ($quantity === null ? $pcs : null),
            ];
        })->all();

        return [
            'baseServings' => $recipe->servings,
            'lists' => $this->offeredLists($actor),
            'stores' => ShoppingStore::query()->orderBy('name_key')->orderBy('id')->get(['id', 'name'])
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->all(),
            'catalog' => $catalog->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'defaultUnitId' => $c->default_unit_id,
                'imageVersion' => $c->image_path !== null ? $c->image_version : null,
            ])->values()->all(),
            'units' => $units->map(fn ($u) => [
                'id' => $u->id,
                'key' => $u->key,
                'names' => $u->key === null ? ['en' => $u->name_en, 'sk' => $u->name_sk, 'pl' => $u->name_pl] : null,
            ])->values()->all(),
            'rows' => $rows,
            'canCreateList' => true,
            'limits' => [
                'nameMax' => (int) config('recipes.shopping.list_name_max'),
                'itemsMax' => (int) config('recipes.shopping.items_max'),
            ],
        ];
    }

    /**
     * Creates a list (actor responsible) or adds to an open one, all in one transaction. Failures (422, 403, 409)
     * pass through and roll everything back.
     *
     * @param  list<array{catalog_item_id?: ?int, name?: ?string, quantity: string, unit_id?: ?int}>  $rows
     * @return array{listId: int, listName: string, count: int}
     */
    public function commit(User $actor, int $recipeId, string $target, ?int $listId, ?string $name, ?int $storeId, array $rows): array
    {
        $this->recipes->visible($actor, $recipeId);

        return DB::transaction(function () use ($actor, $target, $listId, $name, $storeId, $rows) {
            if ($target === 'new') {
                $list = $this->lists->create($actor, (string) $name, $storeId, null);
            } else {
                $list = ListQuery::visibleTo($actor)->whereKey((int) $listId)->first();
                abort_if($list === null || ! $this->access->canView($actor, $list), 404);
            }

            foreach (array_chunk(array_values($rows), (int) config('shopping.items_per_add_request')) as $chunk) {
                $this->items->add($actor, $list->id, $chunk);
            }

            return ['listId' => $list->id, 'listName' => $list->name, 'count' => count($rows)];
        });
    }

    /** @return list<array<string, mixed>> */
    private function offeredLists(User $actor): array
    {
        $lists = ListQuery::visibleTo($actor)->where('shopping_lists.status', ListStatus::Open->value)
            ->with('store:id,name')->orderByDesc('shopping_lists.updated_at')->orderByDesc('shopping_lists.id')->get()
            ->filter(fn (ShoppingList $l) => $this->access->mayEditItems($actor, $l))->values();

        $pending = $lists->isEmpty() ? collect() : ShoppingItem::query()
            ->whereIn('list_id', $lists->pluck('id'))->whereNotNull('catalog_item_id')
            ->where('state', ItemState::Pending->value)->orderBy('position')->orderBy('id')
            ->get(['list_id', 'catalog_item_id', 'unit_id', 'quantity'])->groupBy('list_id');

        return $lists->map(fn (ShoppingList $l) => [
            'id' => $l->id,
            'name' => $l->name,
            'storeName' => $l->store?->name,
            'updatedAt' => $l->updated_at?->toIso8601String(),
            'pending' => ($pending[$l->id] ?? collect())->map(fn ($i) => [
                'catalogItemId' => $i->catalog_item_id,
                'unitId' => $i->unit_id,
                'quantity' => $this->trim((string) $i->quantity),
            ])->values()->all(),
        ])->all();
    }

    private function trim(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
