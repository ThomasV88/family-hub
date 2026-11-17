<?php

use App\Access\AccessLevel;
use App\Models\ShoppingCatalogItem;
use App\Models\ShoppingItem;
use App\Models\ShoppingUnit;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ShoppingFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->admin = User::factory()->admin()->create();
    ShoppingFixtures::setLevel($this->admin, AccessLevel::Edit);
});

it('lets an administrator with Shopping edit create, rename and delete a custom unit', function () {
    $this->actingAs($this->admin)->post('/settings/shopping/units', ['name_en' => 'can', 'name_sk' => 'plechovka', 'name_pl' => 'puszka'])
        ->assertStatus(303)->assertSessionHas('toast');
    $unit = ShoppingUnit::query()->whereNull('key')->sole();
    expect([$unit->name_en, $unit->name_sk, $unit->name_pl])->toBe(['can', 'plechovka', 'puszka'])
        ->and($unit->created_by)->toBe($this->admin->id);

    $this->actingAs($this->admin)->put("/settings/shopping/units/{$unit->id}", ['name_en' => 'tin', 'name_sk' => 'plechovka', 'name_pl' => 'puszka'])->assertStatus(303);
    expect($unit->fresh()->name_en)->toBe('tin');

    $this->actingAs($this->admin)->delete("/settings/shopping/units/{$unit->id}")->assertStatus(303);
    expect(ShoppingUnit::query()->whereNull('key')->count())->toBe(0);
});

it('refuses non-administrators, even with Shopping edit', function () {
    $parent = ShoppingFixtures::user(AccessLevel::Edit);
    $unit = ShoppingUnit::factory()->create();

    $this->actingAs($parent)->post('/settings/shopping/units', ['name_en' => 'can', 'name_sk' => 'plech', 'name_pl' => 'puszka'])->assertForbidden();
    $this->actingAs($parent)->put("/settings/shopping/units/{$unit->id}", ['name_en' => 'x', 'name_sk' => 'y', 'name_pl' => 'z'])->assertForbidden();
    $this->actingAs($parent)->delete("/settings/shopping/units/{$unit->id}")->assertForbidden();
    expect(ShoppingUnit::query()->whereNull('key')->count())->toBe(1);
});

it('refuses an administrator without Shopping edit', function () {
    ShoppingFixtures::setLevel($this->admin, AccessLevel::View);

    $this->actingAs($this->admin)->post('/settings/shopping/units', ['name_en' => 'can', 'name_sk' => 'plech', 'name_pl' => 'puszka'])->assertForbidden();
});

it('refuses a duplicate name in any language case-insensitively and validates length', function () {
    ShoppingUnit::factory()->create(['name_en' => 'Can', 'name_sk' => 'Plechovka', 'name_pl' => 'Puszka']);

    $this->actingAs($this->admin)->post('/settings/shopping/units', ['name_en' => 'jar', 'name_sk' => 'plechovka', 'name_pl' => 'sloik'])
        ->assertSessionHasErrors(['name_sk' => 'shopping.settings.err_duplicate']);
    $this->actingAs($this->admin)->post('/settings/shopping/units', ['name_en' => str_repeat('a', 13), 'name_sk' => 'x', 'name_pl' => 'y'])->assertSessionHasErrors('name_en');
    $this->actingAs($this->admin)->post('/settings/shopping/units', ['name_en' => 'a', 'name_sk' => '', 'name_pl' => 'y'])->assertSessionHasErrors('name_sk');
    expect(ShoppingUnit::query()->whereNull('key')->count())->toBe(1);
});

it('never changes or deletes a built-in unit', function () {
    $builtIn = ShoppingUnit::query()->whereNotNull('key')->firstOrFail();

    $this->actingAs($this->admin)->put("/settings/shopping/units/{$builtIn->id}", ['name_en' => 'a', 'name_sk' => 'b', 'name_pl' => 'c'])
        ->assertSessionHasErrors(['unit' => 'shopping.settings.err_builtin_unit']);
    $this->actingAs($this->admin)->delete("/settings/shopping/units/{$builtIn->id}")
        ->assertSessionHasErrors(['unit' => 'shopping.settings.err_builtin_unit']);
    expect(ShoppingUnit::query()->whereKey($builtIn->id)->exists())->toBeTrue()->and($builtIn->fresh()->name_en)->toBeNull();
});

it('nulls item and catalog references when a used custom unit is deleted', function () {
    $unit = ShoppingUnit::factory()->create();
    $catalog = ShoppingCatalogItem::factory()->create(['default_unit_id' => $unit->id]);
    $item = ShoppingFixtures::item(ShoppingFixtures::list($this->admin), ['unit_id' => $unit->id, 'name' => 'Fazula', 'quantity' => 2]);

    $this->actingAs($this->admin)->delete("/settings/shopping/units/{$unit->id}")->assertStatus(303);

    expect($catalog->fresh()->default_unit_id)->toBeNull()
        ->and(ShoppingItem::query()->findOrFail($item->id)->unit_id)->toBeNull()
        ->and(ShoppingItem::query()->findOrFail($item->id)->name)->toBe('Fazula');
});
