<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\FinancePrivacy;

/*
 * Proof that the finance leak net (FinancePrivacy, FinancePrivacyTest, FinanceWriteParityTest) is complete and sensitive.
 * Completeness: the enumerated surfaces and write routes contain the whole route inventory of the phase.
 * Sensitivity (mutation sanity): throwaway routes named finance.zz_mutant_* exist only inside these tests, run unscoped
 * queries on purpose, and each check of the net must catch them while the real surfaces stay green.
 */
beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

const FH_LS_GET_INVENTORY = [
    'finance.index', 'finance.transactions.index', 'finance.recurring.index', 'finance.accounts.index',
    'finance.categories.index', 'finance.import.index', 'finance.import.show',
    'settings.finance_accounts.index', 'settings.index', 'home',
];

/** Write routes with a record id parameter: each answers a hidden id exactly like a random one. */
const FH_LS_PARITY_INVENTORY = [
    'finance.transactions.update', 'finance.transactions.destroy', 'finance.recurring.update', 'finance.recurring.destroy',
    'finance.bills.record', 'finance.bills.match', 'finance.bills.unmatch', 'finance.budgets.update', 'finance.budgets.destroy',
    'finance.import.commit', 'finance.import.undo', 'finance.import.discard', 'settings.finance_accounts.update',
];

const FH_LS_ALLOWLISTED_WRITES = ['finance.categories.update', 'finance.categories.destroy'];

/** @return array<string, mixed> */
function fhLsScenario(): array
{
    $ctx = FinancePrivacy::scenario();
    FinancePrivacy::seedVisible($ctx);
    FinancePrivacy::seedHidden($ctx);

    return $ctx;
}

function fhLsRefreshRoutes(): void
{
    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();
}

/** Registers the two unscoped GET mutants a missing scope would look like. */
function fhLsRegisterReadMutants(): void
{
    Route::middleware(['web', 'access:module,finance,view'])->group(function () {
        Route::get('/finance/zz-mutant-count', fn () => response((string) DB::table('finance_transactions')->count()))
            ->name('finance.zz_mutant_count');
        Route::get('/finance/zz-mutant-sum', fn () => response()->json(
            DB::table('finance_transactions')->selectRaw('account_id, sum(amount_minor) as total')->groupBy('account_id')->get(),
        ))->name('finance.zz_mutant_sum');
    });
    fhLsRefreshRoutes();
}

it('enumerates every GET route of the phase inventory as a leak surface', function () {
    $names = array_map(fn ($r) => $r->getName(), FinancePrivacy::surfaces());

    expect(array_diff(FH_LS_GET_INVENTORY, $names))->toBe([]);
    foreach ($names as $name) {
        expect(str_starts_with($name, 'settings.') ? in_array($name, ['settings.index'], true) || str_starts_with($name, 'settings.finance_accounts.') : true)
            ->toBeTrue("unexpected settings surface $name");
    }
});

it('covers every record-id write route with hidden-vs-random parity and every other write with the scoped-mutation check', function () {
    $ctx = fhLsScenario();

    $parity = [];
    $mutation = [];
    foreach (FinancePrivacy::writes() as $route) {
        if (FinancePrivacy::hiddenParams($route, $ctx) !== null) {
            $parity[] = $route->getName();
        }
        if (! in_array($route->getName(), FH_LS_ALLOWLISTED_WRITES, true)) {
            FinancePrivacy::params($route, $ctx); // throws when a parameter has no provider
            $mutation[] = $route->getName();
        }
    }

    expect($parity)->toContain(...FH_LS_PARITY_INVENTORY)
        ->and(count($parity))->toBeGreaterThanOrEqual(13)
        ->and(array_diff($mutation, $parity))->not->toBeEmpty() // store/preview routes are scoped-mutation only
        ->and(count($mutation))->toBeGreaterThanOrEqual(19)
        ->and($mutation)->not->toContain(...FH_LS_ALLOWLISTED_WRITES);
});

it('throws for a new finance GET route with a parameter nobody provided', function () {
    $ctx = fhLsScenario();
    Route::middleware(['web', 'access:module,finance,view'])->get('/finance/zz-unknown/{zzparam}', fn () => 'x')->name('finance.zz_unknown');
    fhLsRefreshRoutes();

    $route = collect(FinancePrivacy::surfaces())->first(fn ($r) => $r->getName() === 'finance.zz_unknown');
    expect($route)->not->toBeNull()
        ->and(fn () => FinancePrivacy::urls($route, $ctx))->toThrow(RuntimeException::class, 'zzparam');
});

it('fails the scoped-mutation check for an unscoped write route', function () {
    $ctx = fhLsScenario();
    $hiddenTx = $ctx['hidden']['expense'];
    Route::middleware(['web', 'access:module,finance,edit'])->post('/finance/zz-mutant-write', function () use ($hiddenTx) {
        DB::update('update finance_transactions set note = ? where id = ?', ['tampered', $hiddenTx]);

        return response('ok');
    })->name('finance.zz_mutant_write');
    fhLsRefreshRoutes();

    $mutant = collect(FinancePrivacy::writes())->first(fn ($r) => $r->getName() === 'finance.zz_mutant_write');
    expect($mutant)->not->toBeNull();

    $run = function () use ($mutant, $ctx) {
        $url = route($mutant->getName(), [], false);
        FinancePrivacy::assertScopedMutations(function () use ($ctx, $url) {
            app('auth')->forgetGuards();
            $this->flushSession();
            $this->actingAs($ctx['viewer'])->post($url);
        });
    };
    expect($run)->toThrow(AssertionFailedError::class, 'Unscoped finance mutation');
});

it('catches unscoped read surfaces with the differential, canary and query-log checks while the real surfaces stay clean', function () {
    $ctx = FinancePrivacy::scenario();
    FinancePrivacy::seedVisible($ctx);
    fhLsRegisterReadMutants();

    $before = FinancePrivacy::capture($this, $ctx['viewer'], $ctx);
    FinancePrivacy::seedHidden($ctx);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $after = FinancePrivacy::capture($this, $ctx['viewer'], $ctx);
    $statements = collect(DB::getQueryLog())->pluck('query')->all();
    DB::disableQueryLog();

    $isMutant = fn ($body, string $key) => str_contains($key, 'zz-mutant');
    $realBefore = array_filter($before, fn ($b, $k) => ! $isMutant($b, $k), ARRAY_FILTER_USE_BOTH);
    $realAfter = array_filter($after, fn ($b, $k) => ! $isMutant($b, $k), ARRAY_FILTER_USE_BOTH);
    $mutantKeys = array_keys(array_filter($after, $isMutant, ARRAY_FILTER_USE_BOTH));

    expect($mutantKeys)->not->toBeEmpty()->and($realAfter)->not->toBeEmpty();

    // Real surfaces stay green: identical before and after, no canary.
    expect($realAfter)->toEqual($realBefore);
    foreach ($realAfter as $key => $body) {
        foreach (FinancePrivacy::CANARY_NEEDLES as $needle) {
            expect(str_contains(FinancePrivacy::scannable($body), $needle))->toBeFalse("canary '$needle' in real surface $key");
        }
    }

    // (a) differential: the unscoped count changes when hidden data appears.
    foreach (['inertia', 'html'] as $kind) {
        expect($after["$kind /finance/zz-mutant-count"])->not->toBe($before["$kind /finance/zz-mutant-count"], "differential blind to $kind count mutant");
    }

    // (b) canary: the unscoped grouped SUM prints the hidden amount.
    expect(FinancePrivacy::scannable($after['html /finance/zz-mutant-sum']))->toContain('777777');

    // query log: the membership predicate is missing from exactly the mutants' statements.
    $unscoped = FinancePrivacy::unscopedReads($statements);
    expect($unscoped)->not->toBeEmpty();
    foreach ($unscoped as $sql) {
        expect($sql)->toMatch('/count\(\*\)|sum\(amount_minor\)/i', 'a real statement is unscoped: '.$sql);
    }
    expect(collect($unscoped)->contains(fn ($s) => str_contains(strtolower($s), 'count(*)')))->toBeTrue()
        ->and(collect($unscoped)->contains(fn ($s) => str_contains(strtolower($s), 'sum(amount_minor)')))->toBeTrue();
});

it('keeps mutant routes out of the application', function () {
    $names = collect(Route::getRoutes())->map(fn ($r) => (string) $r->getName())->filter(fn ($n) => str_contains($n, 'zz_'));
    expect($names->all())->toBe([]);
    expect(Str::contains(file_get_contents(base_path('routes/web.php')), 'zz_'))->toBeFalse();
});
