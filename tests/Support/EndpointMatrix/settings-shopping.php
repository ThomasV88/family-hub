<?php

use App\Models\ShoppingCatalogItem;
use App\Models\ShoppingStore;
use App\Models\ShoppingUnit;

// Catalog and stores: Shopping edit (parent, child, grandparent). The administrator carries no module grant in the
// matrix, so unit writes (administrator AND Shopping edit) are refused for every role here; the positive case is
// covered in UnitSettingsTest.
$editors = ['guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 303, 'grandparent' => 303, 'guestPreset' => 403, 'noGrants' => 403];
$noOne = ['guest' => 302, 'admin' => 403, 'parent' => 403, 'child' => 403, 'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403];

$item = fn (array $users) => ['item' => ShoppingCatalogItem::factory()->create()->id];
$store = fn (array $users) => ['store' => ShoppingStore::factory()->create()->id];
$unit = fn (array $users) => ['unit' => ShoppingUnit::factory()->create()->id];
$unitData = ['name_en' => 'tin', 'name_sk' => 'plech', 'name_pl' => 'puszk'];

return [
    'settings.shopping' => ['method' => 'GET', 'uri' => '/settings/shopping', 'expect' => ['guest' => 302, 'admin' => 403, 'parent' => 200, 'child' => 200, 'grandparent' => 200, 'guestPreset' => 403, 'noGrants' => 403]],
    'settings.shopping.catalog.store' => ['method' => 'POST', 'uri' => '/settings/shopping/catalog', 'data' => fn () => ['name' => 'Matrix item '.uniqid()], 'expect' => $editors],
    'settings.shopping.catalog.update' => ['method' => 'PUT', 'uri' => '/settings/shopping/catalog/{item}', 'params' => $item, 'data' => fn () => ['name' => 'Renamed item '.uniqid()], 'expect' => $editors],
    'settings.shopping.catalog.destroy' => ['method' => 'DELETE', 'uri' => '/settings/shopping/catalog/{item}', 'params' => $item, 'expect' => $editors],
    'settings.shopping.stores.store' => ['method' => 'POST', 'uri' => '/settings/shopping/stores', 'data' => fn () => ['name' => 'Matrix store '.uniqid()], 'expect' => $editors],
    'settings.shopping.stores.update' => ['method' => 'PUT', 'uri' => '/settings/shopping/stores/{store}', 'params' => $store, 'data' => fn () => ['name' => 'Renamed store '.uniqid()], 'expect' => $editors],
    'settings.shopping.stores.destroy' => ['method' => 'DELETE', 'uri' => '/settings/shopping/stores/{store}', 'params' => $store, 'expect' => $editors],
    'settings.shopping.units.store' => ['method' => 'POST', 'uri' => '/settings/shopping/units', 'data' => $unitData, 'expect' => $noOne],
    'settings.shopping.units.update' => ['method' => 'PUT', 'uri' => '/settings/shopping/units/{unit}', 'params' => $unit, 'data' => $unitData, 'expect' => $noOne],
    'settings.shopping.units.destroy' => ['method' => 'DELETE', 'uri' => '/settings/shopping/units/{unit}', 'params' => $unit, 'expect' => $noOne],
];
