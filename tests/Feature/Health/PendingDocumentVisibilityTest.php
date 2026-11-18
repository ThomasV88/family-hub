<?php

use App\Access\AccessLevel;
use App\Medical\Queries\AiBulkEligibility;
use App\Medical\Queries\DocumentQuery;
use App\Medical\Queries\ExportQuery;
use App\Medical\Viewer;
use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DocumentTextFixtures;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

/** @return array{0: User, 1: MedicalSubject, 2: MedicalDocument, 3: MedicalDocument, 4: string} editor, person, ordinary doc, pending doc, batch id */
function ubVisScene(): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user, AccessLevel::Edit);
    $person = MedicalFixtures::subject(['name' => 'Batch Person', 'ai_consent' => true], [[$user, AccessLevel::Edit]]);
    $ordinary = MedicalDocument::factory()->image()->forSubject($person)->create(['title' => 'Ordinary quokka']);
    $pending = MedicalDocument::factory()->image()->forSubject($person)->create(['title' => 'Pending quokka']);
    DocumentTextFixtures::save($pending, ['pending quokka text']);

    $batch = (string) Str::uuid();
    DB::table('medical_upload_batches')->insert(['id' => $batch, 'subject_id' => $person->id, 'status' => 'open', 'expires_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('medical_documents')->where('id', $pending->id)->update(['upload_batch_id' => $batch]);

    return [$user, $person, $ordinary, $pending, $batch];
}

it('hides a pending document from the query lists but find and the stored count still see it', function () {
    [$user, $person, $ordinary, $pending] = ubVisScene();
    $viewer = Viewer::for($user);
    $query = app(DocumentQuery::class);

    expect($query->forSubject($viewer, $person)->pluck('id')->all())->toBe([(string) $ordinary->id])
        ->and($query->countForSubject($viewer, $person))->toBe(1)
        ->and($query->countStoredForSubject($viewer, $person))->toBe(2)
        ->and($query->find($viewer, $person, (string) $pending->id, AccessLevel::Edit)?->id)->toBe((string) $pending->id);
});

it('keeps a pending document out of the person page props', function () {
    [$user, $person, $ordinary, $pending] = ubVisScene();

    $response = $this->actingAs($user)->get("/health/people/{$person->id}")->assertOk();
    $props = $response->viewData('page')['props'];

    expect(array_column($props['documents'], 'id'))->toBe([(string) $ordinary->id])
        ->and($props['unattachedDocumentIds'])->toBe([(string) $ordinary->id])
        ->and(json_encode($props))->not->toContain((string) $pending->id);
});

it('never finds a pending document by title or by recognised text', function () {
    [$user, , $ordinary, $pending] = ubVisScene();

    foreach (['quokka', 'pending'] as $q) {
        $ids = array_column($this->actingAs($user)->postJson('/health/search', ['q' => $q])->assertOk()->json('items'), 'documentId');
        expect($ids)->not->toContain((string) $pending->id);
    }
    $ids = array_column($this->actingAs($user)->postJson('/health/search', ['q' => 'quokka'])->json('items'), 'documentId');
    expect($ids)->toBe([(string) $ordinary->id]);
});

it('leaves a pending document out of the export', function () {
    [$user, $person, $ordinary, $pending] = ubVisScene();

    $ids = app(ExportQuery::class)->documents(Viewer::for($user), $person, true)->pluck('id')->all();

    expect($ids)->toBe([(string) $ordinary->id])->not->toContain((string) $pending->id);
});

it('never picks a pending document for the bulk re-read in any scope', function () {
    [, , $ordinary, $pending] = ubVisScene();
    $bulk = app(AiBulkEligibility::class);

    foreach (AiBulkEligibility::SCOPES as $scope) {
        $candidate = $bulk->nextCandidate($scope, (string) Str::uuid());
        expect($candidate === null || $candidate['document_id'] !== (string) $pending->id)->toBeTrue($scope);
    }
    expect($bulk->counts('all')['eligible'])->toBe(1)
        ->and($bulk->nextCandidate('all', (string) Str::uuid())['document_id'])->toBe((string) $ordinary->id);
});

it('shows the document everywhere again once the batch marker is cleared', function () {
    [$user, $person, , $pending, $batch] = ubVisScene();
    DB::table('medical_documents')->where('id', $pending->id)->update(['upload_batch_id' => null]);
    $viewer = Viewer::for($user);

    expect(app(DocumentQuery::class)->forSubject($viewer, $person))->toHaveCount(2)
        ->and(app(DocumentQuery::class)->countForSubject($viewer, $person))->toBe(2)
        ->and(app(ExportQuery::class)->documents($viewer, $person, true)->count())->toBe(2)
        ->and(app(AiBulkEligibility::class)->counts('all')['eligible'])->toBe(2);

    $ids = array_column($this->actingAs($user)->postJson('/health/search', ['q' => 'pending'])->assertOk()->json('items'), 'documentId');
    expect($ids)->toContain((string) $pending->id);
    $page = $this->actingAs($user)->get("/health/people/{$person->id}")->viewData('page')['props'];
    expect($page['documents'])->toHaveCount(2);
});
