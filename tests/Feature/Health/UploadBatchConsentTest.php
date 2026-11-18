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

/** An open batch of three documents: one queued, one waiting, one running, each with its item in state sent; plus a fourth, ready and done-looking. */
function ubConsBatch(): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user, AccessLevel::Edit);
    $person = MedicalFixtures::subject(['ai_consent' => true], [[$user, AccessLevel::Edit]]);
    DB::table('ai_notice_acks')->insert(['user_id' => $user->id, 'providers' => 'codex']);
    $batch = (string) Str::uuid();
    DB::table('medical_upload_batches')->insert([
        'id' => $batch, 'subject_id' => $person->id, 'created_by' => $user->id, 'acted_by' => $user->id, 'status' => 'open',
        'expires_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $out = ['user' => $user, 'person' => $person, 'batch' => $batch, 'docs' => [], 'items' => [], 'runs' => []];
    foreach (['queued', 'waiting', 'running', 'ready'] as $i => $state) {
        $doc = MedicalDocument::factory()->image()->forSubject($person)->create(['title' => 'SECRET-TITLE-'.$state, 'upload_batch_id' => $batch]);
        Storage::disk('medical')->put($doc->path, 'x');
        $ext = MedicalAiExtraction::factory()->forDocument($doc)->{$state}()->create(['started_by' => $user->id, 'upload_batch_id' => $batch]);
        DB::table('ai_calls')->insert([
            'extraction_id' => $ext->id, 'subject_id' => $person->id, 'document_id' => $doc->id, 'started_by' => $user->id, 'agent' => 'codex',
            'attempt' => 1, 'status' => $state === 'running' ? 'running' : ($state === 'ready' ? 'succeeded' : 'queued'), 'queued_at' => now(),
        ]);
        $item = (string) Str::uuid();
        DB::table('medical_upload_batch_items')->insert([
            'id' => $item, 'batch_id' => $batch, 'document_id' => $doc->id, 'position' => $i + 1, 'state' => 'sent', 'extraction_id' => $ext->id,
            'choices' => json_encode(['kind' => 'visit']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $out['docs'][$state] = $doc;
        $out['items'][$state] = $item;
        $out['runs'][$state] = $ext->id;
    }

    return $out;
}

it('withdrawing consent stops the batch runs and sends their documents back to pending, keeping the batch and choices', function () {
    $s = ubConsBatch();

    $this->actingAs($s['user'])->patch("/health/people/{$s['person']->id}/ai-consent", ['value' => false])->assertRedirect();

    $queued = DB::table('medical_ai_extractions')->where('id', $s['runs']['queued'])->first();
    $waiting = DB::table('medical_ai_extractions')->where('id', $s['runs']['waiting'])->first();
    $running = DB::table('medical_ai_extractions')->where('id', $s['runs']['running'])->first();
    expect($queued->status)->toBe('cancelled')->and($queued->cancel_reason)->toBe('consent')
        ->and($waiting->status)->toBe('cancelled')->and($waiting->cancel_reason)->toBe('consent')->and($waiting->available_at)->toBeNull()
        ->and($running->status)->toBe('running')->and($running->cancel_requested_at)->not->toBeNull()
        ->and(DB::table('medical_ai_extractions')->where('id', $s['runs']['ready'])->value('status'))->toBe('ready')
        ->and(DB::table('ai_calls')->where('extraction_id', $s['runs']['queued'])->value('status'))->toBe('cancelled');

    foreach (['queued', 'waiting', 'running'] as $state) {
        $item = DB::table('medical_upload_batch_items')->where('id', $s['items'][$state])->first();
        expect($item->state)->toBe('pending')->and($item->extraction_id)->toBeNull()
            ->and(json_decode($item->choices, true))->toBe(['kind' => 'visit']);
    }
    // The finished read is untouched.
    expect(DB::table('medical_upload_batch_items')->where('id', $s['items']['ready'])->value('state'))->toBe('sent')
        ->and(DB::table('medical_upload_batches')->where('id', $s['batch'])->value('status'))->toBe('open')
        ->and(DB::table('medical_documents')->where('upload_batch_id', $s['batch'])->count())->toBe(4);
    foreach ($s['docs'] as $doc) {
        Storage::disk('medical')->assertExists($doc->path);
    }
    $audit = json_encode(DB::table('audit_logs')->get());
    expect($audit)->not->toContain('SECRET-TITLE')->not->toContain($s['docs']['queued']->path);
});

it('runs the Phase 13 text purge as before in the same change', function () {
    $s = ubConsBatch();
    DB::table('medical_document_texts')->insert([
        'document_id' => $s['docs']['ready']->id, 'subject_id' => $s['person']->id, 'extraction_id' => $s['runs']['ready'],
        'started_by' => $s['user']->id, 'text_version' => 1, 'pages_read' => 1, 'pages_total' => 1, 'complete' => true,
        'body' => 'recognised words', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($s['user'])->patch("/health/people/{$s['person']->id}/ai-consent", ['value' => false])->assertRedirect();

    expect(DB::table('medical_document_texts')->where('subject_id', $s['person']->id)->count())->toBe(0);
});

it('lets the batch go on when consent is turned on again, starting the first pending document', function () {
    $s = ubConsBatch();
    DB::table('medical_ai_extractions')->where('id', $s['runs']['running'])->update(['status' => 'failed', 'failure_code' => 'unknown', 'finished_at' => now(), 'started_at' => now()]);
    $this->actingAs($s['user'])->patch("/health/people/{$s['person']->id}/ai-consent", ['value' => false])->assertRedirect();
    $q = app(UploadBatchJobQuery::class);

    expect($q->advance($s['batch'], CarbonImmutable::now()))->toBeNull();

    $this->actingAs($s['user'])->patch("/health/people/{$s['person']->id}/ai-consent", ['value' => true])->assertRedirect();
    $id = $q->advance($s['batch'], CarbonImmutable::now());

    expect($id)->not->toBeNull()
        ->and(DB::table('medical_ai_extractions')->where('id', $id)->value('document_id'))->toBe($s['docs']['queued']->id);
});

it('leaves another person batch alone and does nothing when consent is switched on', function () {
    $s = ubConsBatch();
    $other = ubConsBatch();

    $this->actingAs($s['user'])->patch("/health/people/{$s['person']->id}/ai-consent", ['value' => false])->assertRedirect();

    expect(DB::table('medical_ai_extractions')->where('id', $other['runs']['queued'])->value('status'))->toBe('queued')
        ->and(DB::table('medical_upload_batch_items')->where('id', $other['items']['queued'])->value('state'))->toBe('sent');

    DB::table('medical_subjects')->where('id', $s['person']->id)->update(['ai_consent' => false]);
    $this->actingAs($s['user'])->patch("/health/people/{$s['person']->id}/ai-consent", ['value' => true])->assertRedirect();
    expect(DB::table('medical_upload_batch_items')->where('id', $s['items']['queued'])->value('state'))->toBe('pending');
});
