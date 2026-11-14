<?php

use App\Models\FinanceAccount;
use App\Models\FinanceTransaction;
use App\Models\MedicalAiExtraction;
use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use App\Models\ModulePermission;
use App\Models\Task;
use App\Models\User;
use App\Models\Wish;

beforeEach(function () {
    $this->originalEnv = app()->environment();
});

afterEach(function () {
    app()['env'] = $this->originalEnv;
});

function setEnv(string $env): void
{
    app()['env'] = $env;
}

it('refuses to seed in production', function () {
    setEnv('production');

    $this->artisan('staging:seed --force')
        ->expectsOutputToContain('refused')
        ->assertExitCode(1);

    expect(User::count())->toBe(0);
});

it('requires --force to seed', function () {
    setEnv('staging');

    $this->artisan('staging:seed')->assertExitCode(1);

    expect(User::count())->toBe(0);
});

it('seeds six synthetic members and one synthetic admin on staging', function () {
    setEnv('staging');

    $this->artisan('staging:seed --force')->assertExitCode(0);

    expect(User::count())->toBe(7)
        ->and(User::where('email', 'not like', '%@example.test')->count())->toBe(0)
        ->and(User::where('is_admin', true)->count())->toBe(1)
        ->and(User::where('is_admin', true)->first()->email)->toBe('admin@example.test');
});

it('gives members preset grants and the admin none', function () {
    setEnv('staging');

    $this->artisan('staging:seed --force')->assertExitCode(0);

    $admin = User::where('is_admin', true)->first();
    expect($admin->modulePermissions()->count())->toBe(0);
    foreach (User::where('is_admin', false)->get() as $member) {
        expect($member->modulePermissions()->count())->toBe(4);
    }
    expect(ModulePermission::where('module', 'health')->where('level', '>', 0)->count())->toBe(0);
});

it('is idempotent', function () {
    setEnv('staging');

    $this->artisan('staging:seed --force')->assertExitCode(0);
    $this->artisan('staging:seed --force')->assertExitCode(0);

    expect(User::count())->toBe(7);
});

it('audit passes when all users are synthetic', function () {
    setEnv('staging');
    User::factory()->create(['email' => 'a.1@example.test']);

    $this->artisan('staging:audit')->assertExitCode(0);
});

it('audit fails and names the count when real data exists', function () {
    setEnv('staging');
    User::factory()->create(['email' => 'a.1@example.test']);
    User::factory()->create(['email' => 'real@gmail.com']);

    $this->artisan('staging:audit')
        ->expectsOutputToContain('1 non-synthetic')
        ->assertExitCode(1);
});

it('audit refuses in production', function () {
    setEnv('production');

    $this->artisan('staging:audit')->assertExitCode(1);
});

it('audit fails on a real invited email, and on audit rows of real users', function () {
    setEnv('staging');
    $synthetic = User::factory()->create(['email' => 'a.1@example.test']);
    DB::table('invitations')->insert([
        'email' => 'real.person@gmail.com', 'token_hash' => str_repeat('a', 64), 'preset' => 'child', 'locale' => 'sk',
        'invited_by' => $synthetic->id, 'expires_at' => now()->addDay(),
    ]);
    DB::table('audit_logs')->insert(['event' => 'invite_created', 'actor_id' => $synthetic->id, 'meta' => json_encode(['email' => 'real.person@gmail.com'])]);

    $this->artisan('staging:audit')
        ->expectsOutputToContain('1 non-synthetic invitations')
        ->expectsOutputToContain('1 non-synthetic audit_logs')
        ->assertExitCode(1);
});

it('audit passes when invitations and audit rows only reference synthetic data', function () {
    setEnv('staging');
    $synthetic = User::factory()->create(['email' => 'a.1@example.test']);
    DB::table('invitations')->insert([
        'email' => 'eva@example.test', 'token_hash' => str_repeat('b', 64), 'preset' => 'child', 'locale' => 'sk',
        'invited_by' => $synthetic->id, 'expires_at' => now()->addDay(),
    ]);
    DB::table('audit_logs')->insert(['event' => 'invite_created', 'actor_id' => $synthetic->id, 'meta' => json_encode(['email' => 'eva@example.test'])]);

    $this->artisan('staging:audit')->assertExitCode(0);
});

it('gives every synthetic account the shared staging password when one is configured', function () {
    setEnv('staging');
    config(['family.staging_seed_password' => 'a-shared-staging-pass-1']);

    $this->artisan('staging:seed --force')->assertExitCode(0);

    foreach (User::all() as $user) {
        expect(Hash::check('a-shared-staging-pass-1', $user->password))->toBeTrue()
            ->and($user->password_set)->toBeTrue();
    }

    // a reseed (every staging deploy) keeps the same login
    $this->artisan('staging:seed --force')->assertExitCode(0);
    expect(Hash::check('a-shared-staging-pass-1', User::where('email', 'admin@example.test')->first()->password))->toBeTrue();
});

it('keeps random, unusable passwords when no shared password is set or it is too short', function () {
    setEnv('staging');

    foreach ([null, '', 'too-short'] as $value) {
        config(['family.staging_seed_password' => $value]);
        $this->artisan('staging:seed --force')->assertExitCode(0);

        foreach (User::all() as $user) {
            expect($user->password_set)->toBeFalse()
                ->and(Hash::check((string) $value, $user->password))->toBeFalse();
        }
    }
});

it('never reads the shared password in production', function () {
    setEnv('production');
    config(['family.staging_seed_password' => 'a-shared-staging-pass-1']);

    $this->artisan('staging:seed --force')->assertExitCode(1);

    expect(User::count())->toBe(0);
});

/** Activity of the synthetic accounts, as a tester would leave it on staging. */
function synthRows(User $user): void
{
    $account = FinanceAccount::factory()->create(['created_by' => $user->id]);
    DB::table('finance_account_members')->insert(['account_id' => $account->id, 'user_id' => $user->id]);
    FinanceTransaction::factory()->create(['account_id' => $account->id, 'created_by' => $user->id]);
    $subject = MedicalSubject::factory()->create(['created_by' => $user->id]);
    $document = MedicalDocument::factory()->pdf()->create(['subject_id' => $subject->id, 'uploaded_by' => $user->id]);
    MedicalAiExtraction::factory()->forDocument($document)->create(['started_by' => $user->id]);
    DB::table('ai_calls')->insert(['extraction_id' => (string) Str::uuid(), 'subject_id' => $subject->id, 'document_id' => $document->id,
        'started_by' => $user->id, 'agent' => 'codex', 'attempt' => 1, 'status' => 'failed', 'queued_at' => now()]);
    Task::factory()->create(['creator_id' => $user->id]);
    Wish::factory()->create(['owner_id' => $user->id]);
}

it('reseeds and audits cleanly after the synthetic admin produced audit rows (live staging failure)', function () {
    setEnv('staging');
    $this->artisan('staging:seed --force')->assertExitCode(0);
    $admin = User::where('email', 'admin@example.test')->first();
    foreach ([
        ['event' => 'login_failed', 'actor_id' => $admin->id, 'actor_label' => 'Admin (demo)', 'meta' => json_encode(['email' => 'admin@example.test'])],
        ['event' => 'login_failed', 'actor_id' => $admin->id, 'actor_label' => 'Admin (demo)'],
        ['event' => 'role_changed', 'actor_id' => $admin->id, 'actor_label' => 'Admin (demo)', 'target_user_id' => $admin->id, 'target_label' => 'Admin (demo)'],
        ['event' => 'login_failed', 'actor_id' => null, 'actor_label' => null],
    ] as $row) {
        DB::table('audit_logs')->insert($row);
    }
    $this->artisan('staging:audit')->assertExitCode(0);

    $this->artisan('staging:seed --force')->assertExitCode(0);

    expect(User::where('email', 'admin@example.test')->first()->id)->not->toBe($admin->id);
    $this->artisan('staging:audit')->assertExitCode(0);
    expect(DB::table('audit_logs')->count())->toBe(4);
});

it('reseeds and audits cleanly after synthetic users created finance, medical, AI, task and wish data', function () {
    setEnv('staging');
    $this->artisan('staging:seed --force')->assertExitCode(0);
    foreach (User::all() as $user) {
        synthRows($user);
    }
    $calls = DB::table('ai_calls')->count();
    expect($calls)->toBe(7);

    $this->artisan('staging:seed --force')->assertExitCode(0);

    foreach (['finance_accounts', 'finance_transactions', 'finance_account_members', 'medical_subjects', 'medical_documents',
        'medical_ai_extractions', 'tasks', 'wishes'] as $table) {
        expect(DB::table($table)->count())->toBe(0, $table);
    }
    // append-only history stays, and the audit accepts its dangling synthetic ids
    expect(DB::table('ai_calls')->count())->toBe($calls);
    $this->artisan('staging:audit')->assertExitCode(0);
});

it('still fails the audit for real data and a reseed keeps the real user rows', function () {
    setEnv('staging');
    $this->artisan('staging:seed --force')->assertExitCode(0);
    $synth = User::where('email', 'admin@example.test')->first();
    synthRows($synth);

    $real = User::factory()->create(['email' => 'real@gmail.com', 'name' => 'Real Person']);
    $account = FinanceAccount::factory()->create(['created_by' => $real->id]);
    DB::table('finance_account_members')->insert(['account_id' => $account->id, 'user_id' => $real->id]);
    FinanceTransaction::factory()->create(['account_id' => $account->id, 'created_by' => $real->id]);
    $subject = MedicalSubject::factory()->create(['created_by' => $real->id]);
    $document = MedicalDocument::factory()->pdf()->create(['subject_id' => $subject->id, 'uploaded_by' => $real->id]);
    MedicalAiExtraction::factory()->forDocument($document)->create(['started_by' => $real->id]);
    DB::table('ai_calls')->insert(['extraction_id' => (string) Str::uuid(), 'subject_id' => $subject->id, 'document_id' => $document->id,
        'started_by' => $real->id, 'agent' => 'codex', 'attempt' => 1, 'status' => 'failed', 'queued_at' => now()]);
    DB::table('audit_logs')->insert(['event' => 'login', 'actor_id' => $real->id, 'actor_label' => 'Real Person']);

    $audit = fn () => $this->artisan('staging:audit');
    $audit()->expectsOutputToContain('non-synthetic users')
        ->expectsOutputToContain('non-synthetic audit_logs')
        ->expectsOutputToContain('non-synthetic finance_accounts')
        ->expectsOutputToContain('non-synthetic finance_transactions')
        ->expectsOutputToContain('non-synthetic medical_subjects')
        ->expectsOutputToContain('non-synthetic medical_ai_extractions')
        ->expectsOutputToContain('non-synthetic ai_calls')
        ->assertExitCode(1);

    $this->artisan('staging:seed --force')->assertExitCode(0);

    expect(User::where('email', 'real@gmail.com')->exists())->toBeTrue()
        ->and(DB::table('finance_accounts')->where('created_by', $real->id)->count())->toBe(1)
        ->and(DB::table('finance_transactions')->where('created_by', $real->id)->count())->toBe(1)
        ->and(DB::table('medical_subjects')->where('created_by', $real->id)->count())->toBe(1)
        ->and(DB::table('medical_ai_extractions')->where('started_by', $real->id)->count())->toBe(1)
        ->and(DB::table('ai_calls')->where('started_by', $real->id)->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('actor_id', $real->id)->count())->toBe(1)
        // the synthetic admin's rows are gone, the real user's are not
        ->and(DB::table('finance_accounts')->count())->toBe(1)
        ->and(DB::table('medical_subjects')->count())->toBe(1);
    $audit()->assertExitCode(1);
});

it('audit flags a dangling audit actor or target only when its stored label is not a demo label', function (array $row, int $exit) {
    setEnv('staging');
    User::factory()->create(['email' => 'a.1@example.test']);
    DB::table('audit_logs')->insert($row + ['event' => 'x']);

    $this->artisan('staging:audit')->assertExitCode($exit);
})->with([
    'dangling actor, real label' => [['actor_id' => 999999, 'actor_label' => 'Jane Doe'], 1],
    'dangling target, real label' => [['target_user_id' => 999999, 'target_label' => 'Jane Doe'], 1],
    'dangling actor, demo label' => [['actor_id' => 999999, 'actor_label' => 'Jane (demo)'], 0],
    'dangling actor, no label' => [['actor_id' => 999999], 0],
    'real meta email' => [['meta' => '{"email":"jane@gmail.com"}'], 1],
]);

it('audit flags a live real user as audit actor or target even with a demo-looking label', function (string $column) {
    setEnv('staging');
    $real = User::factory()->create(['email' => 'real@gmail.com']);
    DB::table('audit_logs')->insert(['event' => 'x', $column => $real->id, 'actor_label' => 'Sneaky (demo)']);

    // the real user already fails on its own, so look at the audit_logs line specifically
    $this->artisan('staging:audit')->expectsOutputToContain('non-synthetic audit_logs')->assertExitCode(1);
})->with(['actor_id', 'target_user_id']);

it('audit flags started_by of a live real user but accepts a dangling one', function (string $table) {
    setEnv('staging');
    $this->artisan('staging:audit')->assertExitCode(0);
    $row = $table === 'ai_calls'
        ? fn (int $by) => DB::table('ai_calls')->insert(['extraction_id' => (string) Str::uuid(), 'subject_id' => (string) Str::uuid(),
            'document_id' => (string) Str::uuid(), 'started_by' => $by, 'agent' => 'codex', 'attempt' => 1, 'status' => 'failed', 'queued_at' => now()])
        : function (int $by) {
            $subject = MedicalSubject::factory()->create(['created_by' => User::where('email', 'a.1@example.test')->value('id')]);
            $document = MedicalDocument::factory()->pdf()->create(['subject_id' => $subject->id]);
            MedicalAiExtraction::factory()->forDocument($document)->create(['started_by' => $by]);
        };
    User::factory()->create(['email' => 'a.1@example.test']);
    $row(424242);
    $this->artisan('staging:audit')->assertExitCode(0);

    $real = User::factory()->create(['email' => 'real@gmail.com']);
    $row($real->id);
    $this->artisan('staging:audit')->expectsOutputToContain("non-synthetic {$table}")->assertExitCode(1);
})->with(['ai_calls', 'medical_ai_extractions']);
