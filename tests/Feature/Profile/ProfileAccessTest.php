<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Access\Preset;
use App\Models\User;
use App\Models\WishlistManager;
use App\Profile\ProfileAccess;
use App\Profile\ProfilePhotoAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('canEdit: self always, admin and manager only for a child', function () {
    $access = new ProfileAccess;
    $child = User::factory()->child()->create();
    $adult = User::factory()->withPreset(Preset::Parent)->create();
    $admin = User::factory()->admin()->create();
    $manager = User::factory()->create(); // no grants at all: the manager row alone suffices
    $other = User::factory()->withPreset(Preset::Parent)->create();
    WishlistManager::factory()->create(['owner_id' => $child->id, 'manager_id' => $manager->id]);
    WishlistManager::factory()->create(['owner_id' => $adult->id, 'manager_id' => $manager->id]);

    expect($access->canEdit($adult, $adult))->toBeTrue()
        ->and($access->canEdit($child, $child))->toBeTrue()
        ->and($access->canEdit($admin, $child))->toBeTrue()
        ->and($access->canEdit($manager, $child))->toBeTrue()
        ->and($access->canEdit($admin, $adult))->toBeFalse()
        ->and($access->canEdit($manager, $adult))->toBeFalse()
        ->and($access->canEdit($other, $child))->toBeFalse();
});

it('canView: self, admin, manager, any member with View on a module; not a signed-in user without grants', function () {
    $subject = User::factory()->child()->create();
    $admin = User::factory()->admin()->create();
    $manager = User::factory()->create();
    WishlistManager::factory()->create(['owner_id' => $subject->id, 'manager_id' => $manager->id]);
    $todoOnly = User::factory()->withGrant(Module::Todo, AccessLevel::View)->create();
    $nobody = User::factory()->create();

    $access = fn () => app(ProfilePhotoAccess::class);
    expect($access()->canView($subject, $subject))->toBeTrue()
        ->and($access()->canView($admin, $subject))->toBeTrue()
        ->and($access()->canView($manager, $subject))->toBeTrue()
        ->and($access()->canView($todoOnly, $subject))->toBeTrue()
        ->and($access()->canView($nobody, $subject))->toBeFalse();
});
