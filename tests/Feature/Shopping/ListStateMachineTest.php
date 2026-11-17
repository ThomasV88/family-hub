<?php

use App\Access\AccessLevel;
use App\Models\ShoppingItem;
use App\Models\ShoppingList;
use App\Models\ShoppingStore;
use App\Models\User;
use App\Shopping\ItemState;
use App\Shopping\ListStateService;
use App\Shopping\ListStatus;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\ShoppingFixtures as F;

function state(): ListStateService
{
    return app(ListStateService::class);
}

it('locks a list with items and records who and when', function () {
    $creator = F::user();
    $resp = F::user();
    $list = F::list($creator, $resp);
    F::item($list);

    $locked = state()->setLocked($resp, $list->id, true);

    expect($locked->status)->toBe(ListStatus::Locked)
        ->and($locked->locked_at)->not->toBeNull()
        ->and($locked->locked_by)->toBe($resp->id);
});

it('refuses to lock an empty list', function () {
    $creator = F::user();
    $list = F::list($creator);

    expect(F::errors(fn () => state()->setLocked($creator, $list->id, true)))->toBe(['list' => ['shopping.error.lock_empty']])
        ->and($list->fresh()->status)->toBe(ListStatus::Open);
});

it('treats a repeated lock or unlock as an unchanged success', function () {
    $creator = F::user();
    $list = F::list($creator);
    F::item($list);

    $first = state()->setLocked($creator, $list->id, true);
    $again = state()->setLocked($creator, $list->id, true);
    expect($again->locked_at->getTimestamp())->toBe($first->locked_at->getTimestamp());

    state()->setLocked($creator, $list->id, false);
    expect(state()->setLocked($creator, $list->id, false)->status)->toBe(ListStatus::Open);
});

it('keeps the marks when a list is unlocked', function () {
    $creator = F::user();
    $list = F::list($creator, status: ListStatus::Locked);
    $bought = F::item($list, ['state' => ItemState::Bought]);

    $open = state()->setLocked($creator, $list->id, false);

    expect($open->status)->toBe(ListStatus::Open)
        ->and($open->locked_at)->toBeNull()
        ->and($bought->fresh()->state)->toBe(ItemState::Bought);
});

it('refuses lock and unlock by a creator who is not responsible, a contributor and a viewer', function () {
    $creator = F::user();
    $resp = F::user();
    $contrib = F::user();
    $viewer = F::user();
    $list = F::list($creator, $resp, contributors: [$contrib], viewers: [$viewer]);
    F::item($list);

    foreach ([$creator, $contrib, $viewer] as $actor) {
        expect(F::status(fn () => state()->setLocked($actor, $list->id, true)))->toBe(403);
    }
    expect(F::status(fn () => state()->setLocked(F::user(), $list->id, true)))->toBe(404)
        ->and($list->fresh()->status)->toBe(ListStatus::Open);

    $list->forceFill(['status' => ListStatus::Locked, 'locked_at' => now()])->save();
    expect(F::status(fn () => state()->setLocked($creator, $list->id, false)))->toBe(403);
});

it('refuses to change the lock of a done list', function () {
    $creator = F::user();
    $list = F::list($creator, status: ListStatus::Done);

    expect(F::status(fn () => state()->setLocked($creator, $list->id, false)))->toBe(409)
        ->and(F::status(fn () => state()->setLocked($creator, $list->id, true)))->toBe(409);
});

it('sets item states absolutely and converges on repeats', function () {
    $creator = F::user();
    $list = F::list($creator, status: ListStatus::Locked);
    $item = F::item($list);

    $first = state()->setItemState($creator, $list->id, $item->id, ItemState::Bought);
    expect($first->state)->toBe(ItemState::Bought)
        ->and($first->state_changed_by)->toBe($creator->id)
        ->and($first->state_changed_at)->not->toBeNull();

    $again = state()->setItemState($creator, $list->id, $item->id, ItemState::Bought);
    expect($again->state_changed_at->getTimestamp())->toBe($first->state_changed_at->getTimestamp());

    expect(state()->setItemState($creator, $list->id, $item->id, ItemState::Unavailable)->state)->toBe(ItemState::Unavailable)
        ->and(state()->setItemState($creator, $list->id, $item->id, ItemState::Pending)->state)->toBe(ItemState::Pending);
});

it('refuses marks on an open list and by anyone but the effective responsible', function () {
    $creator = F::user();
    $resp = F::user();
    $contrib = F::user();
    $open = F::list($creator, $resp);
    $locked = F::list($creator, $resp, ListStatus::Locked, [$contrib]);
    $openItem = F::item($open);
    $lockedItem = F::item($locked);

    expect(F::status(fn () => state()->setItemState($resp, $open->id, $openItem->id, ItemState::Bought)))->toBe(409)
        ->and(F::status(fn () => state()->setItemState($contrib, $locked->id, $lockedItem->id, ItemState::Bought)))->toBe(403)
        ->and(F::status(fn () => state()->setItemState($creator, $locked->id, $lockedItem->id, ItemState::Bought)))->toBe(403)
        ->and(F::status(fn () => state()->setItemState(F::user(), $locked->id, $lockedItem->id, ItemState::Bought)))->toBe(404)
        ->and($lockedItem->fresh()->state)->toBe(ItemState::Pending);
});

it('does not mark an item of another list', function () {
    $creator = F::user();
    $list = F::list($creator, status: ListStatus::Locked);
    $foreign = F::item(F::list($creator, status: ListStatus::Locked));

    expect(fn () => state()->setItemState($creator, $list->id, $foreign->id, ItemState::Bought))
        ->toThrow(ModelNotFoundException::class);
});

it('refuses finish while an item is pending', function () {
    $creator = F::user();
    $list = F::list($creator, status: ListStatus::Locked);
    F::item($list, ['state' => ItemState::Bought]);
    F::item($list);

    expect(F::status(fn () => state()->finish($creator, $list->id, null)))->toBe(409)
        ->and($list->fresh()->status)->toBe(ListStatus::Locked);
});

it('refuses finish on an open list and by a non-responsible', function () {
    $creator = F::user();
    $resp = F::user();
    $open = F::list($creator, $resp);
    $locked = F::list($creator, $resp, ListStatus::Locked);
    F::item($locked, ['state' => ItemState::Bought]);

    expect(F::status(fn () => state()->finish($resp, $open->id, null)))->toBe(409)
        ->and(F::status(fn () => state()->finish($creator, $locked->id, null)))->toBe(403);
});

it('finishes a resolved list, snapshots the store name and creates no carry list without a name', function () {
    $creator = F::user();
    $store = ShoppingStore::factory()->create(['name' => 'Fresh Mart']);
    $list = F::list($creator, status: ListStatus::Locked);
    $list->forceFill(['store_id' => $store->id])->save();
    F::item($list, ['state' => ItemState::Bought]);
    F::item($list, ['state' => ItemState::Unavailable]);

    $result = state()->finish($creator, $list->id, null);

    expect($result['carry'])->toBeNull()
        ->and($result['list']->status)->toBe(ListStatus::Done)
        ->and($result['list']->done_at)->not->toBeNull()
        ->and($result['list']->done_by)->toBe($creator->id)
        ->and($result['list']->store_name)->toBe('Fresh Mart')
        ->and(ShoppingList::count())->toBe(1);
});

it('lets the creator lock, mark and finish once the responsible lost Shopping edit', function () {
    $creator = F::user();
    $resp = F::user();
    $list = F::list($creator, $resp);
    $item = F::item($list);
    F::setLevel($resp, AccessLevel::View);

    state()->setLocked($creator, $list->id, true);
    state()->setItemState($creator, $list->id, $item->id, ItemState::Bought);
    $result = state()->finish($creator, $list->id, null);

    expect($result['list']->status)->toBe(ListStatus::Done)
        ->and(F::status(fn () => state()->setLocked($resp, $list->id, false)))->toBe(403);
});

it('keeps the creator as responsible after a child was chosen and cannot act', function () {
    $creator = F::user();
    $child = F::user(AccessLevel::Edit, child: true);
    $list = F::list($creator, $child);
    F::item($list);

    expect(state()->setLocked($creator, $list->id, true)->status)->toBe(ListStatus::Locked);
});

it('hides lists from an administrator without a role', function () {
    $list = F::list(F::user(), status: ListStatus::Locked);
    $admin = User::factory()->admin()->create();

    expect(F::status(fn () => state()->finish($admin, $list->id, null)))->toBe(404)
        ->and(F::status(fn () => state()->setLocked($admin, $list->id, false)))->toBe(404);
});

it('does not touch items of a finished list through marks', function () {
    $creator = F::user();
    $list = F::list($creator, status: ListStatus::Done);
    $item = F::item($list, ['state' => ItemState::Bought]);

    expect(F::status(fn () => state()->setItemState($creator, $list->id, $item->id, ItemState::Pending)))->toBe(409)
        ->and(ShoppingItem::find($item->id)->state)->toBe(ItemState::Bought);
});
