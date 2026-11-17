<?php

use App\Models\MedicalDocument;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

/** @param  array<string, mixed>  $over */
function docTextSchemaRow(MedicalDocument $doc, string $body = 'text', array $over = []): array
{
    return array_merge([
        'document_id' => $doc->id,
        'subject_id' => $doc->subject_id,
        'started_by' => 1,
        'text_version' => 1,
        'language' => 'sk',
        'pages_read' => 1,
        'pages_total' => 1,
        'complete' => true,
        'body' => $body,
        'created_at' => now(),
        'updated_at' => now(),
    ], $over);
}

function docTextSchemaDoc(): MedicalDocument
{
    return MedicalDocument::factory()->forSubject(MedicalFixtures::subject())->create();
}

function docTextSchemaMatches(string $query, string $document): bool
{
    return (bool) DB::selectOne(
        "select exists (select 1 from medical_document_texts where document_id = ? and search @@ to_tsquery('simple', ?)) as m",
        [$document, $query],
    )->m;
}

it('folds accents with an immutable wrapper', function () {
    expect(DB::selectOne("select public.fh_unaccent('Ťažká') as v")->v)->toBe('Tazka')
        ->and(DB::selectOne("select lower(public.fh_unaccent('ĽADVINY')) as v")->v)->toBe('ladviny')
        ->and(DB::selectOne("select public.fh_unaccent('Łukasz') as v")->v)->toBe('Lukasz')
        ->and(DB::selectOne("select provolatile as v from pg_proc where proname = 'fh_unaccent'")->v)->toBe('i');
});

it('computes the search column and matches without accents', function () {
    $doc = docTextSchemaDoc();
    DB::table('medical_document_texts')->insert(docTextSchemaRow($doc, "Łukasz ťažká reakcia\x0cPenicilín 500 mg"));

    expect(docTextSchemaMatches('lukasz:*', $doc->id))->toBeTrue()
        ->and(docTextSchemaMatches('tazka:* & penicil:*', $doc->id))->toBeTrue()
        ->and(docTextSchemaMatches('paracetamolu:*', $doc->id))->toBeFalse();

    DB::table('medical_document_texts')->where('document_id', $doc->id)->update(['body' => 'paracetamol']);
    expect(docTextSchemaMatches('paracetamolu:*', $doc->id))->toBeFalse()
        ->and(docTextSchemaMatches('paracet:*', $doc->id))->toBeTrue();
});

it('refuses a text row that belongs to another persons document', function () {
    $doc = docTextSchemaDoc();
    $other = MedicalFixtures::subject();

    expect(fn () => DB::table('medical_document_texts')->insert(docTextSchemaRow($doc, 'x', ['subject_id' => $other->id])))
        ->toThrow(QueryException::class);
});

it('deletes the text with its document and with its person', function () {
    $doc = docTextSchemaDoc();
    DB::table('medical_document_texts')->insert(docTextSchemaRow($doc));
    DB::table('medical_documents')->where('id', $doc->id)->delete();
    expect(DB::table('medical_document_texts')->count())->toBe(0);

    $second = docTextSchemaDoc();
    DB::table('medical_document_texts')->insert(docTextSchemaRow($second));
    DB::table('medical_subjects')->where('id', $second->subject_id)->delete();
    expect(DB::table('medical_document_texts')->count())->toBe(0);
});

it('enforces the CHECK constraints', function () {
    $cases = [
        'body too long' => ['body' => str_repeat('a', 200001)],
        'pages read over total' => ['pages_read' => 3, 'pages_total' => 2],
        'complete but pages missing' => ['pages_read' => 1, 'pages_total' => 2, 'complete' => true],
        'bad language' => ['language' => 'de'],
        'zero pages total' => ['pages_total' => 0, 'pages_read' => 0],
        'version zero' => ['text_version' => 0],
    ];
    foreach ($cases as $name => $over) {
        $doc = docTextSchemaDoc();
        $failed = false;
        try {
            DB::transaction(fn () => DB::table('medical_document_texts')->insert(docTextSchemaRow($doc, 'x', $over)));
        } catch (QueryException) {
            $failed = true;
        }
        expect($failed)->toBeTrue($name);
    }

    $ok = docTextSchemaDoc();
    DB::table('medical_document_texts')->insert(docTextSchemaRow($ok, str_repeat('a', 200000), ['pages_read' => 1, 'pages_total' => 2, 'complete' => false, 'language' => null]));
    expect(DB::table('medical_document_texts')->count())->toBe(1);
});

it('lets the runtime role use the table and the function without an extra grant', function () {
    // fh_owner cannot SET ROLE fh_app, so the rights are read from the catalog (default privileges of fh_owner).
    foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $right) {
        expect(DB::selectOne('select has_table_privilege(?, ?, ?) as ok', ['fh_app', 'medical_document_texts', $right])->ok)->toBeTrue($right);
    }
    expect(DB::selectOne('select has_function_privilege(?, ?, ?) as ok', ['fh_app', 'public.fh_unaccent(text)', 'EXECUTE'])->ok)->toBeTrue()
        ->and(DB::selectOne('select has_table_privilege(?, ?, ?) as ok', ['fh_app', 'medical_document_texts', 'TRUNCATE'])->ok)->toBeFalse();
});

it('uses the GIN index for a search', function () {
    DB::transaction(function () {
        DB::statement('SET LOCAL enable_seqscan = off');
        $plan = collect(DB::select("explain select document_id from medical_document_texts where search @@ to_tsquery('simple', 'lukasz:*')"))
            ->map(fn ($r) => (string) array_values((array) $r)[0])->implode("\n");
        expect($plan)->toContain('medical_document_texts_search_index');
    });
});

it('refuses to roll back while text exists and drops table and function when empty', function () {
    $migration = require base_path('database/migrations/2026_12_02_100000_create_medical_document_texts_table.php');
    $doc = docTextSchemaDoc();
    DB::table('medical_document_texts')->insert(docTextSchemaRow($doc));

    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    expect(Schema::hasTable('medical_document_texts'))->toBeTrue();

    DB::table('medical_document_texts')->delete();
    $migration->down();
    expect(Schema::hasTable('medical_document_texts'))->toBeFalse()
        ->and(DB::selectOne("select count(*) as c from pg_proc where proname = 'fh_unaccent'")->c)->toBe(0);

    // Put it back for the remaining tests (RefreshDatabase rolls the transaction back anyway).
    $migration->up();
    expect(Schema::hasTable('medical_document_texts'))->toBeTrue();
});

it('has the ocr config defaults', function () {
    expect(config('ai.ocr.enabled'))->toBeFalse()
        ->and(config('ai.ocr.text_reliability'))->toBe('reliable')
        ->and(config('ai.ocr.text_version'))->toBe(1)
        ->and(Str::of((string) config('ai.ocr.max_total_chars'))->toString())->toBe('200000');
});

it('accepts the real page count of a long document and refuses zero', function () {
    $doc = docTextSchemaDoc();
    DB::table('medical_document_texts')->insert(docTextSchemaRow($doc, 'x', ['pages_read' => 20, 'pages_total' => 120, 'complete' => false]));
    expect((int) DB::table('medical_document_texts')->where('document_id', $doc->id)->value('pages_total'))->toBe(120);

    $failed = false;
    try {
        DB::transaction(fn () => DB::table('medical_document_texts')->insert(docTextSchemaRow(docTextSchemaDoc(), 'x', ['pages_read' => 0, 'pages_total' => 0])));
    } catch (QueryException) {
        $failed = true;
    }
    expect($failed)->toBeTrue();
});
