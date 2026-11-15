<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Access\Preset;
use App\Finance\Queries\AccountQuery;
use App\Finance\Viewer;
use App\Models\FinanceAccount;
use App\Models\FinanceTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('builds a viewer only for users with finance access', function () {
    $none = User::factory()->withPreset(Preset::Child)->create();
    try {
        Viewer::for($none);
        $this->fail('expected 403');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    }

    $parent = Viewer::for(User::factory()->withPreset(Preset::Parent)->create());
    expect($parent->level)->toBe(AccessLevel::Edit)->and($parent->canEdit())->toBeTrue();

    $view = Viewer::for(User::factory()->withGrant(Module::Finance, AccessLevel::View)->create());
    expect($view->canEdit())->toBeFalse()->and($view->userId())->toBeInt();
});

it('shows only accounts the viewer was added to and gives an admin no bypass', function () {
    $member = User::factory()->withPreset(Preset::Parent)->create();
    $other = User::factory()->withPreset(Preset::Parent)->create();
    $admin = User::factory()->admin()->withGrant(Module::Finance, AccessLevel::Edit)->create();

    $mine = FinanceAccount::factory()->withMembers($member, $other)->create(['name' => 'Mine']);
    FinanceAccount::factory()->withMembers($other)->create(['name' => 'Hidden']);

    $q = new AccountQuery;
    expect($q->visible(Viewer::for($member))->pluck('id')->all())->toBe([$mine->id])
        ->and($q->visibleIds(Viewer::for($member)))->toBe([$mine->id])
        ->and($q->visible(Viewer::for($admin))->all())->toBe([])
        ->and($q->visible(Viewer::for($other)))->toHaveCount(2);
});

it('finds only visible accounts and skips SQL for malformed ids', function () {
    $member = User::factory()->withPreset(Preset::Parent)->create();
    $other = User::factory()->withPreset(Preset::Parent)->create();
    $mine = FinanceAccount::factory()->withMembers($member)->create();
    $hidden = FinanceAccount::factory()->withMembers($other)->create();
    $viewer = Viewer::for($member);
    $q = new AccountQuery;

    expect($q->findVisible($viewer, $mine->id)?->id)->toBe($mine->id)
        ->and($q->findVisible($viewer, $hidden->id))->toBeNull()
        ->and($q->findVisible($viewer, (string) Str::uuid7()))->toBeNull();

    $statements = [];
    DB::listen(function ($query) use (&$statements) {
        $statements[] = $query->sql;
    });
    expect($q->findVisible($viewer, 'not-a-uuid'))->toBeNull()
        ->and($statements)->toBe([]);
});

it('computes balances as of a date for visible accounts only', function () {
    $member = User::factory()->withPreset(Preset::Parent)->create();
    $other = User::factory()->withPreset(Preset::Parent)->create();
    $a = FinanceAccount::factory()->withMembers($member)->create(['opening_balance_minor' => 10000]);
    $empty = FinanceAccount::factory()->withMembers($member)->create(['opening_balance_minor' => 50]);
    $hidden = FinanceAccount::factory()->withMembers($other)->create(['opening_balance_minor' => 999]);

    FinanceTransaction::factory()->onAccount($a)->income(5000)->create(['booked_on' => '2026-03-10']);
    FinanceTransaction::factory()->onAccount($a)->expense(1200)->create(['booked_on' => '2026-03-31']);
    FinanceTransaction::factory()->onAccount($a)->expense(700)->create(['booked_on' => '2026-04-01']);
    FinanceTransaction::factory()->onAccount($hidden)->income(1)->create(['booked_on' => '2026-03-10']);

    $balances = (new AccountQuery)->balances(Viewer::for($member), '2026-03-31');
    expect($balances)->toBe([$a->id => 13800, $empty->id => 50])
        ->and($balances[$a->id])->toBeInt();
});

it('lists visible currencies by frequency then code', function () {
    $member = User::factory()->withPreset(Preset::Parent)->create();
    $other = User::factory()->withPreset(Preset::Parent)->create();
    FinanceAccount::factory()->withMembers($member)->count(2)->create(['currency' => 'EUR']);
    FinanceAccount::factory()->withMembers($member)->create(['currency' => 'CZK']);
    FinanceAccount::factory()->withMembers($other)->create(['currency' => 'PLN']);

    expect((new AccountQuery)->currencies(Viewer::for($member)))->toBe(['EUR', 'CZK']);
});

it('carries the membership subquery in every statement', function () {
    $member = User::factory()->withPreset(Preset::Parent)->create();
    $viewer = Viewer::for($member);
    $statements = [];
    DB::listen(function ($query) use (&$statements) {
        $statements[] = $query->sql;
    });
    $q = new AccountQuery;
    $q->visible($viewer);
    $q->findVisible($viewer, (string) Str::uuid7());
    $q->visibleIds($viewer);
    $q->balances($viewer, '2026-01-01');
    $q->currencies($viewer);

    expect($statements)->toHaveCount(5);
    foreach ($statements as $sql) {
        expect($sql)->toContain('finance_account_members');
    }
});
