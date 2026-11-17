<?php

use App\Access\AccessLevel;
use App\Models\ShoppingList;
use App\Models\ShoppingStore;
use App\Models\User;
use App\Shopping\ListService;
use App\Shopping\ListStatus;
use App\Shopping\MemberRole;
use App\Shopping\ShoppingAccess;
use Carbon\CarbonImmutable;
use Tests\Support\ShoppingFixtures as F;

function lists(): ListService
{
    return app(ListService::class);
}

it('creates a list owned by the actor who is responsible by default', function () {
    $actor = F::user();
    $store = ShoppingStore::factory()->create();

    $list = lists()->create($actor, '  Weekend   shop ', $store->id, null);

    expect($list->creator_id)->toBe($actor->id)
        ->and($list->responsible_id)->toBe($actor->id)
        ->and($list->name)->toBe('Weekend shop')
        ->and($list->status)->toBe(ListStatus::Open)
        ->and($list->store_id)->toBe($store->id);
});

it('creates a list for another eligible responsible person', function () {
    $actor = F::user();
    $other = F::user();

    expect(lists()->create($actor, 'L', null, $other->id)->responsible_id)->toBe($other->id);
});

it('refuses an ineligible responsible person on create', function (string $kind) {
    $actor = F::user();
    $target = match ($kind) {
        'view-only' => F::user(AccessLevel::View),
        'child' => F::user(AccessLevel::Edit, child: true),
        'unknown' => null,
    };

    expect(F::errors(fn () => lists()->create($actor, 'L', null, $target?->id ?? 999999)))->toHaveKey('responsible_id')
        ->and(ShoppingList::count())->toBe(0);
})->with(['view-only', 'child', 'unknown']);

it('refuses to create without Shopping edit', function (AccessLevel $level) {
    expect(F::status(fn () => lists()->create(F::user($level), 'L', null, null)))->toBe(403);
})->with([AccessLevel::View, AccessLevel::None]);

it('refuses an empty name and an unknown store', function () {
    $actor = F::user();

    expect(F::errors(fn () => lists()->create($actor, '   ', null, null)))->toHaveKey('name')
        ->and(F::errors(fn () => lists()->create($actor, 'L', 999999, null)))->toHaveKey('store_id');
});

it('lets the creator change name, store, people and reminder while open', function () {
    $creator = F::user();
    $resp = F::user();
    $contrib = F::user();
    $viewer = F::user(AccessLevel::View);
    $store = ShoppingStore::factory()->create();
    $list = F::list($creator);
    $at = CarbonImmutable::now()->addDay()->startOfSecond();

    $updated = lists()->updateSettings($creator, $list->id, [
        'name' => 'New', 'store_id' => $store->id, 'responsible_id' => $resp->id,
        'contributor_ids' => [$contrib->id], 'viewer_ids' => [$viewer->id], 'remind_at' => $at,
    ]);

    expect($updated->name)->toBe('New')
        ->and($updated->store_id)->toBe($store->id)
        ->and($updated->responsible_id)->toBe($resp->id)
        ->and($updated->remind_at->getTimestamp())->toBe($at->getTimestamp())
        ->and($updated->members()->count())->toBe(2);
});

it('drops the new responsible person from the members when only the responsible changes', function () {
    $creator = F::user();
    $contrib = F::user();
    $list = F::list($creator, contributors: [$contrib]);

    lists()->updateSettings($creator, $list->id, ['responsible_id' => $contrib->id]);

    expect($list->fresh()->responsible_id)->toBe($contrib->id)
        ->and($list->members()->count())->toBe(0);
});

it('refuses a forged responsible or member on update', function () {
    $creator = F::user();
    $viewOnly = F::user(AccessLevel::View);
    $list = F::list($creator);

    expect(F::errors(fn () => lists()->updateSettings($creator, $list->id, ['responsible_id' => $viewOnly->id])))->toHaveKey('responsible_id')
        ->and(F::errors(fn () => lists()->updateSettings($creator, $list->id, ['contributor_ids' => [$viewOnly->id]])))->toHaveKey('contributors')
        ->and($list->fresh()->responsible_id)->toBe($creator->id);
});

it('forbids a contributor and a viewer from changing settings and hides the list from an outsider', function () {
    $creator = F::user();
    $contrib = F::user();
    $viewer = F::user();
    $list = F::list($creator, contributors: [$contrib], viewers: [$viewer]);

    expect(F::status(fn () => lists()->updateSettings($contrib, $list->id, ['name' => 'x'])))->toBe(403)
        ->and(F::status(fn () => lists()->updateSettings($viewer, $list->id, ['name' => 'x'])))->toBe(403)
        ->and(F::status(fn () => lists()->updateSettings(F::user(), $list->id, ['name' => 'x'])))->toBe(404)
        ->and($list->fresh()->name)->toBe($list->name);
});

it('lets the responsible change responsible person and reminder while locked but not the name', function () {
    $creator = F::user();
    $resp = F::user();
    $next = F::user();
    $list = F::list($creator, $resp, ListStatus::Locked);
    $at = CarbonImmutable::now()->addDays(2)->startOfSecond();

    expect(F::status(fn () => lists()->updateSettings($resp, $list->id, ['name' => 'x'])))->toBe(409);

    lists()->updateSettings($resp, $list->id, ['responsible_id' => $next->id, 'remind_at' => $at]);
    expect($list->fresh()->responsible_id)->toBe($next->id)
        ->and($list->fresh()->remind_at->getTimestamp())->toBe($at->getTimestamp());
});

it('refuses a locked-list reminder change by a creator who is not responsible', function () {
    $creator = F::user();
    $resp = F::user();
    $list = F::list($creator, $resp, ListStatus::Locked);

    expect(F::status(fn () => lists()->updateSettings($creator, $list->id, ['remind_at' => CarbonImmutable::now()->addDay()])))->toBe(403);
});

it('clears reminder_enqueued_at only when the reminder time changes', function () {
    $creator = F::user();
    $at = CarbonImmutable::now()->addDay()->startOfSecond();
    $list = F::list($creator);
    $list->forceFill(['remind_at' => $at, 'reminder_enqueued_at' => now()])->save();

    lists()->updateSettings($creator, $list->id, ['name' => 'Renamed', 'remind_at' => $at]);
    expect($list->fresh()->reminder_enqueued_at)->not->toBeNull();

    lists()->updateSettings($creator, $list->id, ['remind_at' => $at->addHour()]);
    expect($list->fresh()->reminder_enqueued_at)->toBeNull();

    lists()->updateSettings($creator, $list->id, ['remind_at' => null]);
    expect($list->fresh()->remind_at)->toBeNull();
});

it('refuses any settings change on a done list', function () {
    $creator = F::user();
    $list = F::list($creator, status: ListStatus::Done);

    expect(F::status(fn () => lists()->updateSettings($creator, $list->id, ['name' => 'x'])))->toBe(409)
        ->and(F::status(fn () => lists()->updateSettings($creator, $list->id, ['remind_at' => null])))->toBe(409);
});

it('deletes an open list with its items and members for creator or responsible', function (string $who) {
    $creator = F::user();
    $resp = F::user();
    $contrib = F::user();
    $list = F::list($creator, $resp, contributors: [$contrib]);
    F::item($list);
    $actor = $who === 'creator' ? $creator : $resp;

    lists()->delete($actor, $list->id);

    expect(ShoppingList::count())->toBe(0)
        ->and(DB::table('shopping_items')->count())->toBe(0)
        ->and(DB::table('shopping_list_members')->count())->toBe(0);
})->with(['creator', 'responsible']);

it('refuses to delete a locked or done list', function (ListStatus $status) {
    $creator = F::user();
    $list = F::list($creator, status: $status);

    expect(F::status(fn () => lists()->delete($creator, $list->id)))->toBe(409)
        ->and(ShoppingList::count())->toBe(1);
})->with([ListStatus::Locked, ListStatus::Done]);

it('refuses delete by a contributor and hides it from an outsider', function () {
    $creator = F::user();
    $contrib = F::user();
    $list = F::list($creator, contributors: [$contrib]);

    expect(F::status(fn () => lists()->delete($contrib, $list->id)))->toBe(403)
        ->and(F::status(fn () => lists()->delete(F::user(), $list->id)))->toBe(404)
        ->and(F::status(fn () => lists()->delete(User::factory()->admin()->create(), $list->id)))->toBe(404)
        ->and(ShoppingList::count())->toBe(1);
});

it('keeps the previous responsible person on the list as a contributor after a hand-over', function () {
    $creator = F::user();
    $b = F::user();
    $c = F::user();
    $list = F::list($creator, $b);

    lists()->updateSettings($b, $list->id, ['responsible_id' => $c->id]);

    $member = $list->members()->where('user_id', $b->id)->first();
    expect($member?->role)->toBe(MemberRole::Contributor)
        ->and(app(ShoppingAccess::class)->canView($b, $list->fresh()))->toBeTrue()
        ->and(app(ShoppingAccess::class)->role($b, $list->fresh()))->toBe(ShoppingAccess::CONTRIBUTOR);
});

it('does not add a member row for the creator who hands the list over', function () {
    $creator = F::user();
    $other = F::user();
    $list = F::list($creator);

    lists()->updateSettings($creator, $list->id, ['responsible_id' => $other->id]);

    expect($list->members()->count())->toBe(0);
});

it('keeps a previous responsible person without Shopping edit as a viewer', function () {
    $creator = F::user();
    $b = F::user();
    $c = F::user();
    $list = F::list($creator, $b);
    F::setLevel($b, AccessLevel::View);

    lists()->updateSettings($creator, $list->id, ['responsible_id' => $c->id]);

    expect($list->members()->where('user_id', $b->id)->first()?->role)->toBe(MemberRole::Viewer);
});

it('answers the hand-over redirect for the previous responsible person with the list, not a 404', function () {
    $creator = F::user();
    $b = F::user();
    $c = F::user();
    $list = F::list($creator, $b);

    $this->actingAs($b)->put("/shopping/lists/{$list->id}", ['responsible_id' => $c->id])->assertRedirect();
    $this->actingAs($b)->get("/shopping/lists/{$list->id}")->assertOk();
});
