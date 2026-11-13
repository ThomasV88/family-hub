<?php

use App\Access\Preset;
use App\Models\User;

it('never serialises birth_date from the model', function () {
    $user = User::factory()->create(['birth_date' => '1990-03-15']);

    expect($user->fresh()->toArray())->not->toHaveKey('birth_date')
        ->and(json_decode($user->fresh()->toJson(), true))->not->toHaveKey('birth_date')
        ->and($user->fresh()->birth_date->format('Y-m-d'))->toBe('1990-03-15');
});

it('keeps birth_date out of the shared auth.user prop', function () {
    $user = User::factory()->withPreset(Preset::Parent)->create(['birth_date' => '1990-03-15']);

    $page = $this->actingAs($user)->get('/wishlist')->assertOk()->viewData('page');

    expect($page['props']['auth']['user'])->not->toHaveKey('birth_date');
    expect(json_encode($page['props']['auth']))->not->toContain('1990');
});
