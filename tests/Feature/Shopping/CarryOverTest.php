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

function carryScene(): array
{
    $creator = F::user();
    $resp = F::user();
    $contrib = F::user();
    $viewer = F::user(AccessLevel::View);
    $store = ShoppingStore::factory()->create();
    $list = F::list($creator, $resp, ListStatus::Locked, [$contrib], [$viewer]);
    $list->forceFill(['store_id' => $store->id, 'remind_at' => now()->addDay()])->save();
    F::item($list, ['name' => 'Bought one', 'state' => ItemState::Bought]);
    F::item($list, ['name' => 'Gone A', 'quantity' => '2.50', 'unit_id' => ShoppingUnit::where('key', 'kg')->value('id'), 'state' => ItemState::Unavailable, 'note' => 'ripe']);
    F::item($list, ['name' => 'Gone B', 'state' => ItemState::Unavailable]);

    return compact('creator', 'resp', 'contrib', 'viewer', 'store', 'list');
}

it('creates one open carry list with only the unavailable items and the same people', function () {
    ['creator' => $creator, 'resp' => $resp, 'contrib' => $contrib, 'viewer' => $viewer, 'store' => $store, 'list' => $list] = carryScene();

    $result = app(ListStateService::class)->finish($resp, $list->id, 'Groceries (unbought)');
    $carry = $result['carry'];

    expect($carry)->not->toBeNull()
        ->and($carry->name)->toBe('Groceries (unbought)')
        ->and($carry->status)->toBe(ListStatus::Open)
        ->and($carry->creator_id)->toBe($creator->id)
        ->and($carry->responsible_id)->toBe($resp->id)
        ->and($carry->store_id)->toBe($store->id)
        ->and($carry->remind_at)->toBeNull()
        ->and($carry->source_kind)->toBe(SourceKind::Carry)
        ->and($carry->source_list_id)->toBe($list->id)
        ->and($carry->members()->get()->mapWithKeys(fn ($m) => [$m->user_id => $m->role])->all())
        ->toBe([$contrib->id => MemberRole::Contributor, $viewer->id => MemberRole::Viewer]);

    $rows = $carry->items()->get();
    expect($rows->pluck('name')->all())->toBe(['Gone A', 'Gone B'])
        ->and($rows->every(fn (ShoppingItem $i) => $i->state === ItemState::Pending && $i->state_changed_at === null))->toBeTrue()
        ->and($rows[0]->quantity)->toBe('2.50')
        ->and($rows[0]->unit_id)->toBe(ShoppingUnit::where('key', 'kg')->value('id'))
        ->and($rows[0]->note)->toBe('ripe');
});

it('returns the same carry list on a replay and keeps exactly one', function () {
    ['resp' => $resp, 'list' => $list] = carryScene();
    $service = app(ListStateService::class);

    $first = $service->finish($resp, $list->id, 'Again');
    $again = $service->finish($resp, $list->id, 'Another name');

    expect($again['carry']->id)->toBe($first['carry']->id)
        ->and($again['carry']->name)->toBe('Again')
        ->and(ShoppingList::where('source_kind', 'carry')->count())->toBe(1)
        ->and($again['list']->done_at->getTimestamp())->toBe($first['list']->done_at->getTimestamp())
        ->and(ShoppingItem::where('list_id', $first['carry']->id)->count())->toBe(2);
});

it('creates no carry list when nothing was unavailable', function () {
    $creator = F::user();
    $list = F::list($creator, status: ListStatus::Locked);
    F::item($list, ['state' => ItemState::Bought]);

    $result = app(ListStateService::class)->finish($creator, $list->id, 'Leftovers');

    expect($result['carry'])->toBeNull()
        ->and(ShoppingList::count())->toBe(1);
});

it('returns the existing carry list when the unique index trips, and still finishes', function () {
    ['resp' => $resp, 'list' => $list] = carryScene();
    $existing = new ShoppingList(['name' => 'Pre-existing', 'responsible_id' => $resp->id]);
    $existing->forceFill(['creator_id' => $list->creator_id, 'status' => ListStatus::Open, 'source_list_id' => $list->id, 'source_kind' => SourceKind::Carry])->save();

    $result = app(ListStateService::class)->finish($resp, $list->id, 'Carry');

    expect($result['carry']->id)->toBe($existing->id)
        ->and($result['list']->status)->toBe(ListStatus::Done)
        ->and(ShoppingList::where('source_kind', 'carry')->count())->toBe(1)
        ->and(ShoppingItem::where('list_id', $existing->id)->count())->toBe(0);
});

it('carries the effective responsible when the stored one lost Shopping edit', function () {
    ['creator' => $creator, 'resp' => $resp, 'list' => $list] = carryScene();
    F::setLevel($resp, AccessLevel::View);

    $result = app(ListStateService::class)->finish($creator, $list->id, 'Carry');

    expect($result['carry']->responsible_id)->toBe($creator->id)
        ->and($result['carry']->creator_id)->toBe($creator->id);
});

it('truncates a carry name to 80 characters', function () {
    $creator = F::user();
    $list = F::list($creator, status: ListStatus::Locked);
    F::item($list, ['state' => ItemState::Unavailable]);

    $result = app(ListStateService::class)->finish($creator, $list->id, str_repeat('n', 120));

    expect(mb_strlen($result['carry']->name))->toBe(80);
});

it('refuses a replay by someone who may not finish', function () {
    ['resp' => $resp, 'contrib' => $contrib, 'list' => $list] = carryScene();
    app(ListStateService::class)->finish($resp, $list->id, 'Carry');

    expect(F::status(fn () => app(ListStateService::class)->finish($contrib, $list->id, 'Carry')))->toBe(403)
        ->and(ShoppingList::where('source_kind', 'carry')->count())->toBe(1);
});
