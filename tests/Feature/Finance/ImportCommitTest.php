<?php

use App\Access\Preset;
use App\Finance\Import\ImportStager;
use App\Finance\Import\ProfileSpec;
use App\Finance\Queries\ImportQuery;
use App\Finance\Viewer;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceImportBatch;
use App\Models\FinanceRecurring;
use App\Models\FinanceRecurringMatch;
use App\Models\FinanceTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\FinanceImportProfileFactory;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->travelTo(CarbonImmutable::parse('2026-01-10 09:00', config('family.timezone')));
});

/** @return array{0: User, 1: FinanceAccount} */
function fhIcSetup(): array
{
    $user = User::factory()->withPreset(Preset::Parent)->create();

    return [$user, FinanceAccount::factory()->withMembers($user)->create(['name' => 'Main'])];
}

function fhIcStage(User $user, FinanceAccount $account): string
{
    $bytes = (string) file_get_contents(dirname(__DIR__, 2).'/fixtures/finance/statement-sk-windows1250.csv');

    return app(ImportStager::class)->stage(Viewer::for($user), $account, $bytes, ProfileSpec::fromArray(FinanceImportProfileFactory::skSettings()));
}

/** @return array<int, object> staged rows by row number */
function fhIcRows(string $batchId): array
{
    return DB::table('finance_import_rows')->where('batch_id', $batchId)->orderBy('row_no')->get()->keyBy('row_no')->all();
}

/** @return list<string> ids of the staged rows in the given states */
function fhIcIds(string $batchId, array $states = ['new']): array
{
    return collect(fhIcRows($batchId))->whereIn('state', $states)->pluck('id')->values()->all();
}

it('commits the ticked rows with exact amounts and keeps only counts', function () {
    [$user, $account] = fhIcSetup();
    $batch = fhIcStage($user, $account);
    $rows = fhIcRows($batch);

    $this->actingAs($user)->post("/finance/import/{$batch}/commit", ['include' => fhIcIds($batch)])
        ->assertRedirect("/finance/import/{$batch}")->assertStatus(303);

    $txs = FinanceTransaction::query()->where('account_id', $account->id)->get()->keyBy('description');
    expect(FinanceTransaction::query()->where('account_id', $account->id)->count())->toBe(7)
        ->and($txs['Zaplatené nájomné']->amount_minor)->toBe(-45000)
        ->and($txs['Výplata']->amount_minor)->toBe(250000)
        ->and($txs['Výplata']->kind)->toBe('income')
        ->and($txs['Zaplatené nájomné']->source)->toBe('import')
        ->and($txs['Zaplatené nájomné']->import_batch_id)->toBe($batch)
        ->and($txs['Zaplatené nájomné']->import_hash)->toBe($rows[5]->import_hash)
        ->and($txs['Zaplatené nájomné']->booked_on->format('Y-m-d'))->toBe('2026-01-05')
        ->and($txs['Zaplatené nájomné']->currency)->toBe($account->currency)
        ->and($txs['Zaplatené nájomné']->created_by)->toBe($user->id);
    $row = DB::table('finance_import_batches')->where('id', $batch)->first();
    expect($row->status)->toBe('committed')
        ->and((int) $row->added_count)->toBe(7)
        ->and((int) $row->matched_count)->toBe(0)
        ->and($row->committed_at)->not->toBeNull()
        ->and(DB::table('finance_import_rows')->where('batch_id', $batch)->count())->toBe(0);
});

it('applies a category override only when it fits the row kind', function () {
    [$user, $account] = fhIcSetup();
    $batch = fhIcStage($user, $account);
    $rows = fhIcRows($batch);
    $expense = FinanceCategory::query()->where('default_key', 'groceries')->firstOrFail();
    $income = FinanceCategory::query()->where('default_key', 'salary')->firstOrFail();

    $this->actingAs($user)->post("/finance/import/{$batch}/commit", [
        'include' => fhIcIds($batch),
        'categories' => [$rows[6]->id => $expense->id, $rows[7]->id => $expense->id, $rows[9]->id => $income->id],
    ])->assertStatus(303);

    $byDesc = FinanceTransaction::query()->get()->keyBy('description');
    expect($byDesc['Škôlka január']->category_id)->toBe($expense->id)
        ->and($byDesc['Výplata']->category_id)->toBeNull()
        ->and($byDesc['Prevod od rodiny']->category_id)->toBe($income->id)
        ->and($byDesc['Zaplatené nájomné']->category_id)->toBeNull();
});

it('adds nothing the second time (double click, second tab, direct replay)', function () {
    [$user, $account] = fhIcSetup();
    $batch = fhIcStage($user, $account);
    $ids = fhIcIds($batch);
    $picked = collect(fhIcRows($batch))->where('state', 'new')->map(fn ($r) => [
        'tx_id' => (string) Str::uuid7(), 'row_id' => $r->id, 'import_hash' => $r->import_hash, 'category_id' => null,
    ])->values()->all();

    $this->actingAs($user)->post("/finance/import/{$batch}/commit", ['include' => $ids])->assertStatus(303);
    $this->actingAs($user)->post("/finance/import/{$batch}/commit", ['include' => $ids])->assertRedirect("/finance/import/{$batch}");
    $this->actingAs($user)->post("/finance/import/{$batch}/commit", [])->assertRedirect("/finance/import/{$batch}");
    expect(FinanceTransaction::query()->count())->toBe(7)
        ->and((int) DB::table('finance_import_batches')->where('id', $batch)->value('added_count'))->toBe(7);

    // A concurrent session that raced past the draft check is stopped by the partial unique index.
    $draft = FinanceImportBatch::factory()->forAccount($account, $user)->withRows(1)->create();
    $row = DB::table('finance_import_rows')->where('batch_id', $draft->id)->first();
    $hash = FinanceTransaction::query()->firstOrFail()->import_hash;
    $inserted = app(ImportQuery::class)->commitRows(Viewer::for($user), $draft->id, [
        ['tx_id' => (string) Str::uuid7(), 'row_id' => $row->id, 'import_hash' => $hash, 'category_id' => null],
    ]);
    expect($inserted)->toBe([])->and(FinanceTransaction::query()->count())->toBe(7);
    expect($picked)->toHaveCount(7);
});

it('stages a re-uploaded statement as duplicates and imports a ticked one with a derived hash', function () {
    [$user, $account] = fhIcSetup();
    $first = fhIcStage($user, $account);
    $this->actingAs($user)->post("/finance/import/{$first}/commit", ['include' => fhIcIds($first)])->assertStatus(303);

    $second = fhIcStage($user, $account);
    $rows = fhIcRows($second);
    expect(collect($rows)->where('state', 'duplicate')->count())->toBe(7);

    $this->actingAs($user)->post("/finance/import/{$second}/commit", ['include' => [$rows[5]->id]])->assertStatus(303);

    $extra = FinanceTransaction::query()->where('import_batch_id', $second)->firstOrFail();
    expect(FinanceTransaction::query()->count())->toBe(8)
        ->and($extra->import_hash)->toBe(hash('sha256', $rows[5]->import_hash.'|'.$second))
        ->and($extra->import_hash)->not->toBe($rows[5]->import_hash);
});

it('reconciles an unticked probable duplicate: hash on the hand-entered payment, match kept, re-import dedupes', function () {
    [$user, $account] = fhIcSetup();
    $rent = FinanceRecurring::factory()->onAccount($account)->startingOn('2026-01-05')->create(['name' => 'Rent', 'expected_amount_minor' => 45000]);
    $this->actingAs($user)->post('/finance/transactions', [
        'kind' => 'expense', 'amount' => '450,00', 'booked_on' => '2026-01-06', 'description' => 'Rent by hand', 'account_id' => $account->id,
    ])->assertStatus(303);
    $manual = FinanceTransaction::query()->where('description', 'Rent by hand')->firstOrFail();
    expect(FinanceRecurringMatch::query()->where('transaction_id', $manual->id)->exists())->toBeTrue();

    $batch = fhIcStage($user, $account);
    $rows = fhIcRows($batch);
    expect($rows[5]->state)->toBe('probable_duplicate');

    $this->actingAs($user)->post("/finance/import/{$batch}/commit", ['include' => fhIcIds($batch)])->assertStatus(303);

    $manual->refresh();
    expect($manual->import_hash)->toBe($rows[5]->import_hash)
        ->and($manual->reconciled_batch_id)->toBe($batch)
        ->and($manual->source)->toBe('manual')
        ->and($manual->import_batch_id)->toBeNull()
        ->and(FinanceTransaction::query()->count())->toBe(7)
        ->and(FinanceRecurringMatch::query()->where('transaction_id', $manual->id)->where('recurring_id', $rent->id)->exists())->toBeTrue()
        ->and((int) DB::table('finance_import_batches')->where('id', $batch)->value('matched_count'))->toBe(0);

    $again = fhIcRows(fhIcStage($user, $account));
    expect($again[5]->state)->toBe('duplicate');
});

it('matches imported payments to recurring expenses and previews the match before commit without writing', function () {
    [$user, $account] = fhIcSetup();
    FinanceRecurring::factory()->onAccount($account)->startingOn('2026-01-05')->create(['name' => 'Rent', 'expected_amount_minor' => 45000]);
    $batch = fhIcStage($user, $account);
    $rows = fhIcRows($batch);

    $this->actingAs($user)->get("/finance/import/{$batch}")->assertInertia(function ($page) use ($rows) {
        $byId = collect($page->toArray()['props']['batch']['rows'])->keyBy('id');
        expect($byId[$rows[5]->id]['matchName'])->toBe('Rent')
            ->and($byId[$rows[6]->id]['matchName'])->toBeNull();
    });
    expect(FinanceRecurringMatch::query()->count())->toBe(0);

    $this->actingAs($user)->post("/finance/import/{$batch}/commit", ['include' => fhIcIds($batch)])->assertStatus(303);

    expect(FinanceRecurringMatch::query()->count())->toBe(1)
        ->and((int) DB::table('finance_import_batches')->where('id', $batch)->value('matched_count'))->toBe(1);
    $this->actingAs($user)->get("/finance/import/{$batch}")->assertInertia(function ($page) {
        expect($page->toArray()['props']['batch']['result'])->toBe(['added' => 7, 'matched' => 1, 'kept' => 0]);
    });
});

it('rejects an invalid row, a row of another batch and an empty selection', function () {
    [$user, $account] = fhIcSetup();
    $batch = fhIcStage($user, $account);
    $rows = fhIcRows($batch);
    $foreign = FinanceImportBatch::factory()->forAccount($account, $user)->withRows(1)->create();
    $foreignRow = DB::table('finance_import_rows')->where('batch_id', $foreign->id)->value('id');

    $this->actingAs($user)->postJson("/finance/import/{$batch}/commit", ['include' => [$rows[12]->id]])->assertStatus(422);
    $this->actingAs($user)->postJson("/finance/import/{$batch}/commit", ['include' => [$rows[5]->id, $foreignRow]])->assertStatus(422);
    $this->actingAs($user)->postJson("/finance/import/{$batch}/commit", ['include' => ['nope']])->assertStatus(422);
    $this->actingAs($user)->postJson("/finance/import/{$batch}/commit", ['include' => []])
        ->assertStatus(422)->assertJsonPath('errors.include.0', 'finance.imp.select_one');

    expect(FinanceTransaction::query()->count())->toBe(0)
        ->and(DB::table('finance_import_batches')->where('id', $batch)->value('status'))->toBe('draft');
});

it('answers a hidden, foreign or unknown batch with the same 404', function () {
    [$user, $account] = fhIcSetup();
    $other = User::factory()->withPreset(Preset::Parent)->create();
    $hiddenAccount = FinanceAccount::factory()->withMembers($other)->create();
    $hidden = FinanceImportBatch::factory()->forAccount($hiddenAccount, $other)->withRows(1)->create();
    $foreignDraft = FinanceImportBatch::factory()->forAccount($account, $other)->withRows(1);
    $account->members()->attach($other->id, ['created_at' => now()]);
    $foreignDraft = $foreignDraft->create();

    $send = fn (string $id) => $this->actingAs($user)->postJson("/finance/import/{$id}/commit", ['include' => []]);
    $random = $send((string) Str::uuid7());
    $random->assertNotFound();
    foreach ([$hidden->id, $foreignDraft->id] as $id) {
        $response = $send($id);
        $response->assertNotFound();
        expect($response->json('message'))->toBe($random->json('message'));
    }
    expect(DB::table('finance_import_rows')->count())->toBe(2);
});

it('discards a draft for its creator only', function () {
    [$user, $account] = fhIcSetup();
    $other = User::factory()->withPreset(Preset::Parent)->create();
    $account->members()->attach($other->id, ['created_at' => now()]);
    $batch = fhIcStage($user, $account);

    $this->actingAs($other)->delete("/finance/import/{$batch}")->assertNotFound();
    expect(DB::table('finance_import_batches')->where('id', $batch)->exists())->toBeTrue();

    $this->actingAs($user)->delete("/finance/import/{$batch}")->assertRedirect('/finance/import')->assertStatus(303);
    expect(DB::table('finance_import_batches')->where('id', $batch)->exists())->toBeFalse()
        ->and(DB::table('finance_import_rows')->where('batch_id', $batch)->count())->toBe(0);
});

it('puts the membership subquery in every UPDATE and DELETE of a commit', function () {
    [$user, $account] = fhIcSetup();
    $batch = fhIcStage($user, $account);
    $statements = [];
    DB::listen(function ($q) use (&$statements) {
        $statements[] = $q->sql;
    });

    $this->actingAs($user)->post("/finance/import/{$batch}/commit", ['include' => fhIcIds($batch)])->assertStatus(303);

    $touching = array_filter($statements, fn (string $sql) => preg_match('/^\s*(update|delete|insert)\b.*finance_/is', $sql) === 1);
    expect($touching)->not->toBeEmpty();
    foreach ($touching as $sql) {
        expect(strtolower($sql))->toContain('finance_account_members');
    }
});
