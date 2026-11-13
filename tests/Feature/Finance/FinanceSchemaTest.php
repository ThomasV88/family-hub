<?php

use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function fhSqlState(callable $fn): ?string
{
    try {
        DB::transaction($fn);
    } catch (QueryException $e) {
        return (string) $e->getPrevious()?->getCode();
    }

    return null;
}

function fhTx(FinanceAccount $account, array $attrs): array
{
    return array_merge([
        'id' => (string) Str::uuid7(),
        'account_id' => $account->id,
        'currency' => $account->currency,
        'kind' => 'expense',
        'amount_minor' => -100,
        'booked_on' => '2026-03-01',
        'source' => 'manual',
        'created_at' => now(),
        'updated_at' => now(),
    ], $attrs);
}

function fhInsert(array $row): void
{
    DB::table('finance_transactions')->insert($row);
}

it('refuses a transaction whose currency differs from its account', function () {
    $a = FinanceAccount::factory()->create();
    expect(fhSqlState(fn () => fhInsert(fhTx($a, ['currency' => 'CZK']))))->toBe('23503');
});

it('enforces amount bounds and sign by kind', function (string $kind, int $amount) {
    $a = FinanceAccount::factory()->create();
    expect(fhSqlState(fn () => fhInsert(fhTx($a, ['kind' => $kind, 'amount_minor' => $amount]))))->toBe('23514');
})->with([
    'zero' => ['expense', 0],
    'too big' => ['income', 10000000000001],
    'income negative' => ['income', -100],
    'expense positive' => ['expense', 100],
]);

it('has transfer rules', function () {
    $a = FinanceAccount::factory()->create();
    $ok = fhSqlState(fn () => fhInsert(fhTx($a, ['kind' => 'transfer', 'amount_minor' => -100, 'transfer_id' => (string) Str::uuid7()])));
    expect($ok)->toBeNull();
    expect(fhSqlState(fn () => fhInsert(fhTx($a, ['kind' => 'transfer', 'amount_minor' => -100]))))->toBe('23514');
    expect(fhSqlState(fn () => fhInsert(fhTx($a, ['kind' => 'expense', 'transfer_id' => (string) Str::uuid7()]))))->toBe('23514');
});

it('dedupes import hashes per account only', function () {
    $a = FinanceAccount::factory()->create();
    $b = FinanceAccount::factory()->create();
    $hash = str_repeat('a', 64);
    expect(fhSqlState(fn () => fhInsert(fhTx($a, ['import_hash' => $hash]))))->toBeNull();
    expect(fhSqlState(fn () => fhInsert(fhTx($a, ['import_hash' => $hash]))))->toBe('23505');
    expect(fhSqlState(fn () => fhInsert(fhTx($b, ['import_hash' => $hash]))))->toBeNull();
    expect(fhSqlState(fn () => fhInsert(fhTx($a, ['import_hash' => null]))))->toBeNull();
    expect(fhSqlState(fn () => fhInsert(fhTx($a, ['import_hash' => null]))))->toBeNull();
});

it('has the composite unique constraint', function () {
    $names = DB::table('pg_constraint')->pluck('conname')->all();
    expect($names)->toContain('finance_transactions_id_account_unique')
        ->and($names)->toContain('finance_accounts_id_currency_unique');
});

it('validates accounts', function (array $attrs) {
    $row = array_merge([
        'id' => (string) Str::uuid7(), 'name' => 'X', 'type' => 'bank', 'currency' => 'EUR',
        'opening_balance_minor' => 0, 'balance_as_of' => '2020-01-01', 'created_at' => now(), 'updated_at' => now(),
    ], $attrs);
    expect(fhSqlState(fn () => DB::table('finance_accounts')->insert($row)))->toBeIn(['23514', '22001']);
})->with([
    'lowercase currency' => [['currency' => 'eur']],
    'long currency' => [['currency' => 'EUROX']],
    'bad type' => [['type' => 'crypto']],
    'opening too high' => [['opening_balance_minor' => 10000000000001]],
    'opening too low' => [['opening_balance_minor' => -10000000000001]],
]);

it('seeds eleven default categories and validates custom ones', function () {
    $defaults = DB::table('finance_categories')->whereNotNull('default_key')->get();
    expect($defaults)->toHaveCount(11)
        ->and(DB::table('finance_categories')->count())->toBe(11)
        ->and($defaults->where('kind', 'income')->pluck('default_key')->sort()->values()->all())->toBe(['benefits', 'other_income', 'salary'])
        ->and($defaults->where('kind', 'expense')->pluck('default_key')->sort()->values()->all())
        ->toBe(['groceries', 'health', 'housing', 'kids', 'leisure', 'other', 'savings', 'transport']);

    $base = ['kind' => 'expense', 'created_at' => now(), 'updated_at' => now()];
    expect(fhSqlState(fn () => DB::table('finance_categories')->insert($base + ['id' => (string) Str::uuid7()])))->toBe('23514');
    DB::table('finance_categories')->insert($base + ['id' => (string) Str::uuid7(), 'name' => 'Pets']);
    expect(fhSqlState(fn () => DB::table('finance_categories')->insert($base + ['id' => (string) Str::uuid7(), 'name' => 'pets'])))->toBe('23505');
    DB::table('finance_categories')->insert(['kind' => 'income'] + $base + ['id' => (string) Str::uuid7(), 'name' => 'pets']);
});

it('uses UUIDv7 ids, casts and membership relation', function () {
    $user = User::factory()->create();
    $account = FinanceAccount::factory()->withMembers($user)->create(['opening_balance_minor' => 500]);
    $tx = FinanceTransaction::factory()->onAccount($account)->create();
    $cat = FinanceCategory::factory()->create();

    foreach ([$account, $tx, $cat] as $model) {
        expect($model->id)->toBeString()->and($model->id[14])->toBe('7');
    }
    expect($account->members->pluck('id')->all())->toBe([$user->id]);

    $tx = FinanceTransaction::query()->find($tx->id);
    expect($tx->amount_minor)->toBeInt()->and($tx->booked_on->format('Y-m-d'))->toBe('2026-03-15');
    expect(FinanceAccount::query()->find($account->id)->opening_balance_minor)->toBeInt()->toBe(500);
});
