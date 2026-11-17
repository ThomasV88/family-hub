<?php

namespace App\Shopping\Queries;

use App\Models\ShoppingCatalogItem;
use App\Models\ShoppingStore;
use App\Models\ShoppingUnit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Inertia props for Settings > Shopping. The catalog, stores and units are shared by every member with Shopping edit;
 * usage numbers are plain counts (no list names or ids). Explicit field lists only, never a file path.
 */
final class SettingsPayload
{
    /** @return array<string, mixed> */
    public function for(User $user): array
    {
        $catalogUse = DB::table('shopping_items')->whereNotNull('catalog_item_id')
            ->groupBy('catalog_item_id')->selectRaw('catalog_item_id as id, count(distinct list_id) as n')->pluck('n', 'id');
        $storeUse = DB::table('shopping_lists')->whereNotNull('store_id')
            ->groupBy('store_id')->selectRaw('store_id as id, count(*) as n')->pluck('n', 'id');
        $unitUse = DB::table('shopping_items')->whereNotNull('unit_id')
            ->groupBy('unit_id')->selectRaw('unit_id as id, count(*) as n')->pluck('n', 'id');

        return [
            'catalog' => ShoppingCatalogItem::query()->orderBy('name_key')->orderBy('id')->get()->map(fn (ShoppingCatalogItem $i) => [
                'id' => $i->id,
                'name' => $i->name,
                'defaultUnitId' => $i->default_unit_id,
                'imageVersion' => $i->image_path === null ? null : $i->image_version,
                'usedOn' => (int) ($catalogUse[$i->id] ?? 0),
            ])->all(),
            'stores' => ShoppingStore::query()->orderBy('name_key')->orderBy('id')->get()->map(fn (ShoppingStore $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'logoVersion' => $s->logo_path === null ? null : $s->image_version,
                'usedOn' => (int) ($storeUse[$s->id] ?? 0),
            ])->all(),
            'units' => ShoppingUnit::query()->orderBy('position')->orderBy('id')->get()->map(fn (ShoppingUnit $u) => [
                'id' => $u->id,
                'key' => $u->key,
                'names' => $u->isBuiltIn() ? null : ['en' => $u->name_en, 'sk' => $u->name_sk, 'pl' => $u->name_pl],
                'builtIn' => $u->isBuiltIn(),
                'usedOn' => (int) ($unitUse[$u->id] ?? 0),
            ])->all(),
            'canManageUnits' => (bool) $user->is_admin,
        ];
    }
}
