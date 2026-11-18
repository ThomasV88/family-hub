<?php

use App\Access\AccessLevel;
use App\Medical\Queries\UploadBatchJobQuery;
use App\Models\MedicalAiExtraction;
use App\Models\MedicalDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('medical');
});

/**
 * An open batch of three unfinished documents (one with an accepted decision), plus one document already finished with
 * Continue (no longer referencing the batch).
 *
 * @return array<string, mixed>
 */
function ubExpBatch(string $expiresAt = '-1 hour'): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user, AccessLevel::Edit);
    $person = MedicalFixtures::subject(['ai_consent' => true], [[$user, AccessLevel::Edit]]);
    $batch = (string) Str::uuid();
    DB::table('medical_upload_batches')->insert([
        'id' => $batch, 'subject_id' => $person->id, 'created_by' => $user->id, 'acted_by' => $user->id, 'status' => 'open',
        'expires_at' => CarbonImmutable::parse($expiresAt), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $docs = [];
    foreach ([1, 2, 3, 4] as $i) {
        $finished = $i === 4;
        $doc = MedicalDocument::factory()->image()->forSubject($person)->create(['title' => 'SECRET-TITLE-'.$i, 'upload_batch_id' => $finished ? null : $batch]);
        Storage::disk('medical')->put($doc->path, 'full');
        Storage::disk('medical')->put($doc->thumb_path, 'thumb');
        DB::table('medical_upload_batch_items')->insert([
            'id' => (string) Str::uuid(), 'batch_id' => $batch, 'document_id' => $doc->id, 'position' => $i,
            'state' => $finished ? 'done' : 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $docs[$i] = $doc;
    }
    // Document 2 already has an accepted decision.
    $ext = MedicalAiExtraction::factory()->forDocument($docs[2])->ready()->create(['started_by' => $user->id]);
    DB::table('medical_ai_decisions')->insert([
        'extraction_id' => $ext->id, 'entry_key' => 'e1', 'decision' => 'accepted', 'record_type' => 'visit',
        'record_id' => (string) Str::uuid(), 'decided_by' => $user->id,
    ]);
    // Document 1 has a queued batch run and one more extraction behind it.
    MedicalAiExtraction::factory()->forDocument($docs[1])->create(['started_by' => $user->id, 'upload_batch_id' => $batch]);

    return ['user' => $user, 'person' => $person, 'batch' => $batch, 'docs' => $docs, 'ext' => $ext];
}

it('purges a batch: unfinished documents and files go, accepted and finished ones stay, one audit row with counts only', function (string $action) {
    $s = ubExpBatch();
    $docs = $s['docs'];

    $removed = app(UploadBatchJobQuery::class)->purge($s['batch'], $s['user']->id, $action);

    expect($removed)->toBe(2)
        ->and(DB::table('medical_upload_batches')->count())->toBe(0)
        ->and(DB::table('medical_upload_batch_items')->count())->toBe(0)
        ->and(DB::table('medical_documents')->whereIn('id', [$docs[1]->id, $docs[3]->id])->count())->toBe(0)
        ->and(DB::table('medical_ai_extractions')->where('document_id', $docs[1]->id)->count())->toBe(0);
    Storage::disk('medical')->assertMissing($docs[1]->path);
    Storage::disk('medical')->assertMissing($docs[1]->thumb_path);
    Storage::disk('medical')->assertMissing($docs[3]->path);
    Storage::disk('medical')->assertMissing($docs[3]->thumb_path);

    // The accepted-decision document and the Continue-finished one are ordinary documents now, files intact.
    foreach ([2, 4] as $i) {
        expect(DB::table('medical_documents')->where('id', $docs[$i]->id)->value('upload_batch_id'))->toBeNull();
        Storage::disk('medical')->assertExists($docs[$i]->path);
        Storage::disk('medical')->assertExists($docs[$i]->thumb_path);
    }
    expect(DB::table('medical_ai_decisions')->where('extraction_id', $s['ext']->id)->count())->toBe(1);

    $audit = DB::table('audit_logs')->where('event', 'medical_upload_batch_changed')->get();
    expect($audit)->toHaveCount(1)
        ->and(json_decode($audit[0]->meta, true))->toEqual(['subject' => $s['person']->id, 'batch' => $s['batch'], 'action' => $action, 'count' => 2]);
    $dump = json_encode(DB::table('audit_logs')->get());
    foreach ([1, 2, 3, 4] as $i) {
        expect($dump)->not->toContain('SECRET-TITLE-'.$i)->not->toContain($docs[$i]->path);
    }
})->with(['deleted', 'expired']);

it('purge of an unknown batch removes nothing and a bad action is refused', function () {
    $s = ubExpBatch();
    expect(app(UploadBatchJobQuery::class)->purge((string) Str::uuid(), null, 'deleted'))->toBe(0)
        ->and(fn () => app(UploadBatchJobQuery::class)->purge($s['batch'], null, 'other'))->toThrow(InvalidArgumentException::class)
        ->and(DB::table('medical_upload_batches')->count())->toBe(1);
});

it('cancels queued, waiting and running batch runs, optionally for one document', function () {
    $s = ubExpBatch();
    $make = function (MedicalDocument $doc, string $state) use ($s) {
        $ext = MedicalAiExtraction::factory()->forDocument($doc)->{$state}()->create(['started_by' => $s['user']->id, 'upload_batch_id' => $s['batch']]);
        DB::table('ai_calls')->insert([
            'extraction_id' => $ext->id, 'subject_id' => $doc->subject_id, 'document_id' => $doc->id, 'started_by' => $s['user']->id,
            'agent' => 'codex', 'attempt' => 1, 'status' => $state === 'running' ? 'running' : 'queued', 'queued_at' => now(),
        ]);

        return $ext->id;
    };
    // Document 1 already has a queued run from the fixture; replace it.
    DB::table('medical_ai_extractions')->where('document_id', $s['docs'][1]->id)->delete();
    $queued = $make($s['docs'][1], 'queued');
    $waiting = $make($s['docs'][3], 'waiting');
    DB::table('medical_documents')->where('id', $s['docs'][2]->id)->update(['upload_batch_id' => $s['batch']]);
    DB::table('medical_ai_extractions')->where('document_id', $s['docs'][2]->id)->delete();
    $running = $make($s['docs'][2], 'running');
    $q = app(UploadBatchJobQuery::class);

    // Only document 1.
    expect($q->cancelBatchRuns($s['batch'], $s['user']->id, $s['docs'][1]->id))->toBe(1)
        ->and(DB::table('medical_ai_extractions')->where('id', $queued)->value('status'))->toBe('cancelled')
        ->and(DB::table('medical_ai_extractions')->where('id', $waiting)->value('status'))->toBe('waiting');

    expect($q->cancelBatchRuns($s['batch'], $s['user']->id))->toBe(2);
    $w = DB::table('medical_ai_extractions')->where('id', $waiting)->first();
    $r = DB::table('medical_ai_extractions')->where('id', $running)->first();
    expect($w->status)->toBe('cancelled')->and($w->cancel_reason)->toBe('user')->and($w->available_at)->toBeNull()
        ->and($r->status)->toBe('running')->and($r->cancel_requested_at)->not->toBeNull()
        ->and(DB::table('ai_calls')->where('extraction_id', $waiting)->value('status'))->toBe('cancelled')
        ->and(DB::table('audit_logs')->where('event', 'medical_ai_run_finished')->count())->toBe(2);

    // Idempotent: nothing left to stop (the running one is already asked).
    expect($q->cancelBatchRuns($s['batch'], null))->toBe(0);
});

it('expires every batch past its date, open or finished, and leaves younger ones; output has counts only', function () {
    $old = ubExpBatch('-1 hour');
    $young = ubExpBatch('+1 day');
    $finished = ubExpBatch('-2 days');
    DB::table('medical_upload_batches')->where('id', $finished['batch'])->update(['status' => 'finished', 'finished_at' => now()]);

    $this->artisan('medical:expire-upload-batches')->expectsOutput('expired=2 removed=4')->assertExitCode(0);

    expect(DB::table('medical_upload_batches')->pluck('id')->all())->toBe([$young['batch']])
        ->and(DB::table('medical_documents')->where('upload_batch_id', $young['batch'])->count())->toBe(3);
    Storage::disk('medical')->assertMissing($old['docs'][1]->path);
    Storage::disk('medical')->assertExists($young['docs'][1]->path);
    Storage::disk('medical')->assertExists($old['docs'][2]->path);
    $dump = json_encode(DB::table('audit_logs')->get());
    expect($dump)->not->toContain('SECRET-TITLE')->not->toContain($old['docs'][1]->path);
});

it('keeps the files when the purge transaction rolls back', function () {
    $s = ubExpBatch();
    try {
        DB::transaction(function () use ($s) {
            app(UploadBatchJobQuery::class)->purge($s['batch'], null, 'expired');
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(DB::table('medical_upload_batches')->count())->toBe(1);
    Storage::disk('medical')->assertExists($s['docs'][1]->path);
});
