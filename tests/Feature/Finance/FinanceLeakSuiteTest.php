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
        Route::get('/finance/zz-mutant-recurring-sum', fn () => response()->json(
            DB::table('finance_recurring')->selectRaw('account_id, sum(expected_amount_minor) as total')->groupBy('account_id')->get(),
        ))->name('finance.zz_mutant_recurring_sum');
        // Mentions the members table but does not constrain it to the viewer: the weak "text appears" check let this pass.
        Route::get('/finance/zz-mutant-weak-scope', fn () => response()->json(
            DB::table('finance_transactions')->selectRaw('account_id, sum(amount_minor) as total')
                ->whereIn('account_id', DB::table('finance_account_members')->select('account_id'))->groupBy('account_id')->get(),
        ))->name('finance.zz_mutant_weak_scope');
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
        expect($sql)->toMatch('/count\(\*\)|sum\(amount_minor\)|sum\(expected_amount_minor\)/i', 'a real statement is unscoped: '.$sql);
    }
    // (c) the grouped aggregate over a second table prints the hidden recurring amount, and the net sees its statement.
    expect(FinancePrivacy::scannable($after['html /finance/zz-mutant-recurring-sum']))->toContain($ctx['hiddenAccount']->id)
        ->and(collect($unscoped)->contains(fn ($s) => str_contains(strtolower($s), 'sum(expected_amount_minor)')))->toBeTrue()
        // (d) the members table without the viewer predicate is still unscoped, and leaks.
        ->and(FinancePrivacy::scannable($after['html /finance/zz-mutant-weak-scope']))->toContain($ctx['hiddenAccount']->id)
        ->and(collect($unscoped)->contains(fn ($s) => str_contains(strtolower($s), 'finance_account_members') && str_contains(strtolower($s), 'sum(amount_minor)')))->toBeTrue();
    expect(collect($unscoped)->contains(fn ($s) => str_contains(strtolower($s), 'count(*)')))->toBeTrue()
        ->and(collect($unscoped)->contains(fn ($s) => str_contains(strtolower($s), 'sum(amount_minor)')))->toBeTrue();
});

it('keeps mutant routes out of the application', function () {
    $names = collect(Route::getRoutes())->map(fn ($r) => (string) $r->getName())->filter(fn ($n) => str_contains($n, 'zz_'));
    expect($names->all())->toBe([]);
    expect(Str::contains(file_get_contents(base_path('routes/web.php')), 'zz_'))->toBeFalse();
});


it('catches an unscoped INSERT and an unscoped DELETE write route (mutation sanity)', function () {
    $ctx = fhLsScenario();
    $hiddenAccount = $ctx['hiddenAccount']->id;
    $hiddenTx = $ctx['hidden']['expense'];
    Route::middleware(['web', 'access:module,finance,edit'])->group(function () use ($hiddenAccount, $hiddenTx) {
        // Body-supplied account id written without any visible-account lookup.
        Route::post('/finance/zz-mutant-insert', function () use ($hiddenAccount) {
            App\Models\FinanceTransaction::factory()->onAccount(App\Models\FinanceAccount::query()->findOrFail($hiddenAccount))->expense(1)->create();

            return response('ok');
        })->name('finance.zz_mutant_insert');
        Route::post('/finance/zz-mutant-insert-select', function () use ($hiddenAccount) {
            DB::insert('insert into finance_transactions (id, account_id, currency, kind, amount_minor, booked_on, source, created_by, created_at, updated_at) '
                .'select gen_random_uuid(), account_id, currency, kind, amount_minor, booked_on, source, created_by, created_at, updated_at from finance_transactions where account_id = ? and kind = ? limit 1', [$hiddenAccount, 'expense']);

            return response('ok');
        })->name('finance.zz_mutant_insert_select');
        Route::post('/finance/zz-mutant-delete', function () use ($hiddenTx) {
            DB::table('finance_transactions')->where('id', $hiddenTx)->delete();

            return response('ok');
        })->name('finance.zz_mutant_delete');
    });
    fhLsRefreshRoutes();

    foreach (['zz-mutant-insert', 'zz-mutant-insert-select', 'zz-mutant-delete'] as $path) {
        $run = function () use ($ctx, $path) {
            FinancePrivacy::assertScopedMutations(function () use ($ctx, $path) {
                app('auth')->forgetGuards();
                $this->flushSession();
                $this->actingAs($ctx['viewer'])->post('/finance/'.$path);
            });
        };
        expect($run)->toThrow(AssertionFailedError::class, 'Unscoped finance mutation');
    }
});

/** The only statements of the category routes that may lack the membership predicate: they set category_id and nothing else. */
const FH_LS_CATEGORY_EXEMPT = [
    '/^delete from finance_budgets as f where f\.category_id = \? and exists \(select 1 from finance_budgets as t where t\.category_id = \? and t\.currency = f\.currency and t\.effective_from = f\.effective_from\)$/',
    '/^update finance_(?:budgets|transactions|recurring|import_rows) set category_id = \? where category_id = \?$/',
];

it('runs the category routes through the mutation check with a documented exemption that only rewrites category_id', function () {
    $ctx = fhLsScenario();
    $pets = $ctx['category']['pets'];
    $target = App\Models\FinanceCategory::query()->where('default_key', 'other')->value('id');
    $snapshot = fn () => [
        'tx' => DB::table('finance_transactions')->orderBy('id')->get()->map(fn ($r) => collect((array) $r)->except('category_id')->all())->all(),
        'rec' => DB::table('finance_recurring')->orderBy('id')->get()->map(fn ($r) => collect((array) $r)->except('category_id')->all())->all(),
    ];
    $before = $snapshot();

    // Without the exemption the net flags the reassignment statements; with it the whole request is clean.
    $delete = function () use ($ctx, $pets, $target) {
        app('auth')->forgetGuards();
        $this->flushSession();
        $this->actingAs($ctx['viewer'])->delete('/finance/categories/'.$pets, ['move_to' => $target])->assertStatus(303);
    };
    DB::beginTransaction();
    expect(fn () => FinancePrivacy::assertScopedMutations($delete))->toThrow(AssertionFailedError::class, 'Unscoped finance mutation');
    DB::rollBack();
    expect(App\Models\FinanceCategory::query()->whereKey($pets)->exists())->toBeTrue();

    FinancePrivacy::assertScopedMutations($delete, FH_LS_CATEGORY_EXEMPT);

    // The hidden rows moved to the target category and nothing else about them changed (no updated_at, no amounts).
    expect(App\Models\FinanceCategory::query()->whereKey($pets)->exists())->toBeFalse()
        ->and($snapshot())->toEqual($before)
        ->and(DB::table('finance_transactions')->where('description', 'like', 'ZxCanaryFin vet')->value('category_id'))->toBe($target)
        ->and(DB::table('finance_recurring')->where('name', 'like', 'ZxCanaryFin pet insurance')->value('category_id'))->toBe($target);
});

it('keeps the category exemption narrow', function () {
    $matches = fn (string $sql) => collect(FH_LS_CATEGORY_EXEMPT)->contains(fn ($p) => preg_match($p, strtolower($sql)) === 1);

    expect($matches('update finance_transactions set category_id = ? where category_id = ?'))->toBeTrue()
        ->and($matches('update finance_transactions set category_id = ?, amount_minor = 0 where category_id = ?'))->toBeFalse()
        ->and($matches('update finance_transactions set category_id = ? where id = ?'))->toBeFalse()
        ->and($matches('delete from finance_transactions where category_id = ?'))->toBeFalse()
        ->and($matches('update finance_accounts set category_id = ? where category_id = ?'))->toBeFalse();
});
