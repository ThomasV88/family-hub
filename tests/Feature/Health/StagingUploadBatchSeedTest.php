<?php

use App\Models\MedicalSubject;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->originalEnv = app()->environment();
    app()['env'] = 'staging';
    Storage::fake('medical');
    Storage::fake('local');
});

afterEach(function () {
    app()['env'] = $this->originalEnv;
});

function stgBatchSeed(): void
{
    test()->artisan('staging:seed --force')->assertExitCode(0);
}

function stgBatchPerson(): MedicalSubject
{
    return MedicalSubject::query()->where('user_id', User::where('email', 'sk_sk.1@example.test')->value('id'))->firstOrFail();
}

it('seeds one open batch with five items in every state, written through the production services', function () {
    stgBatchSeed();
    $subject = stgBatchPerson();

    $batch = DB::table('medical_upload_batches')->where('subject_id', $subject->getKey())->get();
    expect($batch)->toHaveCount(1)
        ->and($batch[0]->status)->toBe('open')
        ->and(abs(now()->addDays(30)->diffInDays($batch[0]->expires_at)))->toBeLessThan(1);

    $states = DB::table('medical_upload_batch_items as i')
        ->leftJoin('medical_ai_extractions as e', 'e.id', '=', 'i.extraction_id')
        ->where('i.batch_id', $batch[0]->id)->orderBy('i.position')
        ->get(['i.position', 'i.state', 'e.status as run', 'e.failure_code', 'i.choices']);
    expect($states->map(fn ($r) => [$r->state, $r->run])->all())->toBe([
        ['done', 'ready'], ['sent', 'ready'], ['sent', 'failed'], ['sent', 'waiting'], ['pending', null],
    ]);
    expect($states[2]->failure_code)->toBe('unreadable_document');
    $choices = json_decode((string) $states[1]->choices, true);
    expect($choices['visit-0']['decision'])->toBe('approve')
        ->and($choices['visit-0']['values']['diagnosis'])->toContain('upravené')
        ->and($choices['medication-0']['decision'])->toBe('reject');

    // The continued document is an ordinary one with its accepted record; the four others are pending (hidden) documents.
    expect(DB::table('medical_documents')->where('subject_id', $subject->getKey())->whereNotNull('upload_batch_id')->count())->toBe(4)
        ->and(DB::table('medical_allergies')->where('subject_id', $subject->getKey())->where('source', 'ai')->where('substance', 'Penicilín (demo)')->count())->toBe(1);
    $this->artisan('staging:audit')->assertExitCode(0);
});

it('leaves one open batch after a second seed', function () {
    stgBatchSeed();
    stgBatchSeed();

    expect(DB::table('medical_upload_batches')->count())->toBe(1)
        ->and(DB::table('medical_upload_batch_items')->count())->toBe(5);
    $this->artisan('staging:audit')->assertExitCode(0);
});

it('does not start a real run: the paused run holds the batch, so the tick leaves the waiting item alone', function () {
    stgBatchSeed();
    $before = DB::table('medical_ai_extractions')->count();

    $this->artisan('ai:upload-batch-tick');

    expect(DB::table('medical_ai_extractions')->count())->toBe($before)
        ->and(DB::table('medical_upload_batch_items')->where('state', 'pending')->count())->toBe(1);
});

it('fails staging:audit on a batch or item of a non-synthetic user', function () {
    stgBatchSeed();
    $real = User::factory()->create(['email' => 'user@real.sk']);

    DB::table('medical_upload_batches')->update(['acted_by' => $real->id]);
    $this->artisan('staging:audit')->expectsOutputToContain('non-synthetic medical_upload_batches')->assertExitCode(1);
    DB::table('medical_upload_batches')->update(['acted_by' => null]);
    // the real user itself still fails the users rule, but the batch rules are quiet again
    $this->artisan('staging:audit')->doesntExpectOutputToContain('medical_upload_batch')->assertExitCode(1);

    DB::table('medical_upload_batch_items')->where('position', 2)->update(['requested_by' => $real->id]);
    $this->artisan('staging:audit')->expectsOutputToContain('non-synthetic medical_upload_batch_items')->assertExitCode(1);
    DB::table('medical_upload_batch_items')->update(['requested_by' => null]);

    DB::table('medical_upload_batches')->update(['created_by' => $real->id]);
    $this->artisan('staging:audit')->expectsOutputToContain('non-synthetic medical_upload_batches')->assertExitCode(1);
});
