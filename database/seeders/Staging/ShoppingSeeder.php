<?php

namespace Database\Seeders\Staging;

use App\Models\ShoppingCatalogItem;
use App\Models\ShoppingItem;
use App\Models\ShoppingList;
use App\Models\ShoppingStore;
use App\Models\User;
use App\Shopping\Catalog\CatalogService;
use App\Shopping\Catalog\StoreService;
use App\Shopping\Catalog\UnitService;
use App\Shopping\Images\ShoppingImageStore;
use App\Shopping\ItemService;
use App\Shopping\ItemState;
use App\Shopping\ListService;
use App\Shopping\ListStateService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Synthetic Shopping demo, created through the production services so the seed exercises the real rules: an everyday
 * catalog with generated pictures, five fictional stores with generated logos, one custom unit and seven lists in every
 * state (see docs/ops/staging-data.md). Pictures are coloured shapes drawn in memory with GD: no text, no faces, no
 * network. The purge in StagingSeeder removes everything again before the next seed.
 */
final class ShoppingSeeder
{
    /** @var array<string, int> */
    private array $units = [];

    /** @var array<string, int> catalog name => id */
    private array $catalog = [];

    /** @return array{lists: int, items: int, catalog: int, stores: int} */
    public function seed(StagingContext $ctx): array
    {
        $parent1 = $ctx->user('parent1');
        $parent2 = $ctx->user('parent2');
        $child1 = $ctx->user('child1');
        $grandparent = $ctx->user('grandparent');
        $guest = $ctx->user('guest');
        $admin = $ctx->user('admin');

        $this->units = DB::table('shopping_units')->whereNotNull('key')->pluck('id', 'key')->all();
        $this->units['can'] = app(UnitService::class)->create($admin, 'can', 'plechovka', 'puszka')->id;

        $stores = $this->stores($parent1);
        $this->catalog($parent1);

        $lists = app(ListService::class);
        $items = app(ItemService::class);
        $state = app(ListStateService::class);

        // 1. Open weekly list: people, a reminder tomorrow 18:00, catalog and free-text items, decimal quantities, one note.
        $weekly = $lists->create($parent1, 'Týždenný nákup', $stores['Demo Market'], $parent1->id);
        $items->add($parent1, $weekly->id, [
            ['catalog_item_id' => $this->catalog['Mlieko'], 'quantity' => 2],
            ['catalog_item_id' => $this->catalog['Chlieb']],
            ['catalog_item_id' => $this->catalog['Vajcia'], 'quantity' => 10],
            ['catalog_item_id' => $this->catalog['Zemiaky'], 'quantity' => '1.5'],
            ['catalog_item_id' => $this->catalog['Jablká'], 'quantity' => '0.75', 'note' => 'Radšej sladšie odrody'],
            ['catalog_item_id' => $this->catalog['Paradajky'], 'quantity' => '0.5'],
            ['catalog_item_id' => $this->catalog['Paradajkový pretlak'], 'quantity' => 3],
            ['name' => 'Čerstvá bazalka', 'quantity' => 1, 'unit_id' => $this->units['bunch']],
            ['name' => 'Darček pre suseda', 'quantity' => 1],
        ]);
        $remind = CarbonImmutable::now((string) config('family.timezone'))->addDay()->setTime(18, 0);
        $lists->updateSettings($parent1, $weekly->id, [
            'contributor_ids' => [$child1->id, $grandparent->id],
            'viewer_ids' => [$guest->id],
            'remind_at' => $remind,
        ]);

        // 2. Open list with no store and three free-text items.
        $small = $lists->create($parent2, 'Drobnosti z drogérie', null, $parent2->id);
        $items->add($parent2, $small->id, [
            ['name' => 'Zubná pasta', 'quantity' => 2],
            ['name' => 'Špongie na riad', 'quantity' => 1, 'unit_id' => $this->units['pack']],
            ['name' => 'Tekuté mydlo', 'quantity' => 1],
        ]);

        // 3. Locked list, half marked: bought, unavailable and pending items.
        $market = $lists->create($grandparent, 'Sobotný trh', $stores['Zelený košík'], $grandparent->id);
        $items->add($grandparent, $market->id, [
            ['catalog_item_id' => $this->catalog['Jablká'], 'quantity' => 2],
            ['catalog_item_id' => $this->catalog['Mrkva'], 'quantity' => 1],
            ['catalog_item_id' => $this->catalog['Cibuľa'], 'quantity' => '1.5'],
            ['catalog_item_id' => $this->catalog['Špenát']],
            ['catalog_item_id' => $this->catalog['Med']],
            ['name' => 'Domáci syr', 'quantity' => '0.5', 'unit_id' => $this->units['kg']],
        ]);
        $lists->updateSettings($grandparent, $market->id, ['contributor_ids' => [$parent1->id], 'viewer_ids' => [$child1->id]]);
        $state->setLocked($grandparent, $market->id, true);
        $this->mark($state, $grandparent, $market, ['Jablká' => ItemState::Bought, 'Mrkva' => ItemState::Bought, 'Špenát' => ItemState::Unavailable]);

        // 4. Locked list with every item resolved: "Finish shopping" is visible.
        $party = $lists->create($parent2, 'Oslava narodenín', $stores['Potraviny Pri Dube'], $parent2->id);
        $items->add($parent2, $party->id, [
            ['catalog_item_id' => $this->catalog['Šťava'], 'quantity' => 4, 'unit_id' => $this->units['l']],
            ['catalog_item_id' => $this->catalog['Čipsy'], 'quantity' => 3],
            ['catalog_item_id' => $this->catalog['Fazuľa v konzerve'], 'quantity' => 2],
            ['name' => 'Sviečky na tortu', 'quantity' => 1, 'unit_id' => $this->units['pack']],
        ]);
        $state->setLocked($parent2, $party->id, true);
        $this->mark($state, $parent2, $party, [
            'Šťava' => ItemState::Bought, 'Čipsy' => ItemState::Bought,
            'Fazuľa v konzerve' => ItemState::Bought, 'Sviečky na tortu' => ItemState::Unavailable,
        ]);

        // 5. Finished list without carry-over.
        $weekend = $lists->create($parent1, 'Nákup na víkend', $stores['Demo Market'], $parent1->id);
        $items->add($parent1, $weekend->id, [
            ['catalog_item_id' => $this->catalog['Múka'], 'quantity' => 1, 'unit_id' => $this->units['kg']],
            ['catalog_item_id' => $this->catalog['Cukor'], 'quantity' => 1, 'unit_id' => $this->units['kg']],
            ['catalog_item_id' => $this->catalog['Maslo']],
            ['name' => 'Kakao', 'quantity' => 1, 'unit_id' => $this->units['pack']],
        ]);
        $lists->updateSettings($parent1, $weekend->id, ['contributor_ids' => [$child1->id]]);
        $state->setLocked($parent1, $weekend->id, true);
        $this->mark($state, $parent1, $weekend, ['Múka' => ItemState::Bought, 'Cukor' => ItemState::Bought, 'Maslo' => ItemState::Bought, 'Kakao' => ItemState::Bought]);
        $state->finish($parent1, $weekend->id, null);

        // 6. Finished list with unavailable items and its open carry-over list.
        $big = $lists->create($parent2, 'Veľký nákup', $stores['Zelený košík'], $parent2->id);
        $items->add($parent2, $big->id, [
            ['catalog_item_id' => $this->catalog['Ryža'], 'quantity' => 2, 'unit_id' => $this->units['kg']],
            ['catalog_item_id' => $this->catalog['Cestoviny'], 'quantity' => 3, 'unit_id' => $this->units['pack']],
            ['catalog_item_id' => $this->catalog['Olivový olej']],
            ['catalog_item_id' => $this->catalog['Sójová omáčka']],
            ['name' => 'Tofu', 'quantity' => 2, 'unit_id' => $this->units['pack']],
        ]);
        $state->setLocked($parent2, $big->id, true);
        $this->mark($state, $parent2, $big, [
            'Ryža' => ItemState::Bought, 'Cestoviny' => ItemState::Bought, 'Olivový olej' => ItemState::Bought,
            'Sójová omáčka' => ItemState::Unavailable, 'Tofu' => ItemState::Unavailable,
        ]);
        $state->finish($parent2, $big->id, 'Veľký nákup (nezakúpené)');

        // 7. Copy of the finished list 5, keeping people and responsible person.
        $state->copy($parent1, $weekend->id, 'Nákup na víkend (kópia)', true);

        return [
            'lists' => ShoppingList::query()->count(),
            'items' => ShoppingItem::query()->count(),
            'catalog' => ShoppingCatalogItem::query()->count(),
            'stores' => ShoppingStore::query()->count(),
        ];
    }

    /** @param array<string, ItemState> $marks item name => state; items not listed stay pending */
    private function mark(ListStateService $state, User $actor, ShoppingList $list, array $marks): void
    {
        foreach (ShoppingItem::query()->where('list_id', $list->id)->orderBy('position')->get() as $item) {
            if (isset($marks[$item->name])) {
                $state->setItemState($actor, $list->id, $item->id, $marks[$item->name]);
            }
        }
    }

    /** @return array<string, int> name => id */
    private function stores(User $actor): array
    {
        $service = app(StoreService::class);
        $images = app(ShoppingImageStore::class);
        $ids = [];
        foreach (['Demo Market', 'Zelený košík', 'Potraviny Pri Dube', 'Drogéria Lúka', 'Mini Obchod Ráno'] as $i => $name) {
            $store = $service->create($actor, $name);
            $images->storeStoreLogo($store, $this->picture($name, $i, 320));
            $ids[$name] = $store->id;
        }

        return $ids;
    }

    private function catalog(User $actor): void
    {
        $service = app(CatalogService::class);
        $images = app(ShoppingImageStore::class);
        $rows = [
            ['Mlieko', 'l'], ['Chlieb', 'pcs'], ['Vajcia', 'pcs'], ['Zemiaky', 'kg'], ['Jablká', 'kg'], ['Paradajky', 'kg'],
            ['Paradajkový pretlak', 'can'], ['Mrkva', 'kg'], ['Cibuľa', 'kg'], ['Špenát', 'pack'], ['Med', 'pcs'], ['Šťava', 'l'],
            ['Čipsy', 'pack'], ['Fazuľa v konzerve', 'can'], ['Múka', 'kg'], ['Cukor', 'kg'], ['Maslo', 'pcs'], ['Ryža', 'kg'],
            ['Cestoviny', 'pack'], ['Olivový olej', 'pcs'], ['Sójová omáčka', 'pcs'], ['Syr', 'g'], ['Jogurt', 'pcs'], ['Banány', 'kg'],
            ['Kuracie prsia', 'kg'], ['Káva', 'pack'], ['Čaj', 'pack'], ['Soľ', 'pcs'], ['Šampón', 'pcs'], ['Toaletný papier', 'pack'],
        ];
        foreach ($rows as $i => [$name, $unit]) {
            $item = $service->create($actor, $name, $this->units[$unit]);
            $images->storeCatalogImage($item, $this->picture($name, $i, 400));
            $this->catalog[$name] = $item->id;
        }
    }

    /** Flat coloured shapes on a plain background, derived from the name. No text, no faces, no network. */
    private function picture(string $name, int $i, int $size): string
    {
        $h = crc32($name);
        $img = imagecreatetruecolor($size, $size);
        $c = fn (int $shift) => imagecolorallocate($img, 90 + (($h >> $shift) & 0x7F), 90 + (($h >> ($shift + 7)) & 0x7F), 90 + (($h >> ($shift + 3)) & 0x7F));
        imagefilledrectangle($img, 0, 0, $size, $size, imagecolorallocate($img, 244, 240, 230));
        $q = (int) ($size / 4);
        imagefilledellipse($img, 2 * $q, (int) ($size * 0.55), 2 * $q + $q, 2 * $q + $q, $c(0));
        if ($i % 2 === 0) {
            imagefilledrectangle($img, $q, (int) ($q * 0.5), 2 * $q, (int) ($q * 1.4), $c(5));
        } else {
            imagefilledpolygon($img, [2 * $q, (int) ($q * 0.3), 3 * $q + (int) ($q * 0.5), (int) ($q * 1.4), 2 * $q - (int) ($q * 0.5), (int) ($q * 1.4)], $c(9));
        }
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }
}
