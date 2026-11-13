<?php

use App\Access\Preset;
use App\Finance\Import\ImportDatabaseError;
use App\Finance\Import\ImportError;
use App\Finance\Import\ImportStager;
use App\Finance\Import\ProfileSpec;
use App\Finance\Queries\ImportQuery;
use App\Finance\Viewer;
use App\Models\FinanceAccount;
use App\Models\FinanceImportBatch;
use App\Models\FinanceTransaction;
use App\Models\User;
use Database\Factories\FinanceImportProfileFactory;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

function fhImpBytes(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/fixtures/finance/statement-sk-windows1250.csv');
}

function fhImpSpec(): ProfileSpec
{
    return ProfileSpec::fromArray(FinanceImportProfileFactory::skSettings());
}

/** @return array{0: User, 1: FinanceAccount, 2: Viewer} */
function fhImpSetup(): array
{
    $user = User::factory()->withPreset(Preset::Parent)->create();
    $account = FinanceAccount::factory()->withMembers($user)->create(['name' => 'Main']);

    return [$user, $account, Viewer::for($user)];
}

function fhImpStage(Viewer $viewer, FinanceAccount $account): string
{
    return app(ImportStager::class)->stage($viewer, $account, fhImpBytes(), fhImpSpec());
}

/** @return array<int, object> staged rows keyed by row number */
function fhImpRows(string $batchId): array
{
    return DB::table('finance_import_rows')->where('batch_id', $batchId)->orderBy('row_no')->get()->keyBy('row_no')->all();
}

it('stages the SK fixture as one draft batch with a row per record', function () {
    [$user, $account, $viewer] = fhImpSetup();

    $id = fhImpStage($viewer, $account);

    $batch = DB::table('finance_import_batches')->where('id', $id)->first();
    $rows = fhImpRows($id);
    expect($batch->status)->toBe('draft')
        ->and((int) $batch->user_id)->toBe($user->id)
        ->and($batch->account_id)->toBe($account->id)
        ->and((int) $batch->row_count)->toBe(9)
        ->and(array_keys($rows))->toBe(range(5, 13))
        ->and(collect($rows)->where('state', 'new')->count())->toBe(7)
        ->and(collect($rows)->where('state', 'invalid')->count())->toBe(2)
        ->and(collect($rows)->where('include', true)->pluck('state')->unique()->all())->toBe(['new'])
        ->and($rows[5]->description)->toBe('Zaplatené nájomné')
        ->and((int) $rows[5]->amount_minor)->toBe(-45000)
        ->and($rows[5]->booked_on)->toBe('2026-01-05')
        ->and($rows[12]->error_code)->toBe('columns');
});

it('flags a row whose hash is already booked on the same account and ignores another account', function () {
    [$user, $account, $viewer] = fhImpSetup();
    $other = FinanceAccount::factory()->withMembers($user)->create(['name' => 'Other']);
    $first = fhImpRows(fhImpStage($viewer, $account));
    $hashA = $first[5]->import_hash;
    $hashB = $first[6]->import_hash;

    FinanceTransaction::factory()->onAccount($account)->expense(45000)
        ->create(['source' => 'import', 'import_hash' => $hashA, 'booked_on' => '2026-01-05']);
    // The same hash string on another account must not count for this account.
    FinanceTransaction::factory()->onAccount($other)->expense(12050)
        ->create(['source' => 'import', 'import_hash' => $hashB, 'booked_on' => '2026-01-07']);

    $rows = fhImpRows(fhImpStage($viewer, $account));

    expect($rows[5]->state)->toBe('duplicate')
        ->and((bool) $rows[5]->include)->toBeFalse()
        ->and($rows[6]->state)->toBe('new')
        ->and((bool) $rows[6]->include)->toBeTrue();
});

it('flags a probable duplicate of a hand-entered transaction within 3 days, claimed by one row only', function () {
    [$user, $account, $viewer] = fhImpSetup();
    $hiddenAccount = FinanceAccount::factory()->withMembers(User::factory()->withPreset(Preset::Parent)->create())->create();

    $near = FinanceTransaction::factory()->onAccount($account)->expense(45000)->create(['booked_on' => '2026-01-07', 'description' => 'Rent by hand']);
    // 4 days apart from the 12,050 row: no effect. Hidden account with a matching amount and date: no effect.
    FinanceTransaction::factory()->onAccount($account)->expense(12050)->create(['booked_on' => '2026-01-11']);
    FinanceTransaction::factory()->onAccount($hiddenAccount)->income(250000)->create(['booked_on' => '2026-01-10']);
    // One hand-entered coffee for two identical bank rows.
    $coffee = FinanceTransaction::factory()->onAccount($account)->expense(250)->create(['booked_on' => '2026-01-21']);

    $rows = fhImpRows(fhImpStage($viewer, $account));

    expect($rows[5]->state)->toBe('probable_duplicate')
        ->and($rows[5]->probable_transaction_id)->toBe($near->id)
        ->and((bool) $rows[5]->include)->toBeFalse()
        ->and($rows[6]->state)->toBe('new')
        ->and($rows[7]->state)->toBe('new')
        ->and($rows[10]->state)->toBe('probable_duplicate')
        ->and($rows[10]->probable_transaction_id)->toBe($coffee->id)
        ->and($rows[11]->state)->toBe('new');
});

it('replaces only the same user drafts for the same account', function () {
    [$user, $account, $viewer] = fhImpSetup();
    $otherUser = User::factory()->withPreset(Preset::Parent)->create();
    $account->members()->attach($otherUser->id, ['created_at' => now()]);
    $otherDraft = FinanceImportBatch::factory()->forAccount($account, $otherUser)->withRows(1)->create();
    $committed = FinanceImportBatch::factory()->forAccount($account, $user)->committed()->create();

    $first = fhImpStage($viewer, $account);
    $second = fhImpStage($viewer, $account);

    $ids = DB::table('finance_import_batches')->pluck('id')->all();
    expect($ids)->not->toContain($first)
        ->and($ids)->toContain($second, $otherDraft->id, $committed->id);
});

it('refuses a hidden account without writing anything', function () {
    [$user, , $viewer] = fhImpSetup();
    $hidden = FinanceAccount::factory()->withMembers(User::factory()->withPreset(Preset::Parent)->create())->create();

    expect(fn () => fhImpStage($viewer, $hidden))->toThrow(NotFoundHttpException::class);
    expect(DB::table('finance_import_batches')->count())->toBe(0)
        ->and(DB::table('finance_import_rows')->count())->toBe(0);
});

it('reports a profile that does not fit the file', function () {
    [, $account, $viewer] = fhImpSetup();
    $settings = FinanceImportProfileFactory::skSettings();
    $settings['columns']['amount'] = ['name' => 'Betrag', 'index' => null];

    expect(fn () => app(ImportStager::class)->stage($viewer, $account, fhImpBytes(), ProfileSpec::fromArray($settings), true))
        ->toThrow(ImportError::class);
});

it('never writes the file to storage or logs its content', function () {
    Storage::fake('local');
    [, $account, $viewer] = fhImpSetup();
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e->message.json_encode($e->context);
    });

    fhImpStage($viewer, $account);

    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and(implode('', $logged))->not->toContain('nájomné')
        ->and(implode('', $logged))->not->toContain('Škôlka');
});

it('puts the membership subquery in every statement on a finance table while staging', function () {
    [$user, $account, $viewer] = fhImpSetup();
    FinanceTransaction::factory()->onAccount($account)->expense(45000)->create(['booked_on' => '2026-01-07']);
    FinanceImportBatch::factory()->forAccount($account, $user)->withRows(1)->create();

    $statements = [];
    DB::listen(function ($q) use (&$statements) {
        $statements[] = $q->sql;
    });
    fhImpStage($viewer, $account);

    $touching = array_values(array_filter($statements, fn (string $sql) => preg_match('/finance_transactions|finance_import_/i', $sql) === 1));
    expect($touching)->not->toBeEmpty();
    foreach ($touching as $sql) {
        expect(strtolower($sql))->toContain('finance_account_members');
    }
});

it('keeps CSV row text out of the log when a staging or commit statement fails', function () {
    [$user, $account, $viewer] = fhImpSetup();
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e->message.json_encode(array_map(fn ($c) => $c instanceof Throwable ? (string) $c : $c, $e->context));
    });
    $row = ['row_no' => 1, 'booked_on' => '2026-01-05', 'amount_minor' => -100, 'kind' => 'expense', 'description' => 'SECRETROWTEXT', 'counterparty' => 'SECRETCOUNTERPARTY', 'reference' => 'SECRETREF', 'import_hash' => str_repeat('a', 64), 'state' => 'bogus-state', 'error_code' => null, 'include' => true, 'probable_transaction_id' => null];

    $caught = null;
    try {
        DB::transaction(fn () => app(ImportQuery::class)->insertBatchWithRows($viewer, (string) $account->id, [], [$row]));
    } catch (Throwable $e) {
        $caught = $e;
        report($e);
    }

    expect($caught)->toBeInstanceOf(ImportDatabaseError::class)
        ->and($caught->getPrevious())->toBeNull()
        ->and($caught->getMessage())->not->toContain('SECRET')
        ->and($caught->getMessage())->toContain('SQLSTATE 23514')
        ->and(implode('', $logged))->not->toContain('SECRET');
});

it('sanitises a failing commit statement too', function () {
    [$user, $account, $viewer] = fhImpSetup();
    $batchId = fhImpStage($viewer, $account);
    $rows = array_values(array_filter(fhImpRows($batchId), fn ($r) => $r->state === 'new'));
    $txId = (string) Str::uuid7();
    $picked = array_map(fn ($r) => ['tx_id' => $txId, 'row_id' => $r->id, 'import_hash' => $r->import_hash, 'category_id' => null], array_slice($rows, 0, 2));

    $caught = null;
    try {
        DB::transaction(fn () => app(ImportQuery::class)->commitRows($viewer, $batchId, $picked));
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ImportDatabaseError::class)
        ->and($caught->getPrevious())->toBeNull()
        ->and($caught->getMessage())->not->toContain('nájomné')->not->toContain('Škôlka');
});
