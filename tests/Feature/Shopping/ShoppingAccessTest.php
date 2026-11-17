<?php

use App\Access\AccessLevel;
use App\Models\ShoppingList;
use App\Models\User;
use App\Shopping\ListMembers;
use App\Shopping\ListStatus;
use App\Shopping\Queries\ListQuery;
use App\Shopping\ShoppingAccess;
use Tests\Support\ShoppingFixtures as F;

/** @return array{list: ShoppingList, who: array<string, User>} */
function accessScene(ListStatus $status): array
{
    $creator = F::user();
    $resp = F::user();
    $contrib = F::user();
    $viewer = F::user(AccessLevel::View);
    $viewOnlyContrib = F::user(AccessLevel::View);
    $outsider = F::user();
    $admin = User::factory()->admin()->create();

    $list = F::list($creator, $resp, $status, [$contrib, $viewOnlyContrib], [$viewer]);

    return ['list' => $list, 'who' => [
        'creator' => $creator, 'responsible' => $resp, 'contributor' => $contrib, 'viewer' => $viewer,
        'viewOnlyContributor' => $viewOnlyContrib, 'outsider' => $outsider, 'admin' => $admin,
    ]];
}

/** Expected abilities per role and status: open / locked / done. */
function expectedAbilities(): array
{
    $keys = ['editSettings', 'changeResponsible', 'setReminder', 'manageMembers', 'editItems', 'lock', 'unlock', 'mark', 'finish', 'copy', 'mail', 'delete'];
    $t = fn (string ...$on) => array_merge(array_fill_keys($keys, false), array_fill_keys($on, true));

    return [
        'creator' => [
            'open' => $t('editSettings', 'changeResponsible', 'setReminder', 'manageMembers', 'editItems', 'copy', 'mail', 'delete'),
            'locked' => $t('copy', 'mail'),
            'done' => $t('copy', 'mail'),
        ],
        'responsible' => [
            'open' => $t('editSettings', 'changeResponsible', 'setReminder', 'manageMembers', 'editItems', 'lock', 'copy', 'mail', 'delete'),
            'locked' => $t('changeResponsible', 'setReminder', 'unlock', 'mark', 'finish', 'copy', 'mail'),
            'done' => $t('copy', 'mail'),
        ],
        'contributor' => [
            'open' => $t('editItems', 'copy', 'mail'),
            'locked' => $t('copy', 'mail'),
            'done' => $t('copy', 'mail'),
        ],
        'viewer' => ['open' => $t(), 'locked' => $t(), 'done' => $t()],
        'viewOnlyContributor' => ['open' => $t(), 'locked' => $t(), 'done' => $t()],
    ];
}

it('encodes every ability per role and status', function (string $role, ListStatus $status) {
    $scene = accessScene($status);
    $access = app(ShoppingAccess::class);
    $abilities = $access->abilities($scene['who'][$role], $scene['list']);

    unset($abilities['manageCatalog']);
    expect($abilities)->toBe(expectedAbilities()[$role][$status->value]);
})->with(function () {
    foreach (['creator', 'responsible', 'contributor', 'viewer', 'viewOnlyContributor'] as $role) {
        foreach (ListStatus::cases() as $status) {
            yield "$role $status->value" => [$role, $status];
        }
    }
});

it('gives a creator who is not responsible no lock, mark or finish power', function () {
    $scene = accessScene(ListStatus::Locked);
    $access = app(ShoppingAccess::class);
    $c = $scene['who']['creator'];

    expect($access->canUnlock($c, $scene['list']))->toBeFalse()
        ->and($access->canMark($c, $scene['list']))->toBeFalse()
        ->and($access->canFinish($c, $scene['list']))->toBeFalse()
        ->and($access->role($c, $scene['list']))->toBe('creator')
        ->and($access->role($scene['who']['responsible'], $scene['list']))->toBe('responsible')
        ->and($access->role($scene['who']['contributor'], $scene['list']))->toBe('contributor')
        ->and($access->role($scene['who']['viewOnlyContributor'], $scene['list']))->toBe('viewer');
});

it('shows nothing to an outsider or an administrator without a role', function (string $who) {
    $scene = accessScene(ListStatus::Open);
    $access = app(ShoppingAccess::class);
    $user = $scene['who'][$who];

    expect($access->canView($user, $scene['list']))->toBeFalse()
        ->and($access->role($user, $scene['list']))->toBeNull()
        ->and(array_filter($access->abilities($user, $scene['list']), fn ($v, $k) => $k !== 'manageCatalog' && $v, ARRAY_FILTER_USE_BOTH))->toBe([])
        ->and(ListQuery::visibleTo($user)->whereKey($scene['list']->id)->exists())->toBeFalse();
})->with(['outsider', 'admin']);

it('lets everyone with a place on the list view it', function (string $who) {
    $scene = accessScene(ListStatus::Done);

    expect(app(ShoppingAccess::class)->canView($scene['who'][$who], $scene['list']))->toBeTrue();
})->with(['creator', 'responsible', 'contributor', 'viewer']);

it('hides the list from a member whose Shopping module level dropped to none', function () {
    $scene = accessScene(ListStatus::Open);
    F::setLevel($scene['who']['contributor'], AccessLevel::None);
    $access = app(ShoppingAccess::class);

    expect($access->canView($scene['who']['contributor'], $scene['list']))->toBeFalse()
        ->and($access->canCopy($scene['who']['contributor'], $scene['list']))->toBeFalse();
});

it('lets a viewer with Shopping edit copy but never mail', function () {
    $creator = F::user();
    $viewer = F::user();
    $list = F::list($creator, viewers: [$viewer]);
    $access = app(ShoppingAccess::class);

    expect($access->canCopy($viewer, $list))->toBeTrue()
        ->and($access->canMail($viewer, $list))->toBeFalse()
        ->and($access->canEditItems($viewer, $list))->toBeFalse();
});

it('makes the creator act as responsible when the stored one cannot', function () {
    $creator = F::user();
    $child = F::user(AccessLevel::Edit, child: true);
    $list = F::list($creator, $child, ListStatus::Locked);
    $access = app(ShoppingAccess::class);

    expect($access->canMark($creator, $list))->toBeTrue()
        ->and($access->canFinish($creator, $list))->toBeTrue()
        ->and($access->role($creator, $list))->toBe('creator');
});

it('keeps a demoted responsible person able to see the list but not to act', function () {
    $creator = F::user();
    $resp = F::user();
    $list = F::list($creator, $resp, ListStatus::Locked);
    F::setLevel($resp, AccessLevel::View);
    $access = app(ShoppingAccess::class);

    expect($access->canView($resp, $list))->toBeTrue()
        ->and($access->canMark($resp, $list))->toBeFalse()
        ->and($access->canMark($creator, $list))->toBeTrue()
        ->and(ListQuery::visibleTo($resp)->whereKey($list->id)->exists())->toBeTrue();
});

it('exposes manageCatalog as Shopping edit', function () {
    $list = F::list(F::user());
    $access = app(ShoppingAccess::class);

    expect($access->abilities(F::user(), $list)['manageCatalog'])->toBeTrue()
        ->and($access->abilities(F::user(AccessLevel::View), $list)['manageCatalog'])->toBeFalse();
});

it('scopes visibleTo to creator, responsible and members', function () {
    $scene = accessScene(ListStatus::Open);
    $other = F::list(F::user());

    foreach (['creator', 'responsible', 'contributor', 'viewer'] as $who) {
        $ids = ListQuery::visibleTo($scene['who'][$who])->pluck('id')->all();
        expect($ids)->toBe([$scene['list']->id]);
    }
    expect(ListQuery::visibleTo($scene['who']['outsider'])->count())->toBe(0)
        ->and(ListQuery::visibleTo($scene['who']['admin'])->count())->toBe(0)
        ->and(ListQuery::visibleTo($other->creator)->pluck('id')->all())->toBe([$other->id]);
});

it('syncs members, converts roles and removes the others', function () {
    $creator = F::user();
    $a = F::user();
    $b = F::user();
    $list = F::list($creator, viewers: [$a]);
    $members = app(ListMembers::class);

    $members->sync($list, [$a->id], [$b->id]);

    expect($list->members()->pluck('role', 'user_id')->map(fn ($r) => $r->value)->all())
        ->toBe([$a->id => 'contributor', $b->id => 'viewer']);

    $members->sync($list, [], []);
    expect($list->members()->count())->toBe(0);
});

it('refuses ineligible, overlapping and reserved members', function (string $case) {
    $creator = F::user();
    $resp = F::user();
    $list = F::list($creator, $resp);
    $members = app(ListMembers::class);
    $good = F::user();
    $viewOnly = F::user(AccessLevel::View);
    $none = F::user(AccessLevel::None);

    $run = match ($case) {
        'contributor view-only' => fn () => $members->sync($list, [$viewOnly->id], []),
        'viewer without Shopping' => fn () => $members->sync($list, [], [$none->id]),
        'overlap' => fn () => $members->sync($list, [$good->id], [$good->id]),
        'creator as member' => fn () => $members->sync($list, [$creator->id], []),
        'responsible as viewer' => fn () => $members->sync($list, [], [$resp->id]),
        'forged id' => fn () => $members->sync($list, [999999], []),
    };

    expect(F::errors($run))->not->toBe([])
        ->and($list->members()->count())->toBe(0);
})->with(['contributor view-only', 'viewer without Shopping', 'overlap', 'creator as member', 'responsible as viewer', 'forged id']);

it('accepts a child as contributor and as viewer', function () {
    $list = F::list(F::user());
    $contributorChild = F::user(AccessLevel::Edit, child: true);
    $viewerChild = F::user(AccessLevel::View, child: true);

    app(ListMembers::class)->sync($list, [$contributorChild->id], [$viewerChild->id]);

    expect($list->members()->count())->toBe(2);
});
