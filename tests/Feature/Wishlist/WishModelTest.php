<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Access\Preset;
use App\Models\User;
use App\Models\Wish;
use App\Models\WishlistManager;
use App\Wishlist\ViewerContext;
use App\Wishlist\WishAccess;
use App\Wishlist\WishlistMembers;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('ignores protected attributes on mass assignment', function () {
    $other = User::factory()->create();

    $wish = new Wish([
        'title' => 'Book', 'owner_id' => $other->id, 'image_path' => 'x.jpg', 'image_status' => 'ready',
        'received_at' => now(), 'created_by_id' => $other->id,
    ]);

    expect($wish->owner_id)->toBeNull()->and($wish->image_path)->toBeNull()
        ->and($wish->received_at)->toBeNull()->and($wish->created_by_id)->toBeNull()
        ->and($wish->getAttributes())->not->toHaveKey('image_status')
        ->and($wish->title)->toBe('Book');
});

it('has no reservation surface on the model', function () {
    $wish = Wish::factory()->create();
    $keys = array_keys($wish->fresh()->toArray());
    $methods = get_class_methods(Wish::class);

    expect(collect($keys)->filter(fn ($k) => preg_match('/reserv|contrib/i', $k))->all())->toBe([])
        ->and(collect($methods)->filter(fn ($m) => preg_match('/reserv|contrib/i', $m))->all())->toBe([])
        ->and((new ReflectionClass(Wish::class))->getDefaultProperties()['with'] ?? [])->toBe([])
        ->and((new ReflectionClass(Wish::class))->getDefaultProperties()['appends'] ?? [])->toBe([]);
});

it('stores a price in minor units with a default currency', function () {
    $wish = Wish::factory()->create(['price_minor' => 1250]);

    expect($wish->fresh()->price_minor)->toBe(1250)->and($wish->fresh()->price_currency)->toBe('EUR');
});

it('rejects a negative price and a self-manager row at the database level', function () {
    $user = User::factory()->create();

    expect(fn () => Wish::factory()->create(['price_minor' => -1]))->toThrow(QueryException::class);
    expect(fn () => WishlistManager::factory()->create(['owner_id' => $user->id, 'manager_id' => $user->id]))->toThrow(QueryException::class);
});

it('knows who is recipient-side', function () {
    $owner = User::factory()->withPreset(Preset::Parent)->create();
    $manager = User::factory()->withPreset(Preset::Parent)->create();
    $editor = User::factory()->withPreset(Preset::Child)->create();
    $admin = User::factory()->admin()->create();
    WishlistManager::factory()->create(['owner_id' => $owner->id, 'manager_id' => $manager->id]);

    expect(ViewerContext::for($owner)->isRecipientSideOf($owner->id))->toBeTrue()
        ->and(ViewerContext::for($manager)->isRecipientSideOf($owner->id))->toBeTrue()
        ->and(ViewerContext::for($manager)->isManagerOf($owner->id))->toBeTrue()
        ->and(ViewerContext::for($owner)->isManagerOf($owner->id))->toBeFalse()
        ->and(ViewerContext::for($editor)->isRecipientSideOf($owner->id))->toBeFalse()
        ->and(ViewerContext::for($admin)->isRecipientSideOf($owner->id))->toBeFalse()
        ->and(ViewerContext::for($manager)->recipientSideOwnerIds())->toBe([$manager->id, $owner->id]);
});

it('builds the viewer context with one query', function () {
    $user = User::factory()->create();
    $count = 0;
    DB::listen(function () use (&$count) {
        $count++;
    });

    ViewerContext::for($user);

    expect($count)->toBe(1);
});

it('lets only the owner or a manager with the edit grant edit a list', function () {
    $access = app(WishAccess::class);
    $owner = User::factory()->withPreset(Preset::Parent)->create();
    $manager = User::factory()->withPreset(Preset::Child)->create();
    $stranger = User::factory()->withPreset(Preset::Grandparent)->create();
    $admin = User::factory()->admin()->create();
    $viewOnlyOwner = User::factory()->withGrant(Module::Wishlist, AccessLevel::View)->create();
    WishlistManager::factory()->create(['owner_id' => $owner->id, 'manager_id' => $manager->id]);

    expect($access->canEditList($owner, $owner->id))->toBeTrue()
        ->and($access->canEditList($manager, $owner->id))->toBeTrue()
        ->and($access->canEditList($stranger, $owner->id))->toBeFalse()
        ->and($access->canEditList($admin, $owner->id))->toBeFalse()
        ->and($access->canEditList($viewOnlyOwner, $viewOnlyOwner->id))->toBeFalse();
});

it('lists wishlist members with the viewer first then locale sorted', function () {
    $viewer = User::factory()->withPreset(Preset::Parent)->create(['name' => 'Zora']);
    User::factory()->withPreset(Preset::Child)->create(['name' => 'Štefan']);
    User::factory()->withPreset(Preset::Child)->create(['name' => 'Adam']);
    User::factory()->withPreset(Preset::Guest)->create(['name' => 'Nobody']);

    $names = array_column(app(WishlistMembers::class)->forViewer($viewer), 'name');

    expect($names)->toBe(['Zora', 'Adam', 'Štefan']);
});
