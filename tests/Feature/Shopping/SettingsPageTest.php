<?php

use App\Access\AccessLevel;
use App\Models\ShoppingCatalogItem;
use App\Models\ShoppingStore;
use App\Models\ShoppingUnit;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ShoppingFixtures as F;

it('shows the catalog, stores and units with usage counts to a member with Shopping edit', function () {
    $me = F::user(AccessLevel::Edit);
    $custom = ShoppingUnit::factory()->create(['name_en' => 'tin', 'name_sk' => 'plech', 'name_pl' => 'puszka']);
    $milk = ShoppingCatalogItem::factory()->create(['name' => 'Milk', 'default_unit_id' => $custom->id]);
    ShoppingCatalogItem::factory()->create(['name' => 'Apple']);
    $tesco = ShoppingStore::factory()->create(['name' => 'Tesco']);
    $a = F::list($me);
    $b = F::list($me);
    $a->forceFill(['store_id' => $tesco->id])->save();
    F::item($a, ['catalog_item_id' => $milk->id, 'unit_id' => $custom->id]);
    F::item($a, ['catalog_item_id' => $milk->id, 'unit_id' => $custom->id]);
    F::item($b, ['catalog_item_id' => $milk->id]);

    $this->actingAs($me)->get('/settings/shopping')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Settings/Shopping')
        ->where('catalog.0.name', 'Apple')->where('catalog.0.usedOn', 0)->where('catalog.0.imageVersion', null)
        ->where('catalog.1.name', 'Milk')->where('catalog.1.usedOn', 2)->where('catalog.1.defaultUnitId', $custom->id)
        ->where('stores.0.name', 'Tesco')->where('stores.0.usedOn', 1)->where('stores.0.logoVersion', null)
        ->where('canManageUnits', false)
        ->has('units', 10)
        ->where('units.0.key', 'pcs')->where('units.0.builtIn', true)->where('units.0.names', null)
        ->where('units.9.builtIn', false)->where('units.9.usedOn', 2)->where('units.9.names.sk', 'plech')
        ->has('sections'));
});

it('tells an administrator with Shopping edit that units can be managed', function () {
    $admin = User::factory()->admin()->create();
    F::setLevel($admin, AccessLevel::Edit);

    $this->actingAs($admin)->get('/settings/shopping')->assertOk()->assertInertia(fn (Assert $p) => $p->where('canManageUnits', true));
});

it('refuses view-only members, members without a grant and administrators without the grant', function () {
    $this->get('/settings/shopping')->assertRedirect('/login');
    $this->actingAs(F::user(AccessLevel::View))->get('/settings/shopping')->assertForbidden();
    $this->actingAs(F::user(AccessLevel::None))->get('/settings/shopping')->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get('/settings/shopping')->assertForbidden();
});

it('lists the Shopping section in Settings only for members with Shopping edit', function () {
    $editor = F::user(AccessLevel::Edit);
    $viewer = F::user(AccessLevel::View);

    $this->actingAs($editor)->get('/settings')->assertInertia(fn (Assert $p) => $p
        ->where('sections.0.key', 'shopping')->where('sections.0.href', '/settings/shopping')
        ->where('sections.0.labelKey', 'settings.section.shopping')->where('sections.0.icon', 'shopping-cart'));
    $this->actingAs($viewer)->get('/settings')->assertInertia(fn (Assert $p) => $p->has('sections', 3));
});
