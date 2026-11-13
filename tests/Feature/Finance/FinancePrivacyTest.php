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
        ->and(FinancePrivacy::unscopedReads(['select * from "finance_transactions" where "account_id" in (select "account_id" from "finance_account_members")']))->toBe([]);
});

it('reports a missing provider with route and parameter name', function () {
    $route = new Route('GET', '/finance/probe/{nosuchparam}', []);
    $route->name('finance.probe');

    expect(fn () => FinancePrivacy::params($route, []))
        ->toThrow(RuntimeException::class, 'finance.probe');
});
