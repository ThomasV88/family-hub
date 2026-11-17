<?php

use App\Access\AccessLevel;
use App\Models\User;
use App\Shopping\ListStatus;
use App\Shopping\Queries\ListQuery;
use App\Shopping\ResponsiblePerson;
use App\Shopping\ShoppingAccess;
use Tests\Support\ShoppingFixtures as F;

function resolver(): ResponsiblePerson
{
    return app(ResponsiblePerson::class);
}

it('returns the responsible person while they can sign in and hold Shopping edit', function () {
    $creator = F::user();
    $resp = F::user();
    $list = F::list($creator, $resp);

    expect(resolver()->effective($list)->id)->toBe($resp->id)
        ->and(resolver()->isFallback($list))->toBeFalse()
        ->and(resolver()->previousName($list))->toBeNull();
});

it('falls back to the creator when the responsible row was deleted', function () {
    $creator = F::user();
    $resp = F::user();
    $list = F::list($creator, $resp);
    $resp->delete();

    $list = $list->fresh();
    expect($list->responsible_id)->toBeNull()
        ->and(resolver()->effective($list)->id)->toBe($creator->id)
        ->and(resolver()->isFallback($list))->toBeTrue()
        ->and(resolver()->previousName($list))->toBeNull();
});

it('falls back to the creator when the responsible cannot sign in (child)', function () {
    $creator = F::user();
    $child = F::user(AccessLevel::Edit, child: true);
    $list = F::list($creator, $child);

    expect(resolver()->effective($list)->id)->toBe($creator->id)
        ->and(resolver()->isFallback($list))->toBeTrue()
        ->and(resolver()->previousName($list))->toBe($child->name);
});

it('falls back to the creator when the responsible lost Shopping edit', function (AccessLevel $level) {
    $creator = F::user();
    $resp = F::user();
    $list = F::list($creator, $resp);
    F::setLevel($resp, $level);

    expect(resolver()->effective($list)->id)->toBe($creator->id)
        ->and(resolver()->isFallback($list))->toBeTrue()
        ->and(resolver()->previousName($list))->toBe($resp->name);
})->with([AccessLevel::View, AccessLevel::None]);

it('does not call a creator who is also responsible a fallback', function () {
    $creator = F::user();
    $list = F::list($creator);

    expect(resolver()->effective($list)->id)->toBe($creator->id)
        ->and(resolver()->isFallback($list))->toBeFalse();
});

it('judges eligibility by sign-in and Shopping edit', function () {
    expect(resolver()->eligible(F::user()))->toBeTrue()
        ->and(resolver()->eligible(F::user(AccessLevel::View)))->toBeFalse()
        ->and(resolver()->eligible(F::user(AccessLevel::Edit, child: true)))->toBeFalse();
});

it('falls back to the first eligible contributor by id when the creator is not eligible', function () {
    $creator = F::user();
    $resp = F::user();
    $c1 = F::user();
    $c2 = F::user();
    $list = F::list($creator, $resp, contributors: [$c2, $c1]);
    F::setLevel($resp, AccessLevel::View);
    F::setLevel($creator, AccessLevel::View);

    expect(resolver()->effective($list)->id)->toBe(min($c1->id, $c2->id))
        ->and(resolver()->hasActive($list))->toBeTrue();
});

it('skips an ineligible contributor and keeps the creator as the readable last resort', function () {
    $creator = F::user();
    $c1 = F::user();
    $list = F::list($creator, contributors: [$c1]);
    F::setLevel($creator, AccessLevel::View);
    F::setLevel($c1, AccessLevel::View);

    expect(resolver()->effective($list)->id)->toBe($creator->id)
        ->and(resolver()->hasActive($list))->toBeFalse();
});

it('lets an administrator with Shopping edit act on a list nobody is eligible for, and only then', function () {
    $creator = F::user();
    $admin = User::factory()->admin()->create();
    F::setLevel($admin, AccessLevel::Edit);
    $list = F::list($creator, status: ListStatus::Locked);
    $access = app(ShoppingAccess::class);

    expect(ListQuery::visibleTo($admin)->count())->toBe(0)
        ->and($access->canUnlock($admin, $list))->toBeFalse();

    F::setLevel($creator, AccessLevel::View);

    expect(ListQuery::visibleTo($admin)->whereKey($list->id)->exists())->toBeTrue()
        ->and($access->canView($admin, $list))->toBeTrue()
        ->and($access->canUnlock($admin, $list))->toBeTrue()
        ->and($access->canChangeResponsible($admin, $list))->toBeTrue();
});

it('gives an administrator without Shopping edit nothing on a stuck list', function () {
    $creator = F::user();
    $admin = User::factory()->admin()->create();
    F::setLevel($admin, AccessLevel::View);
    $list = F::list($creator, status: ListStatus::Locked);
    F::setLevel($creator, AccessLevel::View);

    expect(app(ShoppingAccess::class)->canUnlock($admin, $list))->toBeFalse();
});
