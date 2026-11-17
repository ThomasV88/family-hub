<?php

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\User;
use App\Recipes\RecipeAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RecipeFixtures;

uses(RefreshDatabase::class);

it('lets the author with edit view and edit', function () {
    $a = RecipeFixtures::member('edit');
    $r = RecipeFixtures::privateRecipe($a);
    $access = app(RecipeAccess::class);
    expect($access->mayView($a, $r))->toBeTrue()->and($access->mayEdit($a, $r))->toBeTrue();
    expect($access->abilities($a, $r))->toMatchArray(['edit' => true, 'delete' => true, 'changeVisibility' => true]);
});

it('keeps a view-only author reading but not writing', function () {
    $a = RecipeFixtures::member('view');
    $r = RecipeFixtures::privateRecipe($a);
    $access = app(RecipeAccess::class);
    expect($access->mayView($a, $r))->toBeTrue()->and($access->mayEdit($a, $r))->toBeFalse();
    expect($access->abilities($a, $r)['edit'])->toBeFalse();
});

it('shows shared recipes to others read-only and hides private ones', function () {
    $a = RecipeFixtures::member('edit');
    $b = RecipeFixtures::member('edit');
    $shared = RecipeFixtures::sharedRecipe($a);
    $private = RecipeFixtures::privateRecipe($a);
    $access = app(RecipeAccess::class);
    expect($access->mayView($b, $shared))->toBeTrue()->and($access->mayEdit($b, $shared))->toBeFalse();
    expect($access->mayView($b, $private))->toBeFalse()->and($access->mayEdit($b, $private))->toBeFalse();
});

it('gives an administrator no bypass', function () {
    $a = RecipeFixtures::member('edit');
    $admin = RecipeFixtures::member('edit');
    $admin->forceFill(['is_admin' => true])->save();
    $r = RecipeFixtures::privateRecipe($a);
    $access = app(RecipeAccess::class);
    expect($access->mayView($admin, $r))->toBeFalse()->and($access->mayEdit($admin, $r))->toBeFalse();
});

it('allows add-to-shopping only with shopping edit', function () {
    $a = RecipeFixtures::member('edit');
    $r = RecipeFixtures::privateRecipe($a);
    expect(app(RecipeAccess::class)->abilities($a, $r)['addToShopping'])->toBeFalse();

    $b = RecipeFixtures::member('edit', [Module::Shopping->value => AccessLevel::Edit]);
    $r2 = RecipeFixtures::privateRecipe($b);
    expect(app(RecipeAccess::class)->abilities($b, $r2)['addToShopping'])->toBeTrue();

    $c = RecipeFixtures::member('edit', [Module::Shopping->value => AccessLevel::View]);
    expect(app(RecipeAccess::class)->abilities($c, RecipeFixtures::privateRecipe($c))['addToShopping'])->toBeFalse();
    app(AccessGate::class)->forget(User::first());
});
