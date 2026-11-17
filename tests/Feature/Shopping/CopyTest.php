<?php

use App\Access\AccessLevel;
use App\Models\ShoppingItem;
use App\Models\ShoppingList;
use App\Models\ShoppingStore;
use App\Models\ShoppingUnit;
use App\Shopping\ItemState;
use App\Shopping\ListStateService;
use App\Shopping\ListStatus;
use App\Shopping\MemberRole;
use App\Shopping\SourceKind;
use Tests\Support\ShoppingFixtures as F;

function copyScene(ListStatus $status): array
{
    $creator = F::user();
    $resp = F::user();
    $contrib = F::user();
    $viewer = F::user(AccessLevel::View);
    $store = ShoppingStore::factory()->create();
    $list = F::list($creator, $resp, $status, [$contrib], [$viewer]);
    $list->forceFill(['store_id' => $store->id, 'remind_at' => now()->addDay()])->save();
    $kg = ShoppingUnit::where('key', 'kg')->value('id');
    F::item($list, ['name' => 'Apples', 'quantity' => '1.50', 'unit_id' => $kg, 'note' => 'red', 'state' => ItemState::Bought]);
    F::item($list, ['name' => 'Pears', 'quantity' => '3', 'state' => ItemState::Unavailable]);

    return compact('creator', 'resp', 'contrib', 'viewer', 'store', 'list', 'kg');
}

it('copies from any status as an open list owned by the copier with unmarked items', function (ListStatus $status) {
    ['contrib' => $contrib, 'store' => $store, 'list' => $list, 'kg' => $kg] = copyScene($status);

    $copy = app(ListStateService::class)->copy($contrib, $list->id, 'My copy', false);

    expect($copy->creator_id)->toBe($contrib->id)
        ->and($copy->responsible_id)->toBe($contrib->id)
        ->and($copy->status)->toBe(ListStatus::Open)
        ->and($copy->store_id)->toBe($store->id)
        ->and($copy->remind_at)->toBeNull()
        ->and($copy->source_kind)->toBe(SourceKind::Copy)
        ->and($copy->source_list_id)->toBe($list->id)
        ->and($copy->members()->count())->toBe(0);

    $rows = $copy->items()->get();
    expect($rows->pluck('name')->all())->toBe(['Apples', 'Pears'])
        ->and($rows->every(fn (ShoppingItem $i) => $i->state === ItemState::Pending && $i->state_changed_at === null))->toBeTrue()
        ->and($rows[0]->quantity)->toBe('1.50')
        ->and($rows[0]->unit_id)->toBe($kg)
        ->and($rows[0]->note)->toBe('red')
        ->and($list->fresh()->status)->toBe($status)
        ->and($list->items()->count())->toBe(2);
})->with([ListStatus::Open, ListStatus::Locked, ListStatus::Done]);

it('keeps store, responsible and members with keep and adds the original creator as contributor', function () {
    ['creator' => $creator, 'resp' => $resp, 'contrib' => $contrib, 'viewer' => $viewer, 'list' => $list] = copyScene(ListStatus::Done);
    $copier = F::user();
    F::member($list, $copier, MemberRole::Viewer);

    $copy = app(ListStateService::class)->copy($copier, $list->id, 'Kept', true);

    expect($copy->creator_id)->toBe($copier->id)
        ->and($copy->responsible_id)->toBe($resp->id)
        ->and($copy->members()->get()->mapWithKeys(fn ($m) => [$m->user_id => $m->role])->all())
        ->toBe([$contrib->id => MemberRole::Contributor, $viewer->id => MemberRole::Viewer, $creator->id => MemberRole::Contributor]);
});

it('adds the original creator as viewer when they only hold Shopping view', function () {
    ['creator' => $creator, 'list' => $list] = copyScene(ListStatus::Open);
    F::setLevel($creator, AccessLevel::View);
    $copier = F::user();
    F::member($list, $copier, MemberRole::Contributor);

    $copy = app(ListStateService::class)->copy($copier, $list->id, 'Kept', true);

    expect($copy->members()->where('user_id', $creator->id)->first()->role)->toBe(MemberRole::Viewer);
});

it('does not add the original creator when they are the copier or the responsible', function () {
    $creator = F::user();
    $list = F::list($creator);
    F::item($list);

    $own = app(ListStateService::class)->copy($creator, $list->id, 'Own', true);
    expect($own->members()->count())->toBe(0)->and($own->responsible_id)->toBe($creator->id);

    $resp = F::user();
    $list2 = F::list($creator, $resp);
    $copy = app(ListStateService::class)->copy($resp, $list2->id, 'By responsible', true);
    expect($copy->members()->pluck('user_id')->all())->toBe([$creator->id])
        ->and($copy->responsible_id)->toBe($resp->id);
});

it('keeps the effective responsible when the stored one cannot act', function () {
    ['creator' => $creator, 'resp' => $resp, 'contrib' => $contrib, 'list' => $list] = copyScene(ListStatus::Open);
    F::setLevel($resp, AccessLevel::View);

    $copy = app(ListStateService::class)->copy($contrib, $list->id, 'Kept', true);

    expect($copy->responsible_id)->toBe($creator->id)
        ->and($copy->members()->where('user_id', $creator->id)->count())->toBe(0);
});

it('lets a viewer who holds Shopping edit copy and refuses a view-only member and outsiders', function () {
    $creator = F::user();
    $editViewer = F::user();
    $viewOnly = F::user(AccessLevel::View);
    $list = F::list($creator, viewers: [$editViewer, $viewOnly]);

    expect(app(ListStateService::class)->copy($editViewer, $list->id, 'Mine', false)->creator_id)->toBe($editViewer->id)
        ->and(F::status(fn () => app(ListStateService::class)->copy($viewOnly, $list->id, 'Nope', false)))->toBe(403)
        ->and(F::status(fn () => app(ListStateService::class)->copy(F::user(), $list->id, 'Nope', false)))->toBe(404)
        ->and(ShoppingList::count())->toBe(2);
});

it('refuses an empty copy name and truncates a long one', function () {
    $creator = F::user();
    $list = F::list($creator);

    expect(F::errors(fn () => app(ListStateService::class)->copy($creator, $list->id, '  ', false)))->toHaveKey('name')
        ->and(mb_strlen(app(ListStateService::class)->copy($creator, $list->id, str_repeat('c', 100), false)->name))->toBe(80);
});
