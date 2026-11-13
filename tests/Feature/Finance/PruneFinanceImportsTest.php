<?php

use App\Models\FinanceAccount;
use App\Models\FinanceImportBatch;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

it('deletes draft batches older than the TTL with their rows and keeps everything else', function () {
    $user = User::factory()->create();
    $account = FinanceAccount::factory()->withMembers($user)->create();
    $make = fn (string $state, int $hoursAgo) => FinanceImportBatch::factory()->forAccount($account, $user)
        ->{$state}()->withRows(2)->create(['created_at' => now()->subHours($hoursAgo)])->id;

    $oldDraft = FinanceImportBatch::factory()->forAccount($account, $user)->withRows(2)->create(['created_at' => now()->subHours(25)])->id;
    $newDraft = FinanceImportBatch::factory()->forAccount($account, $user)->withRows(2)->create(['created_at' => now()->subHours(23)])->id;
    $oldCommitted = $make('committed', 100);
    $oldUndone = $make('undone', 100);

    $this->artisan('finance:prune-imports')->assertSuccessful();

    $ids = DB::table('finance_import_batches')->pluck('id')->all();
    expect($ids)->not->toContain($oldDraft)
        ->and($ids)->toContain($newDraft, $oldCommitted, $oldUndone)
        ->and(DB::table('finance_import_rows')->where('batch_id', $oldDraft)->count())->toBe(0)
        ->and(DB::table('finance_import_rows')->where('batch_id', $newDraft)->count())->toBe(2);
});

it('honours the configured TTL', function () {
    config(['finance.import.draft_ttl_hours' => 1]);
    $user = User::factory()->create();
    $account = FinanceAccount::factory()->withMembers($user)->create();
    $draft = FinanceImportBatch::factory()->forAccount($account, $user)->create(['created_at' => now()->subHours(2)]);

    $this->artisan('finance:prune-imports')->assertSuccessful();

    expect(DB::table('finance_import_batches')->where('id', $draft->id)->exists())->toBeFalse();
});

it('is scheduled daily', function () {
    $events = collect(app(Schedule::class)->events())->keyBy(fn ($e) => $e->description);

    expect($events['finance-prune-imports']->expression)->toBe('0 0 * * *')
        ->and($events['finance-prune-imports']->command)->toContain('finance:prune-imports');
});
