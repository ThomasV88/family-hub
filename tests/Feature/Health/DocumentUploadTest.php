<?php

use App\Access\AccessLevel;
use App\Medical\Documents\DocumentRejected;
use App\Medical\Documents\MedicalDocumentStore;
use App\Medical\Queries\DocumentQuery;
use App\Medical\Viewer;
use App\Models\AuditEntry;
use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use App\Models\MedicalVisit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ImageFixtures;
use Tests\Support\MedicalFileFixtures;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    Storage::fake('medical');
});

/** @return array{0: User, 1: MedicalSubject} */
function docEditor(): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user);

    return [$user, MedicalFixtures::subject([], [[$user, AccessLevel::Edit]])];
}

function docUpload(User $user, string $personId, string $name, string $bytes, array $extra = [])
{
    return test()->actingAs($user)->post(
        '/health/people/'.$personId.'/documents',
        ['file' => UploadedFile::fake()->createWithContent($name, $bytes)] + $extra,
        ['Accept' => 'application/json'],
    );
}

it('uploads a JPEG, a PDF and a HEIC file with the right kind and thumbnail', function () {
    [$user, $person] = docEditor();

    $jpeg = docUpload($user, $person->id, 'Blood test.jpg', ImageFixtures::jpegWithExif());
    $pdf = docUpload($user, $person->id, 'report.pdf', MedicalFileFixtures::pdf());
    $heic = docUpload($user, $person->id, 'IMG_1.heic', MedicalFileFixtures::heic());

    $jpeg->assertCreated()->assertJsonPath('document.kind', 'image')->assertJsonPath('document.title', 'Blood test');
    $pdf->assertCreated()->assertJsonPath('document.kind', 'pdf');
    $heic->assertCreated()->assertJsonPath('document.kind', 'heic');

    $rows = MedicalDocument::query()->get()->keyBy('kind');
    expect($rows['image']->thumb_path)->not->toBeNull()
        ->and($rows['pdf']->thumb_path)->toBeNull()
        ->and(Storage::disk('medical')->allFiles())->toHaveCount(4)
        ->and(Storage::disk('medical')->exists($rows['image']->path))->toBeTrue()
        ->and($rows['pdf']->uploaded_by)->toBe($user->id)
        ->and(array_keys($rows['pdf']->toArray()))->not->toContain('path');
});

it('treats a mislabeled file by its bytes', function () {
    [$user, $person] = docEditor();

    docUpload($user, $person->id, 'scan.heic', ImageFixtures::jpeg())->assertCreated()->assertJsonPath('document.kind', 'image');
    docUpload($user, $person->id, 'scan.pdf', ImageFixtures::svg())->assertStatus(422);
});

it('attaches a document to a visit of the same person', function () {
    [$user, $person] = docEditor();
    $visit = MedicalVisit::factory()->forSubject($person)->create();

    docUpload($user, $person->id, 'a.pdf', MedicalFileFixtures::pdf(), ['visit_id' => $visit->id])->assertCreated();

    expect(MedicalDocument::query()->first()->visit_id)->toBe($visit->id);
});

it('answers the same visit_id error for a hidden person, an unknown and a malformed visit and leaves no file', function () {
    [$user, $person] = docEditor();
    $hiddenSubject = MedicalSubject::factory()->create();
    $hidden = MedicalVisit::factory()->forSubject($hiddenSubject)->create();
    $own = MedicalVisit::factory()->forSubject(MedicalFixtures::subject([], [[$user, AccessLevel::Edit]]))->create();

    $bodies = [];
    foreach ([$hidden->id, (string) Str::uuid(), 'abc', $own->id] as $visitId) {
        $r = docUpload($user, $person->id, 'a.pdf', MedicalFileFixtures::pdf(), ['visit_id' => $visitId]);
        $r->assertStatus(422)->assertJsonValidationErrors('visit_id');
        $bodies[] = $r->getContent();
    }

    expect(array_unique($bodies))->toHaveCount(1)
        ->and(Storage::disk('medical')->allFiles())->toBe([])
        ->and(MedicalDocument::query()->count())->toBe(0);
});

it('refuses unsupported and oversize files with the mapped error and stores nothing', function (string $name, string $bytes, string $key) {
    [$user, $person] = docEditor();

    docUpload($user, $person->id, $name, $bytes)->assertStatus(422)->assertJsonPath('errors.file.0', $key);

    expect(Storage::disk('medical')->allFiles())->toBe([])->and(MedicalDocument::query()->count())->toBe(0);
})->with([
    'svg' => ['a.svg', fn () => ImageFixtures::svg(), 'health.upload.err_type'],
    'gif' => ['a.gif', fn () => ImageFixtures::gif(), 'health.upload.err_type'],
    'junk pdf' => ['a.pdf', fn () => MedicalFileFixtures::pdfWithLeadingJunk(), 'health.upload.err_type'],
    'pixel bomb' => ['a.png', fn () => ImageFixtures::pngBomb(20000, 20000), 'health.upload.err_read'],
    'oversize' => ['a.pdf', fn () => MedicalFileFixtures::pdf().str_repeat('0', 10 * 1024 * 1024), 'health.upload.err_size'],
]);

it('refuses the 201st document of one person', function () {
    [$user, $person] = docEditor();
    config(['medical.max_documents_per_subject' => 2]);
    MedicalDocument::factory()->count(2)->forSubject($person)->create();

    docUpload($user, $person->id, 'a.pdf', MedicalFileFixtures::pdf())->assertStatus(422)->assertJsonPath('errors.file.0', 'health.upload.err_limit');

    expect(Storage::disk('medical')->allFiles())->toBe([]);
});

it('gives view members, hidden, unknown and malformed people the identical 403 without touching files', function () {
    config(['app.debug' => false]); // debug traces differ per request
    [$editor, $person] = docEditor();
    $viewer = User::factory()->create();
    MedicalFixtures::grantHealth($viewer);
    $viewOnly = MedicalFixtures::subject([], [[$viewer, AccessLevel::View]]);
    $hidden = MedicalSubject::factory()->create();

    $codes = [
        docUpload($viewer, $viewOnly->id, 'a.pdf', MedicalFileFixtures::pdf()),
        docUpload($editor, $hidden->id, 'a.pdf', MedicalFileFixtures::pdf()),
        docUpload($editor, (string) Str::uuid(), 'a.pdf', MedicalFileFixtures::pdf()),
        docUpload($editor, 'abc', 'a.pdf', MedicalFileFixtures::pdf()),
        docUpload($editor, $hidden->id, 'a.pdf', 'not a pdf at all'),
    ];

    expect(collect($codes)->map->getStatusCode()->unique()->all())->toBe([403])
        ->and(collect($codes)->map->getContent()->unique())->toHaveCount(1)
        ->and(Storage::disk('medical')->allFiles())->toBe([]);
});

it('redirects an HTML post without a file back with a validation error', function () {
    [$user, $person] = docEditor();

    $this->actingAs($user)->post('/health/people/'.$person->id.'/documents')->assertRedirect()->assertSessionHasErrors('file');
});

it('keeps the client file name out of audit rows, titles and disk names', function () {
    [$user, $person] = docEditor();

    docUpload($user, $person->id, 'ZxCanaryMed-scan.pdf', MedicalFileFixtures::pdf(), ['title' => 'Own title'])->assertCreated();

    $audit = AuditEntry::query()->get();
    expect($audit)->toHaveCount(1)
        ->and(json_encode($audit->toArray()))->not->toContain('ZxCanaryMed')
        ->and(Storage::disk('medical')->allFiles()[0])->not->toContain('Canary')
        ->and(MedicalDocument::query()->first()->title)->toBe('Own title');
});

it('enforces the composite visit foreign key and clears only visit_id when a visit is deleted', function () {
    [, $person] = docEditor();
    $other = MedicalSubject::factory()->create();
    $visit = MedicalVisit::factory()->forSubject($person)->create();
    $foreign = MedicalVisit::factory()->forSubject($other)->create();

    expect(fn () => DB::transaction(fn () => MedicalDocument::factory()->forSubject($person)->create(['visit_id' => $foreign->id])))
        ->toThrow(QueryException::class);

    $doc = MedicalDocument::factory()->forVisit($visit)->create();
    DB::table('medical_visits')->where('id', $visit->id)->delete();

    $fresh = MedicalDocument::query()->find($doc->id);
    expect($fresh->visit_id)->toBeNull()->and($fresh->subject_id)->toBe($person->id);
});

it('enforces the kind and thumbnail checks', function () {
    [, $person] = docEditor();

    expect(fn () => DB::transaction(fn () => MedicalDocument::factory()->forSubject($person)->create(['thumb_path' => 'x'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => MedicalDocument::factory()->image()->forSubject($person)->create(['thumb_path' => null])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => MedicalDocument::factory()->forSubject($person)->create(['kind' => 'gif'])))->toThrow(QueryException::class);
});

it('re-checks the per-person cap inside the insert transaction and keeps the row count at the cap', function () {
    [$user, $person] = docEditor();
    config(['medical.max_documents_per_subject' => 2]);
    MedicalDocument::factory()->count(2)->forSubject($person)->create();
    $stored = app(MedicalDocumentStore::class)->put($person->id, MedicalFileFixtures::pdf());
    $viewer = Viewer::for($user);

    expect(fn () => app(DocumentQuery::class)->create($viewer, $person, $stored, 'Late', null))
        ->toThrow(DocumentRejected::class);
    expect(MedicalDocument::where('subject_id', $person->id)->count())->toBe(2);
});

it('answers a visit deleted between lookup and update with the one visit_id error instead of a 500', function () {
    [$user, $person] = docEditor();
    $visit = MedicalVisit::factory()->create(['subject_id' => $person->id]);
    $document = MedicalDocument::factory()->forSubject($person)->create();
    $done = false;
    DB::listen(function ($query) use (&$done, $visit) {
        if (! $done && str_contains($query->sql, 'from "medical_visits"')) {
            $done = true;
            DB::table('medical_visits')->where('id', $visit->id)->delete();
        }
    });

    $this->withoutMiddleware(PreventRequestForgery::class)->actingAs($user)
        ->patch("/health/people/{$person->id}/documents/{$document->id}", ['title' => 'T', 'visit_id' => $visit->id])
        ->assertSessionHasErrors(['visit_id' => 'health.upload.err_visit']);
});
