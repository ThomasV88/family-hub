<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Access\Preset;

function levelsOf(Preset $preset): array
{
    return array_map(fn (AccessLevel $l) => $l->label(), $preset->grants());
}

it('defines the Parent preset exactly', function () {
    expect(levelsOf(Preset::Parent))->toBe(['todo' => 'edit', 'wishlist' => 'edit', 'finance' => 'edit', 'health' => 'none']);
});

it('defines Child and Grandparent exactly', function () {
    $expected = ['todo' => 'edit', 'wishlist' => 'edit', 'finance' => 'none', 'health' => 'none'];
    expect(levelsOf(Preset::Child))->toBe($expected)
        ->and(levelsOf(Preset::Grandparent))->toBe($expected);
});

it('defines Guest as todo view only', function () {
    expect(levelsOf(Preset::Guest))->toBe(['todo' => 'view', 'wishlist' => 'none', 'finance' => 'none', 'health' => 'none']);
});

it('covers all four modules in every preset', function () {
    foreach (Preset::cases() as $preset) {
        expect(array_keys($preset->grants()))->toBe(array_map(fn (Module $m) => $m->value, Module::cases()));
    }
});

it('marks only finance and health as audited', function () {
    expect(array_values(array_filter(Module::cases(), fn (Module $m) => $m->audited())))->toBe([Module::Finance, Module::Health]);
});
