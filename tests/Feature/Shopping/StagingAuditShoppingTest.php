<?php

use App\Models\ShoppingCatalogItem;
use App\Models\ShoppingItem;
use App\Models\ShoppingList;
use App\Models\ShoppingStore;
use App\Models\ShoppingUnit;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->originalEnv = app()->environment();
    app()['env'] = 'staging';
    Storage::fake('medical');
    Storage::fake('local');
    $this->synthetic = User::factory()->create(['email' => 'a.1@example.test']);
});

afterEach(function () {
    app()['env'] = $this->originalEnv;
});

/** A real user makes the generic users rule fail too; only tests that expect a failure create one. */
function auditRealUser(): User
{
    return User::query()->where('email', 'user@real.sk')->first() ?? User::factory()->create(['email' => 'user@real.sk']);
}

function auditOwned(string $model, string $name, User $by): void
{
    $row = new $model(['name' => $name]);
    $row->forceFill(['created_by' => $by->id])->save();
}

it('passes on the seeded demo and on built-in units', function () {
    $this->artisan('staging:seed --force')->assertExitCode(0);
    expect(ShoppingUnit::whereNull('created_by')->whereNotNull('key')->count())->toBe(9);

    $this->artisan('staging:audit')->assertExitCode(0);
});

it('passes with shopping rows owned only by synthetic users', function () {
    $list = ShoppingList::factory()->create(['creator_id' => $this->synthetic->id]);
    ShoppingItem::factory()->create(['list_id' => $list->id, 'created_by' => $this->synthetic->id]);
    DB::table('shopping_list_members')->insert(['list_id' => $list->id, 'user_id' => $this->synthetic->id, 'role' => 'viewer']);
    auditOwned(ShoppingCatalogItem::class, 'Mlieko', $this->synthetic);
    auditOwned(ShoppingStore::class, 'Demo', $this->synthetic);

    $this->artisan('staging:audit')->assertExitCode(0);
});

it('fails when a list is created by a real user and names the table without personal data', function () {
    ShoppingList::factory()->create(['creator_id' => auditRealUser()->id, 'name' => 'Secret family list']);

    $this->artisan('staging:audit')
        ->expectsOutputToContain('non-synthetic shopping_lists')
        ->doesntExpectOutputToContain('Secret family list')
        ->doesntExpectOutputToContain('real.sk')
        ->assertExitCode(1);
});

it('fails when the responsible person of a list is a real user', function () {
    ShoppingList::factory()->create(['creator_id' => $this->synthetic->id, 'responsible_id' => auditRealUser()->id]);

    $this->artisan('staging:audit')->expectsOutputToContain('non-synthetic shopping_lists')->assertExitCode(1);
});

it('fails when a real user is a list member', function () {
    $list = ShoppingList::factory()->create(['creator_id' => $this->synthetic->id]);
    DB::table('shopping_list_members')->insert(['list_id' => $list->id, 'user_id' => auditRealUser()->id, 'role' => 'viewer']);

    $this->artisan('staging:audit')->expectsOutputToContain('non-synthetic shopping_list_members')->assertExitCode(1);
});

it('fails when an item was created or marked by a real user', function () {
    $list = ShoppingList::factory()->create(['creator_id' => $this->synthetic->id]);
    ShoppingItem::factory()->create(['list_id' => $list->id, 'created_by' => auditRealUser()->id]);
    ShoppingItem::factory()->bought()->create(['list_id' => $list->id, 'created_by' => $this->synthetic->id, 'state_changed_by' => auditRealUser()->id]);

    $this->artisan('staging:audit')->expectsOutputToContain('non-synthetic shopping_items')->assertExitCode(1);
});

it('fails when a catalog item, a store or a unit was created by a real user', function () {
    auditOwned(ShoppingCatalogItem::class, 'Real catalog', auditRealUser());
    auditOwned(ShoppingStore::class, 'Real store', auditRealUser());
    $unit = ShoppingUnit::factory()->create();
    $unit->forceFill(['created_by' => auditRealUser()->id])->save();

    $this->artisan('staging:audit')
        ->expectsOutputToContain('non-synthetic shopping_catalog_items')
        ->expectsOutputToContain('non-synthetic shopping_stores')
        ->expectsOutputToContain('non-synthetic shopping_units')
        ->assertExitCode(1);
});

it('keeps real shopping rows and their picture folders through a reseed, so the audit keeps failing', function () {
    $this->artisan('staging:seed --force')->assertExitCode(0);
    auditOwned(ShoppingCatalogItem::class, 'Real catalog', auditRealUser());
    ShoppingList::factory()->create(['creator_id' => auditRealUser()->id]);

    $this->artisan('staging:seed --force')->assertExitCode(0);

    expect(ShoppingList::where('creator_id', auditRealUser()->id)->count())->toBe(1)
        ->and(ShoppingCatalogItem::where('name', 'Real catalog')->count())->toBe(1);
    $this->artisan('staging:audit')->assertExitCode(1);
});
