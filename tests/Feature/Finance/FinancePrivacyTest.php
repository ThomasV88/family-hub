<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinancePrivacy;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

it('serves the viewer byte-identical surfaces with and without hidden-account data, free of canaries', function () {
    $ctx = FinancePrivacy::scenario();
    FinancePrivacy::seedVisible($ctx);
    $viewer = $ctx['viewer'];

    expect(FinancePrivacy::surfaces())->not->toBeEmpty();

    $before = FinancePrivacy::capture($this, $viewer, $ctx);
    FinancePrivacy::seedHidden($ctx);
    expect($ctx['hidden'])->not->toBeEmpty();
    $after = FinancePrivacy::capture($this, $viewer, $ctx);

    expect($after)->toEqual($before);
    // Guard against a vacuous pass: the Inertia captures really are rendered pages.
    expect(collect($after)->filter(fn ($b, $k) => str_starts_with($k, 'inertia ') && str_starts_with($b, "200\n\n{")))->not->toBeEmpty();

    foreach ($after as $key => $body) {
        $scan = FinancePrivacy::scannable($body);
        foreach (FinancePrivacy::CANARY_NEEDLES as $needle) {
            expect($scan)->not->toContain($needle, "canary '$needle' in $key");
        }
    }
});

it('puts the membership subquery in every finance statement a viewer request issues', function () {
    $ctx = FinancePrivacy::scenario();
    FinancePrivacy::seedVisible($ctx);
    FinancePrivacy::seedHidden($ctx);

    DB::flushQueryLog();
    DB::enableQueryLog();
    FinancePrivacy::capture($this, $ctx['viewer'], $ctx);
    $statements = collect(DB::getQueryLog())->pluck('query')->all();
    DB::disableQueryLog();

    expect($statements)->not->toBeEmpty()
        ->and(FinancePrivacy::unscopedReads($statements))->toBe([]);
});

it('detects an unscoped read statement (helper self-test)', function () {
    expect(FinancePrivacy::unscopedReads(['select * from "finance_transactions" where "id" = ?']))->toHaveCount(1)
        ->and(FinancePrivacy::unscopedReads(['select * from "finance_transactions" where "account_id" in (select "account_id" from "finance_account_members" where "user_id" = ?)']))->toBe([])
        ->and(FinancePrivacy::unscopedReads(['select 1 from finance_transactions t where exists (select 1 from finance_account_members m where m.user_id = ? and m.account_id = t.account_id)']))->toBe([])
        // The members table merely mentioned, or constraining someone else or another column, is not a scope.
        ->and(FinancePrivacy::unscopedReads(['select * from "finance_transactions" where "account_id" in (select "account_id" from "finance_account_members")']))->toHaveCount(1)
        ->and(FinancePrivacy::unscopedReads(['select * from "finance_transactions" t join "finance_account_members" m on m.account_id = t.account_id']))->toHaveCount(1)
        ->and(FinancePrivacy::unscopedReads(['select count(*) from "finance_transactions" where exists (select 1 from "finance_account_members" where "role" = ?)']))->toHaveCount(1);
});

it('classifies every finance table as account-owned or family-wide', function () {
    $tables = collect(DB::select("select table_name from information_schema.tables where table_schema = current_schema() and table_name like 'finance\_%'"))
        ->pluck('table_name')->all();
    $owned = (new ReflectionClass(FinancePrivacy::class))->getReflectionConstant('READ_TABLES')->getValue();

    expect($tables)->not->toBeEmpty();
    foreach ($tables as $table) {
        expect(in_array($table, $owned, true) || array_key_exists($table, FinancePrivacy::FAMILY_WIDE_TABLES))
            ->toBeTrue("finance table $table is neither scope-checked nor declared family-wide");
    }
    expect(array_intersect($owned, array_keys(FinancePrivacy::FAMILY_WIDE_TABLES)))->toBe([]);
});

it('reports a missing provider with route and parameter name', function () {
    $route = new Route('GET', '/finance/probe/{nosuchparam}', []);
    $route->name('finance.probe');

    expect(fn () => FinancePrivacy::params($route, []))
        ->toThrow(RuntimeException::class, 'finance.probe');
});
