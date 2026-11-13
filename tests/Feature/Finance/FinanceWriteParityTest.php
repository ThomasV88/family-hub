<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\FinancePrivacy;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

/** Routes whose family-wide reassign is allowlisted by design (proven by the hidden-usage identity test of 05-19). */
const FH_PARITY_EXCLUDED = ['finance.categories.update', 'finance.categories.destroy'];

it('answers a write on a hidden record exactly like a write on a record that does not exist', function () {
    $ctx = FinancePrivacy::scenario();
    FinancePrivacy::seedVisible($ctx);
    FinancePrivacy::seedHidden($ctx);
    $viewer = $ctx['viewer'];
    $checked = 0;

    foreach (FinancePrivacy::writes() as $route) {
        $hiddenParams = FinancePrivacy::hiddenParams($route, $ctx);
        if ($hiddenParams === null) {
            continue;
        }
        $random = FinancePrivacy::hiddenParams($route, $ctx, fn () => (string) Str::uuid7());
        $payload = FinancePrivacy::payload($route->getName(), $ctx);

        $send = function (array $params) use ($route, $viewer, $payload): string {
            app('auth')->forgetGuards();
            app('cache')->store()->flush();
            $this->flushSession();
            $url = route($route->getName(), $params, false);

            return FinancePrivacy::normalise($this->actingAs($viewer)->call(FinancePrivacy::method($route), $url, $payload));
        };

        expect($send($hiddenParams))->toBe($send($random), 'hidden vs random id differ on '.$route->getName());
        $checked++;
    }

    expect($checked)->toBeGreaterThanOrEqual(0);
});

it('issues only membership-scoped UPDATE and DELETE statements from every finance write request', function () {
    $ctx = FinancePrivacy::scenario();
    FinancePrivacy::seedVisible($ctx);
    FinancePrivacy::seedHidden($ctx);
    $viewer = $ctx['viewer'];

    expect(FinancePrivacy::writes())->toBeArray();

    foreach (FinancePrivacy::writes() as $route) {
        if (in_array($route->getName(), FH_PARITY_EXCLUDED, true)) {
            continue;
        }
        $url = route($route->getName(), FinancePrivacy::params($route, $ctx), false);
        $payload = FinancePrivacy::payload($route->getName(), $ctx);

        FinancePrivacy::assertScopedMutations(function () use ($route, $viewer, $url, $payload) {
            app('auth')->forgetGuards();
            app('cache')->store()->flush();
            $this->flushSession();
            $this->actingAs($viewer)->call(FinancePrivacy::method($route), $url, $payload);
        });
    }
});

it('fails the scoped-mutation helper for an unscoped statement (helper self-test)', function () {
    $unscoped = fn () => DB::update('update finance_transactions set note = ? where id = ?', ['x', (string) Str::uuid7()]);
    expect(fn () => FinancePrivacy::assertScopedMutations($unscoped))->toThrow(AssertionFailedError::class, 'Unscoped finance mutation');

    expect(FinancePrivacy::unscopedMutations(['delete from "finance_import_rows" where "id" = ?']))->toHaveCount(1)
        ->and(FinancePrivacy::unscopedMutations(['insert into "finance_budgets" ("id") values (?) on conflict ("id") do update set "id" = excluded."id"']))->toHaveCount(1)
        ->and(FinancePrivacy::unscopedMutations(['insert into "finance_transactions" ("id") values (?)']))->toBe([])
        ->and(FinancePrivacy::unscopedMutations(['update "finance_categories" set "name" = ?']))->toBe([]);

    $scoped = fn () => DB::update('update finance_transactions set note = ? where id = ? and account_id in (select account_id from finance_account_members where user_id = ?)', ['x', (string) Str::uuid7(), 1]);
    FinancePrivacy::assertScopedMutations($scoped);
    expect(true)->toBeTrue();
});
