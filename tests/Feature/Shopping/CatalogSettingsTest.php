<?php

use App\Access\AccessLevel;
use App\Models\ShoppingCatalogItem;
use App\Models\ShoppingItem;
use App\Models\ShoppingList;
use App\Models\ShoppingStore;
use App\Models\ShoppingUnit;
use App\Shopping\ListStatus;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFixtures;
use Tests\Support\ShoppingFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->editor = ShoppingFixtures::user(AccessLevel::Edit);
});

it('creates a catalog item with a default unit and refuses duplicates by case or accent', function () {
    $litre = ShoppingUnit::query()->whereNotNull('key')->firstOrFail();

    $this->actingAs($this->editor)->post('/settings/shopping/catalog', ['name' => '  Mlieko ', 'default_unit_id' => $litre->id])
        ->assertStatus(303)->assertSessionHas('toast');
    $row = ShoppingCatalogItem::query()->sole();
    expect($row->name)->toBe('Mlieko')->and($row->default_unit_id)->toBe($litre->id)->and($row->created_by)->toBe($this->editor->id);

    $this->actingAs($this->editor)->post('/settings/shopping/catalog', ['name' => 'mliéko'])
        ->assertSessionHasErrors(['name' => 'shopping.settings.err_duplicate']);
    expect(ShoppingCatalogItem::query()->count())->toBe(1);
});

it('validates name length and the unit id', function () {
    $this->actingAs($this->editor)->post('/settings/shopping/catalog', ['name' => str_repeat('a', 81)])->assertSessionHasErrors('name');
    $this->actingAs($this->editor)->post('/settings/shopping/catalog', ['name' => '   '])->assertSessionHasErrors('name');
    $this->actingAs($this->editor)->post('/settings/shopping/catalog', ['name' => 'Chlieb', 'default_unit_id' => 99999])->assertSessionHasErrors('default_unit_id');
    expect(ShoppingCatalogItem::query()->count())->toBe(0);
});

it('renames an item, allows keeping its own name and refuses another item\'s name', function () {
    $a = ShoppingCatalogItem::factory()->create(['name' => 'Maslo']);
    ShoppingCatalogItem::factory()->create(['name' => 'Syr']);

    $this->actingAs($this->editor)->put("/settings/shopping/catalog/{$a->id}", ['name' => 'MASLO'])->assertStatus(303);
    expect($a->fresh()->name)->toBe('MASLO');
    $this->actingAs($this->editor)->put("/settings/shopping/catalog/{$a->id}", ['name' => 'syr'])
        ->assertSessionHasErrors(['name' => 'shopping.settings.err_duplicate']);
});

it('deletes an item used on two lists, keeps the list items readable and removes the image files', function () {
    $item = ShoppingCatalogItem::factory()->create(['name' => 'Jablka']);
    $this->actingAs($this->editor)->post("/shopping/catalog/{$item->id}/image", ['image' => UploadedFile::fake()->createWithContent('a.jpg', ImageFixtures::jpeg())])->assertStatus(303);
    $paths = [$item->fresh()->image_path, $item->fresh()->thumb_path];
    $one = ShoppingFixtures::item(ShoppingFixtures::list($this->editor), ['catalog_item_id' => $item->id, 'name' => 'Jablka', 'quantity' => 3]);
    $two = ShoppingFixtures::item(ShoppingFixtures::list($this->editor), ['catalog_item_id' => $item->id, 'name' => 'Jablka', 'quantity' => 1.5]);

    $this->actingAs($this->editor)->delete("/settings/shopping/catalog/{$item->id}")->assertStatus(303);

    expect(ShoppingCatalogItem::query()->count())->toBe(0);
    foreach ([$one, $two] as $i) {
        $fresh = ShoppingItem::query()->findOrFail($i->id);
        expect($fresh->catalog_item_id)->toBeNull()->and($fresh->name)->toBe('Jablka');
    }
    expect((float) ShoppingItem::query()->findOrFail($one->id)->quantity)->toBe(3.0);
    foreach ($paths as $p) {
        Storage::disk('local')->assertMissing($p);
    }
    $this->actingAs($this->editor)->delete("/settings/shopping/catalog/{$item->id}")->assertNotFound();
});

it('creates, renames and deletes stores and refuses duplicates', function () {
    $this->actingAs($this->editor)->post('/settings/shopping/stores', ['name' => 'Tesco'])->assertStatus(303);
    $store = ShoppingStore::query()->sole();
    $this->actingAs($this->editor)->post('/settings/shopping/stores', ['name' => 'TESCO'])->assertSessionHasErrors(['name' => 'shopping.settings.err_duplicate']);
    $this->actingAs($this->editor)->post('/settings/shopping/stores', ['name' => str_repeat('a', 61)])->assertSessionHasErrors('name');
    $this->actingAs($this->editor)->put("/settings/shopping/stores/{$store->id}", ['name' => 'Lidl'])->assertStatus(303);
    expect($store->fresh()->name)->toBe('Lidl');

    $open = ShoppingFixtures::list($this->editor);
    $open->forceFill(['store_id' => $store->id])->save();
    $done = ShoppingFixtures::list($this->editor, status: ListStatus::Done);
    $done->forceFill(['store_id' => $store->id, 'store_name' => 'Lidl'])->save();

    $this->actingAs($this->editor)->delete("/settings/shopping/stores/{$store->id}")->assertStatus(303);

    expect(ShoppingStore::query()->count())->toBe(0)
        ->and(ShoppingList::query()->findOrFail($open->id)->store_id)->toBeNull()
        ->and(ShoppingList::query()->findOrFail($done->id)->store_id)->toBeNull()
        ->and(ShoppingList::query()->findOrFail($done->id)->store_name)->toBe('Lidl');
});

it('refuses every catalog and store write to a view-only member', function () {
    $viewer = ShoppingFixtures::user(AccessLevel::View);
    $item = ShoppingCatalogItem::factory()->create();
    $store = ShoppingStore::factory()->create();

    $this->actingAs($viewer)->post('/settings/shopping/catalog', ['name' => 'X'])->assertForbidden();
    $this->actingAs($viewer)->put("/settings/shopping/catalog/{$item->id}", ['name' => 'Y'])->assertForbidden();
    $this->actingAs($viewer)->delete("/settings/shopping/catalog/{$item->id}")->assertForbidden();
    $this->actingAs($viewer)->post('/settings/shopping/stores', ['name' => 'X'])->assertForbidden();
    $this->actingAs($viewer)->put("/settings/shopping/stores/{$store->id}", ['name' => 'Y'])->assertForbidden();
    $this->actingAs($viewer)->delete("/settings/shopping/stores/{$store->id}")->assertForbidden();
    expect(ShoppingCatalogItem::query()->count())->toBe(1)->and(ShoppingStore::query()->count())->toBe(1);
});

it('returns the id of a created catalog item or store in the flash data', function () {
    $this->actingAs($this->editor)->post('/settings/shopping/catalog', ['name' => 'Čaj'])
        ->assertStatus(303)->assertSessionHas('flash_data.created_id', ShoppingCatalogItem::query()->where('name', 'Čaj')->value('id'));
    $this->actingAs($this->editor)->post('/settings/shopping/stores', ['name' => 'Billa'])
        ->assertStatus(303)->assertSessionHas('flash_data.created_id', ShoppingStore::query()->where('name', 'Billa')->value('id'));
});
