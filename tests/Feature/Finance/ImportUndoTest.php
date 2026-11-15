<?php

use App\Access\Preset;
use App\Finance\Import\ImportStager;
use App\Finance\Import\ProfileSpec;
use App\Finance\Queries\RecurringQuery;
use App\Finance\Viewer;
use App\Models\FinanceAccount;
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
function fhIuSetup(): array
{
    $user = User::factory()->withPreset(Preset::Parent)->create();

    return [$user, FinanceAccount::factory()->withMembers($user)->create(['name' => 'Main'])];
}

function fhIuStage(User $user, FinanceAccount $account): string
{
    $bytes = (string) file_get_contents(dirname(__DIR__, 2).'/fixtures/finance/statement-sk-windows1250.csv');

    return app(ImportStager::class)->stage(Viewer::for($user), $account, $bytes, ProfileSpec::fromArray(FinanceImportProfileFactory::skSettings()));
}

function fhIuStatus(User $user, FinanceRecurring $item, string $date): ?string
{
    return app(RecurringQuery::class)->bill(Viewer::for($user), (string) $item->id, $date)['status'] ?? null;
}

function fhIuMatch(FinanceRecurring $item, FinanceTransaction $tx, string $date): void
{
    DB::table('finance_recurring_matches')->insert([
        'id' => (string) Str::uuid7(), 'recurring_id' => $item->id, 'account_id' => $tx->account_id, 'due_date' => $date,
        'transaction_id' => $tx->id, 'matched_by' => 'manual', 'expected_amount_minor' => 1200, 'created_by' => null, 'created_at' => now(),
    ]);
}

it('deletes pristine rows, keeps and detaches edited rows and transfer legs, and reverts matched bills', function () {
    [$user, $account] = fhIuSetup();
    $second = FinanceAccount::factory()->withMembers($user)->create(['name' => 'Second']);
    $batch = FinanceImportBatch::factory()->forAccount($account, $user)->committed()->create(['added_count' => 10]);

    $make = fn (int $i) => FinanceTransaction::factory()->onAccount($account)->expense(1200)->create([
        'source' => 'import', 'import_batch_id' => $batch->id, 'import_hash' => hash('sha256', "u{$i}"), 'booked_on' => '2026-01-04', 'description' => "Imported {$i}",
    ]);
    $txs = array_map($make, range(1, 9));

    // The transfer leg is seeded directly: kind is immutable, and it stays only because of its transfer_id.
    $transferId = (string) Str::uuid7();
    $leg = FinanceTransaction::factory()->onAccount($account)->create([
        'kind' => 'transfer', 'transfer_id' => $transferId, 'amount_minor' => -1200, 'source' => 'import', 'import_batch_id' => $batch->id,
        'import_hash' => hash('sha256', 'leg'), 'description' => 'Transfer leg',
    ]);
    FinanceTransaction::factory()->onAccount($second)->create(['kind' => 'transfer', 'transfer_id' => $transferId, 'amount_minor' => 1200]);

    foreach ([$txs[0], $txs[1]] as $edited) {
        $this->actingAs($user)->patch('/finance/transactions/'.$edited->id, ['amount' => '12,00', 'booked_on' => '2026-01-04', 'description' => 'Edited '.$edited->id])->assertStatus(303);
        expect(FinanceTransaction::query()->find($edited->id)->edited_at)->not->toBeNull();
    }

    $rent = FinanceRecurring::factory()->onAccount($account)->startingOn('2026-01-04')->create(['name' => 'Rent', 'expected_amount_minor' => 1200]);
    $gym = FinanceRecurring::factory()->onAccount($account)->startingOn('2026-01-03')->create(['name' => 'Gym', 'expected_amount_minor' => 1200]);
    fhIuMatch($rent, $txs[5], '2026-01-04');
    fhIuMatch($gym, $txs[0], '2026-01-03');
    expect(fhIuStatus($user, $rent, '2026-01-04'))->toBe('paid');

    $this->actingAs($user)->from('/finance/import')->post("/finance/import/{$batch->id}/undo")
        ->assertRedirect('/finance/import')->assertStatus(303)
        ->assertSessionHas('toast.key', 'finance.toast.imp_undone')
        ->assertSessionHas('toast_kept.key', 'finance.toast.imp_undone_kept')
        ->assertSessionHas('toast_kept.kept', 3);

    $left = FinanceTransaction::query()->where('account_id', $account->id)->get();
    expect($left)->toHaveCount(3)
        ->and($left->pluck('id')->sort()->values()->all())->toBe(collect([$txs[0]->id, $txs[1]->id, $leg->id])->sort()->values()->all())
        ->and($left->pluck('import_batch_id')->filter()->all())->toBe([])
        ->and(FinanceTransaction::query()->where('account_id', $second->id)->count())->toBe(1);
    $row = DB::table('finance_import_batches')->where('id', $batch->id)->first();
    expect($row->status)->toBe('undone')
        ->and($row->undone_at)->not->toBeNull()
        ->and((int) $row->kept_count)->toBe(3)
        ->and(fhIuStatus($user, $rent, '2026-01-04'))->toBe('overdue')
        ->and(FinanceRecurringMatch::query()->where('recurring_id', $gym->id)->count())->toBe(1)
        ->and(FinanceRecurringMatch::query()->where('recurring_id', $rent->id)->count())->toBe(0);
});

it('lets the statement be imported again after an undo and re-flags reconciled hand-entered rows as probable', function () {
    [$user, $account] = fhIuSetup();
    $this->actingAs($user)->post('/finance/transactions', [
        'kind' => 'expense', 'amount' => '450,00', 'booked_on' => '2026-01-06', 'description' => 'Rent by hand', 'account_id' => $account->id,
    ])->assertStatus(303);
    $manual = FinanceTransaction::query()->where('description', 'Rent by hand')->firstOrFail();

    $batch = fhIuStage($user, $account);
    $rows = DB::table('finance_import_rows')->where('batch_id', $batch)->where('state', 'new')->pluck('id')->all();
    $this->actingAs($user)->post("/finance/import/{$batch}/commit", ['include' => $rows])->assertStatus(303);
    expect($manual->refresh()->import_hash)->not->toBeNull();

    $this->actingAs($user)->post("/finance/import/{$batch}/undo")->assertStatus(303)->assertSessionMissing('toast_kept');
    $manual->refresh();
    expect($manual->import_hash)->toBeNull()
        ->and($manual->reconciled_batch_id)->toBeNull()
        ->and(FinanceTransaction::query()->count())->toBe(1);

    $again = DB::table('finance_import_rows')->where('batch_id', fhIuStage($user, $account))->orderBy('row_no')->get()->keyBy('row_no');
    expect($again[5]->state)->toBe('probable_duplicate')
        ->and($again[6]->state)->toBe('new')
        ->and($again->where('state', 'duplicate')->count())->toBe(0);
});

it('lets any member who sees the account undo, and answers everyone else with the same 404', function () {
    [$user, $account] = fhIuSetup();
    $member = User::factory()->withPreset(Preset::Parent)->create();
    $account->members()->attach($member->id, ['created_at' => now()]);
    $outsider = User::factory()->withPreset(Preset::Parent)->create();
    $batch = FinanceImportBatch::factory()->forAccount($account, $user)->committed()->create();
    $draft = FinanceImportBatch::factory()->forAccount($account, $user)->withRows(1)->create();

    $send = fn (User $by, string $id) => $this->actingAs($by)->postJson("/finance/import/{$id}/undo");
    $random = $send($outsider, (string) Str::uuid7());
    $random->assertNotFound();
    foreach ([[$outsider, $batch->id], [$user, $draft->id]] as [$by, $id]) {
        $response = $send($by, $id);
        $response->assertNotFound();
        expect($response->json('message'))->toBe($random->json('message'));
    }
    expect(DB::table('finance_import_batches')->where('id', $batch->id)->value('status'))->toBe('committed');

    $this->actingAs($member)->post("/finance/import/{$batch->id}/undo")->assertStatus(303);
    expect(DB::table('finance_import_batches')->where('id', $batch->id)->value('status'))->toBe('undone');

    $this->actingAs($member)->post("/finance/import/{$batch->id}/undo")->assertStatus(303)->assertSessionMissing('toast');
});

it('puts the membership subquery in every UPDATE and DELETE of an undo', function () {
    [$user, $account] = fhIuSetup();
    $batch = FinanceImportBatch::factory()->forAccount($account, $user)->committed()->create();
    FinanceTransaction::factory()->onAccount($account)->create(['source' => 'import', 'import_batch_id' => $batch->id, 'import_hash' => hash('sha256', 'x')]);
    $statements = [];
    DB::listen(function ($q) use (&$statements) {
        $statements[] = $q->sql;
    });

    $this->actingAs($user)->post("/finance/import/{$batch->id}/undo")->assertStatus(303);

    $touching = array_filter($statements, fn (string $sql) => preg_match('/^\s*(update|delete)\b.*finance_/is', $sql) === 1);
    expect($touching)->not->toBeEmpty();
    foreach ($touching as $sql) {
        expect(strtolower($sql))->toContain('finance_account_members');
    }
});

it('shows canUndo for committed batches in the history', function () {
    [$user, $account] = fhIuSetup();
    FinanceImportBatch::factory()->forAccount($account, $user)->committed()->create(['added_count' => 2]);
    FinanceImportBatch::factory()->forAccount($account, $user)->undone()->create();

    $this->actingAs($user)->get('/finance/import')->assertInertia(function ($page) {
        $history = collect($page->toArray()['props']['import']['history']);
        expect($history->where('status', 'committed')->first()['canUndo'])->toBeTrue()
            ->and($history->where('status', 'undone')->first()['canUndo'])->toBeFalse();
    });
});
