<?php

use App\Access\AccessLevel;
use App\Shopping\ResponsiblePerson;
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
