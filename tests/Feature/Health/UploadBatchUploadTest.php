<?php

use App\Access\AccessLevel;
use App\Medical\Ai\RunExtractionJob;
use App\Models\MedicalSubject;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ImageFixtures;
use Tests\Support\MedicalFileFixtures;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    Storage::fake('medical');
    Bus::fake();
});

/** @return array{0: User, 1: MedicalSubject, 2: string} an Edit member, a consenting person and an open batch */
function ubUpScene(AccessLevel $level = AccessLevel::Edit): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user, AccessLevel::Edit);
    $person = MedicalFixtures::subject(['ai_consent' => true], [[$user, $level]]);
    DB::table('ai_notice_acks')->insert(['user_id' => $user->id, 'providers' => 'codex', 'acknowledged_at' => now()]);

    return [$user, $person, ubUpBatch($user, $person)];
}

function ubUpBatch(User $user, MedicalSubject $person, string $status = 'open'): string
{
    $batch = (string) Str::uuid();
    DB::table('medical_upload_batches')->insert([
        'id' => $batch, 'subject_id' => $person->id, 'created_by' => $user->id, 'acted_by' => $user->id,
        'status' => $status, 'expires_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $batch;
}

function ubUpPost(User $user, string $personId, string $name, string $bytes, array $extra = [])
{
    return test()->actingAs($user)->post(
        '/health/people/'.$personId.'/documents',
        ['file' => UploadedFile::fake()->createWithContent($name, $bytes)] + $extra,
        ['Accept' => 'application/json'],
    );
}

function ubUpFiles(): int
{
    return count(Storage::disk('medical')->allFiles());
}

it('stores the file into an open batch, creates the item and starts the first run', function () {
    [$user, $person, $batch] = ubUpScene();

    $r = ubUpPost($user, $person->id, 'Blood test.jpg', ImageFixtures::jpegWithExif(), ['upload_batch_id' => $batch])->assertCreated();

    $r->assertJsonPath('document.kind', 'image')->assertJsonPath('document.title', 'Blood test')->assertJsonPath('item.duplicateOf', null);
    $item = DB::table('medical_upload_batch_items')->sole();
    expect($r->json('item.id'))->toBe($item->id)->and($item->batch_id)->toBe($batch)->and($item->document_id)->toBe($r->json('document.id'))
        ->and(DB::table('medical_documents')->where('id', $item->document_id)->value('upload_batch_id'))->toBe($batch)
        ->and(ubUpFiles())->toBeGreaterThan(0);
    Bus::assertDispatched(RunExtractionJob::class);
});

it('behaves as before without upload_batch_id', function () {
    [$user, $person] = ubUpScene();

    $r = ubUpPost($user, $person->id, 'plain.jpg', ImageFixtures::jpeg())->assertCreated();

    expect($r->json('item'))->toBeNull()->and(DB::table('medical_upload_batch_items')->count())->toBe(0)
        ->and(DB::table('medical_documents')->whereNotNull('upload_batch_id')->count())->toBe(0);
    Bus::assertNotDispatched(RunExtractionJob::class);
});

it('marks a file that is already stored for the person as a duplicate of that document', function () {
    [$user, $person, $batch] = ubUpScene();
    $bytes = ImageFixtures::jpeg(80, 60);
    $first = ubUpPost($user, $person->id, 'a.jpg', $bytes)->assertCreated()->json('document.id');

    $r = ubUpPost($user, $person->id, 'b.jpg', $bytes, ['upload_batch_id' => $batch])->assertCreated();

    expect($r->json('item.duplicateOf'))->toBe($first);
});

it('refuses another person\'s batch, a finished batch and garbage with err_closed and writes no file', function () {
    [$user, $person, $batch] = ubUpScene();
    [, , $otherBatch] = ubUpScene();
    $mine = MedicalFixtures::subject(['ai_consent' => true], [[$user, AccessLevel::Edit]]);
    $mineFinished = ubUpBatch($user, $mine, 'finished');
    $before = ubUpFiles();

    foreach ([[$person->id, $otherBatch], [$mine->id, $mineFinished], [$person->id, 'garbage'], [$person->id, (string) Str::uuid()], [$person->id, str_repeat('x', 65)]] as [$personId, $id]) {
        ubUpPost($user, (string) $personId, 'x.jpg', ImageFixtures::jpeg(), ['upload_batch_id' => $id])
            ->assertStatus(422)->assertJsonPath('errors.'.(strlen($id) > 64 ? 'upload_batch_id' : 'file').'.0', 'health.batch.err_closed');
    }
    expect(ubUpFiles())->toBe($before)->and(DB::table('medical_upload_batch_items')->count())->toBe(0)
        ->and(DB::table('medical_documents')->count())->toBe(0);
});

it('refuses the 51st file with err_full and removes its stored files', function () {
    [$user, $person, $batch] = ubUpScene();
    config(['medical.upload_batches.max_files' => 2]);
    ubUpPost($user, $person->id, 'a.jpg', ImageFixtures::jpeg(40, 30), ['upload_batch_id' => $batch])->assertCreated();
    ubUpPost($user, $person->id, 'b.jpg', ImageFixtures::jpeg(50, 30), ['upload_batch_id' => $batch])->assertCreated();
    $before = ubUpFiles();

    ubUpPost($user, $person->id, 'c.jpg', ImageFixtures::jpeg(60, 30), ['upload_batch_id' => $batch])
        ->assertStatus(422)->assertJsonPath('errors.file.0', 'health.batch.err_full');

    expect(ubUpFiles())->toBe($before)->and(DB::table('medical_upload_batch_items')->count())->toBe(2);
});

it('refuses the same bytes twice in one batch with err_duplicate and removes its stored files', function () {
    [$user, $person, $batch] = ubUpScene();
    $bytes = ImageFixtures::jpeg(70, 50);
    ubUpPost($user, $person->id, 'a.jpg', $bytes, ['upload_batch_id' => $batch])->assertCreated();
    $before = ubUpFiles();

    ubUpPost($user, $person->id, 'b.jpg', $bytes, ['upload_batch_id' => $batch])
        ->assertStatus(422)->assertJsonPath('errors.file.0', 'health.batch.err_duplicate');

    expect(ubUpFiles())->toBe($before)->and(DB::table('medical_upload_batch_items')->count())->toBe(1);
});

it('keeps the existing type and read refusals and creates no item', function () {
    [$user, $person, $batch] = ubUpScene();

    ubUpPost($user, $person->id, 'a.svg', ImageFixtures::svg(), ['upload_batch_id' => $batch])->assertStatus(422)->assertJsonPath('errors.file.0', 'health.upload.err_type');
    ubUpPost($user, $person->id, 'a.pdf', 'not a pdf at all', ['upload_batch_id' => $batch])->assertStatus(422);

    expect(DB::table('medical_upload_batch_items')->count())->toBe(0)->and(ubUpFiles())->toBe(0);
});

it('answers 403 for a View member and does not store anything', function () {
    [$user, $person, $batch] = ubUpScene(AccessLevel::View);

    ubUpPost($user, $person->id, 'a.jpg', ImageFixtures::jpeg(), ['upload_batch_id' => $batch])->assertForbidden();

    expect(ubUpFiles())->toBe(0);
});

it('accepts a PDF into a batch', function () {
    [$user, $person, $batch] = ubUpScene();

    ubUpPost($user, $person->id, 'r.pdf', MedicalFileFixtures::pdf(), ['upload_batch_id' => $batch])->assertCreated()->assertJsonPath('document.kind', 'pdf');
});

it('throttles the document upload at 60 per minute', function () {
    $middleware = Route::getRoutes()->getByName('health.documents.store')->gatherMiddleware();

    expect($middleware)->toContain('throttle:60,1,health.documents.store');
});

describe('person page', function () {
    it('carries openBatch with counts and expiry for an Edit viewer and the batch file limit', function () {
        [$user, $person, $batch] = ubUpScene();
        ubUpPost($user, $person->id, 'a.jpg', ImageFixtures::jpeg(), ['upload_batch_id' => $batch])->assertCreated();
        DB::table('medical_upload_batch_items')->update(['state' => 'done']);
        ubUpPost($user, $person->id, 'b.jpg', ImageFixtures::jpeg(33, 21), ['upload_batch_id' => $batch])->assertCreated();

        $this->actingAs($user)->get("/health/people/{$person->id}")->assertInertia(fn (Assert $page) => $page
            ->where('openBatch.id', $batch)->where('openBatch.total', 2)->where('openBatch.done', 1)->has('openBatch.expiresAt')
            ->where('limits.batchMaxFiles', 50));
    });

    it('is null for a View member and when no batch is open', function () {
        [$user, $person, $batch] = ubUpScene(AccessLevel::View);
        $this->actingAs($user)->get("/health/people/{$person->id}")->assertInertia(fn (Assert $page) => $page->where('openBatch', null));

        DB::table('medical_subject_members')->where('subject_id', $person->id)->where('user_id', $user->id)->update(['level' => AccessLevel::Edit->value]);
        DB::table('medical_upload_batches')->where('id', $batch)->update(['status' => 'finished', 'finished_at' => now()]);
        $this->actingAs($user)->get("/health/people/{$person->id}")->assertInertia(fn (Assert $page) => $page->where('openBatch', null));
    });
});
