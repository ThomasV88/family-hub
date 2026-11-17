<?php

use App\Access\AccessLevel;
use App\Medical\Documents\Text\SaveOutcome;
use App\Medical\Queries\DocumentTextStore;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Viewer;
use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DocumentTextFixtures;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

/** @return array{0: User, 1: MedicalSubject, 2: MedicalDocument} */
function docTextStoreScene(AccessLevel $level = AccessLevel::Edit, bool $consent = true): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user, $level);
    $subject = MedicalFixtures::subject(['ai_consent' => $consent], [[$user, $level]]);
    $doc = MedicalDocument::factory()->forSubject($subject)->create();

    return [$user, $subject, $doc];
}

function docTextStoreSave(MedicalDocument $doc, array $pages = ['Žltačka'], int $total = 1, bool $complete = true): SaveOutcome
{
    return app(DocumentTextStore::class)->save((string) $doc->id, null, 1, 'codex', 'm', 'sk', $pages, $total, $complete);
}

it('saves the normalised text with consent on', function () {
    [, , $doc] = docTextStoreScene();

    expect(docTextStoreSave($doc, ["z\u{030C}lta\u{010D}ka  \r\nA", 'B'], 2))->toBe(SaveOutcome::Saved);

    $row = DocumentTextFixtures::row($doc);
    expect($row->body)->toBe("žltačka\nA\x0cB")
        ->and($row->pages_read)->toBe(2)
        ->and($row->pages_total)->toBe(2)
        ->and((bool) $row->complete)->toBeTrue()
        ->and($row->language)->toBe('sk')
        ->and($row->text_version)->toBe(1)
        ->and(DB::table('medical_document_texts')->count())->toBe(1);
});

it('replaces the row on a second save', function () {
    [, , $doc] = docTextStoreScene();
    docTextStoreSave($doc, ['first']);
    docTextStoreSave($doc, ['second']);

    expect(DB::table('medical_document_texts')->count())->toBe(1)
        ->and(DocumentTextFixtures::row($doc)->body)->toBe('second');
});

it('whitelists the language and records a partial read honestly', function () {
    [, , $doc] = docTextStoreScene();
    app(DocumentTextStore::class)->save((string) $doc->id, null, 1, null, null, 'de', ['a'], 3, true);

    $row = DocumentTextFixtures::row($doc);
    expect($row->language)->toBeNull()->and($row->pages_read)->toBe(1)->and((bool) $row->complete)->toBeFalse();
});

it('writes nothing with consent off', function () {
    [, , $doc] = docTextStoreScene(consent: false);

    expect(docTextStoreSave($doc))->toBe(SaveOutcome::ConsentOff)
        ->and(DB::table('medical_document_texts')->count())->toBe(0);
});

it('writes nothing for an archived document or an archived person', function () {
    [, $subject, $doc] = docTextStoreScene();
    DB::table('medical_documents')->where('id', $doc->id)->update(['archived_at' => now()]);
    expect(docTextStoreSave($doc))->toBe(SaveOutcome::Archived);

    [, $subject2, $doc2] = docTextStoreScene();
    DB::table('medical_subjects')->where('id', $subject2->id)->update(['archived_at' => now()]);
    expect(docTextStoreSave($doc2))->toBe(SaveOutcome::Archived)
        ->and(DB::table('medical_document_texts')->count())->toBe(0);
});

it('answers Missing for an unknown or malformed document', function () {
    [, , $doc] = docTextStoreScene();
    $store = app(DocumentTextStore::class);

    expect($store->save((string) Str::uuid(), null, 1, null, null, null, ['a'], 1, true))->toBe(SaveOutcome::Missing)
        ->and($store->save('nope', null, 1, null, null, null, ['a'], 1, true))->toBe(SaveOutcome::Missing);
});

it('propagates a database error without writing or logging the text', function () {
    [, , $doc] = docTextStoreScene();
    $logs = glob(storage_path('logs/*.log')) ?: [];
    $before = array_sum(array_map('filesize', $logs));

    expect(fn () => app(DocumentTextStore::class)->save((string) $doc->id, null, 1, null, null, 'sk', [DocumentTextFixtures::CANARY], 60, false))
        ->toThrow(QueryException::class);

    expect(DB::table('medical_document_texts')->count())->toBe(0);
    foreach (glob(storage_path('logs/*.log')) ?: [] as $file) {
        expect(file_get_contents($file))->not->toContain(DocumentTextFixtures::CANARY);
    }
    expect(array_sum(array_map('filesize', glob(storage_path('logs/*.log')) ?: [])))->toBeGreaterThanOrEqual($before);
});

it('drops pages beyond the total limit and reports what it kept', function () {
    config(['ai.ocr.max_total_chars' => 25]);
    [, , $doc] = docTextStoreScene();
    docTextStoreSave($doc, [str_repeat('a', 10), str_repeat('b', 10), str_repeat('c', 10)], 3);

    $row = DocumentTextFixtures::row($doc);
    expect($row->pages_read)->toBe(2)->and((bool) $row->complete)->toBeFalse();
});

it('lets an Edit member remove the text', function () {
    [$user, $subject, $doc] = docTextStoreScene();
    docTextStoreSave($doc);

    expect(app(DocumentTextStore::class)->remove(Viewer::for($user), (string) $subject->id, (string) $doc->id))->toBeTrue()
        ->and(DB::table('medical_document_texts')->count())->toBe(0);
});

it('does not let a View member, a stranger or a hidden person remove the text', function () {
    [$user, $subject, $doc] = docTextStoreScene(AccessLevel::View);
    docTextStoreSave($doc);
    $store = app(DocumentTextStore::class);
    expect($store->remove(Viewer::for($user), (string) $subject->id, (string) $doc->id))->toBeFalse();

    // Edit module level but no membership of this person.
    $stranger = User::factory()->create();
    MedicalFixtures::grantHealth($stranger, AccessLevel::Edit);
    expect($store->remove(Viewer::for($stranger), (string) $subject->id, (string) $doc->id))->toBeFalse()
        ->and($store->remove(Viewer::for($stranger), 'bad', (string) $doc->id))->toBeFalse()
        ->and(DB::table('medical_document_texts')->count())->toBe(1);
});

it('does not remove the text of an archived document, and not through another persons id', function () {
    [$user, $subject, $doc] = docTextStoreScene();
    docTextStoreSave($doc);
    $other = MedicalFixtures::subject([], [[$user, AccessLevel::Edit]]);
    $store = app(DocumentTextStore::class);

    expect($store->remove(Viewer::for($user), (string) $other->id, (string) $doc->id))->toBeFalse();

    DB::table('medical_documents')->where('id', $doc->id)->update(['archived_at' => now()]);
    expect($store->remove(Viewer::for($user), (string) $subject->id, (string) $doc->id))->toBeFalse()
        ->and(DB::table('medical_document_texts')->count())->toBe(1);
});

it('purges the text of one person when AI consent is withdrawn, and only that person', function () {
    [$user, $subject, $doc] = docTextStoreScene();
    $other = MedicalFixtures::subject(['ai_consent' => true], [[$user, AccessLevel::Edit]]);
    $otherDoc = MedicalDocument::factory()->forSubject($other)->create();
    $second = MedicalDocument::factory()->forSubject($subject)->create();
    docTextStoreSave($doc);
    docTextStoreSave($second);
    docTextStoreSave($otherDoc);

    $queries = app(SubjectQuery::class);
    expect($queries->setConsent(Viewer::for($user), $subject, true))->toBeTrue()
        ->and(DB::table('medical_document_texts')->count())->toBe(3);

    expect($queries->setConsent(Viewer::for($user), $subject, false))->toBeTrue();
    expect(DB::table('medical_document_texts')->where('subject_id', $subject->id)->count())->toBe(0)
        ->and(DB::table('medical_document_texts')->where('subject_id', $other->id)->count())->toBe(1);
});

it('does not purge when consent was already off and cannot save afterwards', function () {
    [$user, $subject, $doc] = docTextStoreScene();
    docTextStoreSave($doc);
    // Consent off by a direct write (no purge), then the already-off call is a no-op.
    DB::table('medical_subjects')->where('id', $subject->id)->update(['ai_consent' => false]);

    expect(app(SubjectQuery::class)->setConsent(Viewer::for($user), $subject->fresh(), false))->toBeTrue()
        ->and(DB::table('medical_document_texts')->count())->toBe(1)
        ->and(docTextStoreSave($doc))->toBe(SaveOutcome::ConsentOff);
});

it('does not purge when a View member withdraws consent', function () {
    [$user, $subject, $doc] = docTextStoreScene(AccessLevel::View);
    docTextStoreSave($doc);

    expect(app(SubjectQuery::class)->setConsent(Viewer::for($user), $subject, false))->toBeFalse()
        ->and(DB::table('medical_document_texts')->count())->toBe(1);
});

it('loads the fixture helper and its sample text through the real store', function () {
    [, , $doc] = docTextStoreScene(consent: false);

    expect(DocumentTextFixtures::save($doc, DocumentTextFixtures::samplePages()))->toBe(SaveOutcome::Saved)
        ->and(DocumentTextFixtures::row($doc)->body)->toContain(DocumentTextFixtures::CANARY)->toContain('Penicilín');
});
