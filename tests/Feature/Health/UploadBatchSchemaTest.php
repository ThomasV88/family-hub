<?php

use App\Models\MedicalAiExtraction;
use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** Runs $fn in a savepoint; returns the SQLSTATE of a refusal or null. */
function ubSchemaRefusal(callable $fn): ?string
{
    try {
        DB::transaction($fn);
    } catch (QueryException $e) {
        return (string) $e->getCode();
    }

    return null;
}

function ubSchemaBatch(string $subjectId, string $status = 'open'): string
{
    $id = (string) Str::uuid();
    DB::table('medical_upload_batches')->insert([
        'id' => $id, 'subject_id' => $subjectId, 'status' => $status, 'expires_at' => now()->addDays(30),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function ubSchemaItem(string $batchId, string $documentId, int $position = 1, array $extra = []): string
{
    $id = (string) Str::uuid();
    DB::table('medical_upload_batch_items')->insert([
        'id' => $id, 'batch_id' => $batchId, 'document_id' => $documentId, 'position' => $position,
        'created_at' => now(), 'updated_at' => now(),
    ] + $extra);

    return $id;
}

it('allows one open batch per person but an open and a finished one together', function () {
    $subject = MedicalSubject::factory()->create();
    ubSchemaBatch($subject->id);

    expect(ubSchemaRefusal(fn () => ubSchemaBatch($subject->id)))->toBe('23505')
        ->and(ubSchemaRefusal(fn () => ubSchemaBatch($subject->id, 'finished')))->toBeNull()
        ->and(ubSchemaRefusal(fn () => ubSchemaBatch(MedicalSubject::factory()->create()->id)))->toBeNull();
});

it('refuses a bad batch status, item state and rescan count', function () {
    $subject = MedicalSubject::factory()->create();
    $doc = MedicalDocument::factory()->forSubject($subject)->create();
    $batch = ubSchemaBatch($subject->id);

    expect(ubSchemaRefusal(fn () => ubSchemaBatch(MedicalSubject::factory()->create()->id, 'weird')))->toBe('23514')
        ->and(ubSchemaRefusal(fn () => ubSchemaItem($batch, $doc->id, 1, ['state' => 'weird'])))->toBe('23514')
        ->and(ubSchemaRefusal(fn () => ubSchemaItem($batch, $doc->id, 1, ['rescan_count' => 6])))->toBe('23514')
        ->and(ubSchemaRefusal(fn () => ubSchemaItem($batch, $doc->id, 1, ['rescan_count' => -1])))->toBe('23514')
        ->and(ubSchemaRefusal(fn () => ubSchemaItem($batch, $doc->id, 1, ['rescan_count' => 5])))->toBeNull();
});

it('defaults adjustments and choices and keeps one document in one item and one position per batch', function () {
    $subject = MedicalSubject::factory()->create();
    $one = MedicalDocument::factory()->forSubject($subject)->create();
    $two = MedicalDocument::factory()->forSubject($subject)->create();
    $batch = ubSchemaBatch($subject->id);
    $item = ubSchemaItem($batch, $one->id);

    $row = DB::table('medical_upload_batch_items')->where('id', $item)->first();
    expect(json_decode($row->adjustments, true))->toEqual(['brightness' => 100, 'contrast' => 100, 'bw' => false])
        ->and(json_decode($row->choices, true))->toBe([])
        ->and($row->state)->toBe('pending')
        ->and($row->rescan_count)->toBe(0)
        ->and(ubSchemaRefusal(fn () => ubSchemaItem($batch, $one->id, 2)))->toBe('23505')
        ->and(ubSchemaRefusal(fn () => ubSchemaItem($batch, $two->id, 1)))->toBe('23505')
        ->and(ubSchemaRefusal(fn () => ubSchemaItem($batch, $two->id, 2)))->toBeNull();
});

it('deletes items and unfinished documents with the batch and batches with the person', function () {
    $subject = MedicalSubject::factory()->create();
    $pending = MedicalDocument::factory()->forSubject($subject)->create();
    $ordinary = MedicalDocument::factory()->forSubject($subject)->create();
    $batch = ubSchemaBatch($subject->id);
    DB::table('medical_documents')->where('id', $pending->id)->update(['upload_batch_id' => $batch]);
    ubSchemaItem($batch, $pending->id);
    $extraction = MedicalAiExtraction::factory()->forDocument($pending)->create();

    DB::table('medical_upload_batches')->where('id', $batch)->delete();

    expect(DB::table('medical_upload_batch_items')->count())->toBe(0)
        ->and(DB::table('medical_documents')->where('id', $pending->id)->exists())->toBeFalse()
        ->and(DB::table('medical_ai_extractions')->where('id', $extraction->id)->exists())->toBeFalse()
        ->and(DB::table('medical_documents')->where('id', $ordinary->id)->exists())->toBeTrue();

    $again = ubSchemaBatch($subject->id);
    DB::table('medical_subjects')->where('id', $subject->id)->delete();
    expect(DB::table('medical_upload_batches')->where('id', $again)->exists())->toBeFalse();
});

it('adds the extraction columns with defaults for existing rows', function () {
    $doc = MedicalDocument::factory()->forSubject(MedicalSubject::factory()->create())->create();
    $extraction = MedicalAiExtraction::factory()->forDocument($doc)->create();

    $row = DB::table('medical_ai_extractions')->where('id', $extraction->id)->first();
    expect($row->fresh_read)->toBeFalse()->and($row->upload_batch_id)->toBeNull()->and($row->adjustments)->toBeNull();

    $batchId = (string) Str::uuid();
    DB::table('medical_ai_extractions')->where('id', $extraction->id)->update(['upload_batch_id' => $batchId, 'fresh_read' => true, 'adjustments' => json_encode(['bw' => true])]);
    expect(DB::table('medical_ai_extractions')->where('id', $extraction->id)->value('fresh_read'))->toBeTrue();
});

it('rolls its tables and columns back', function () {
    $migration = require database_path('migrations/2026_12_04_100000_create_medical_upload_batches.php');
    $migration->down();

    expect(Schema::hasTable('medical_upload_batches'))->toBeFalse()
        ->and(Schema::hasTable('medical_upload_batch_items'))->toBeFalse()
        ->and(Schema::hasColumn('medical_documents', 'upload_batch_id'))->toBeFalse()
        ->and(Schema::hasColumn('medical_ai_extractions', 'fresh_read'))->toBeFalse()
        ->and(Schema::hasColumn('medical_ai_extractions', 'adjustments'))->toBeFalse()
        ->and(Schema::hasColumn('medical_ai_extractions', 'upload_batch_id'))->toBeFalse();

    $migration->up();
    expect(Schema::hasTable('medical_upload_batch_items'))->toBeTrue();
});
