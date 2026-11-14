<?php

use App\Access\AccessLevel;
use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use App\Models\MedicalVisit;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ImageFixtures;
use Tests\Support\MedicalFileFixtures;
use Tests\Support\MedicalFixtures;
use Tests\Support\MedicalPrivacy;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    Storage::fake('medical');
});

/** @return array{0: User, 1: MedicalSubject} */
function manageEditor(): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user);

    return [$user, MedicalFixtures::subject([], [[$user, AccessLevel::Edit]])];
}

function manageDoc(MedicalSubject $person, array $attrs = []): MedicalDocument
{
    $document = MedicalDocument::factory()->pdf()->create(['subject_id' => $person->id] + $attrs);
    Storage::disk('medical')->put($document->path, MedicalFileFixtures::pdf());

    return $document;
}

function docUrl(string $person, string $document): string
{
    return "/health/people/{$person}/documents/{$document}";
}

describe('update', function () {
    it('renames and attaches to a visit of the same person, and detaches with null', function () {
        [$user, $person] = manageEditor();
        $document = manageDoc($person);
        $visit = MedicalVisit::factory()->forSubject($person)->create();

        $this->actingAs($user)->patchJson(docUrl($person->id, $document->id), ['title' => 'Blood test', 'visit_id' => $visit->id])
            ->assertRedirect("/health/people/{$person->id}")->assertSessionHas('toast.key', 'health.doc.saved');
        expect(MedicalDocument::find($document->id))->title->toBe('Blood test')->visit_id->toBe($visit->id);

        $this->actingAs($user)->patchJson(docUrl($person->id, $document->id), ['title' => 'Blood test 2', 'visit_id' => null])->assertRedirect();
        expect(MedicalDocument::find($document->id))->title->toBe('Blood test 2')->visit_id->toBeNull();
    });

    it('keeps the attachment when the body has no visit_id key', function () {
        [$user, $person] = manageEditor();
        $visit = MedicalVisit::factory()->forSubject($person)->create();
        $document = manageDoc($person, ['visit_id' => $visit->id]);

        $this->actingAs($user)->patchJson(docUrl($person->id, $document->id), ['title' => 'Only rename'])->assertRedirect();

        expect(MedicalDocument::find($document->id))->title->toBe('Only rename')->visit_id->toBe($visit->id);
    });

    it('answers one identical 422 for a visit of another person, a hidden visit and a random uuid', function () {
        [$user, $person] = manageEditor();
        $document = manageDoc($person);
        $other = MedicalFixtures::subject([], [[$user, AccessLevel::Edit]]);
        $hiddenSubject = MedicalFixtures::subject();
        $bodies = [];
        foreach ([MedicalVisit::factory()->forSubject($other)->create()->id, MedicalVisit::factory()->forSubject($hiddenSubject)->create()->id, (string) Str::uuid(), 'abc'] as $visit) {
            $response = $this->actingAs($user)->patchJson(docUrl($person->id, $document->id), ['title' => 'X', 'visit_id' => $visit])->assertStatus(422);
            $bodies[] = $response->json();
        }
        expect(array_unique(array_map('json_encode', $bodies)))->toHaveCount(1)
            ->and($bodies[0]['errors'])->toHaveKey('visit_id')
            ->and(MedicalDocument::find($document->id)->title)->toBe('Scan');
    });

    it('answers the identical 403 for view members, hidden, unknown and malformed ids', function () {
        [$user, $person] = manageEditor();
        $document = manageDoc($person);
        $viewer = User::factory()->create();
        MedicalFixtures::grantHealth($viewer);
        $viewOnly = MedicalFixtures::subject([], [[$viewer, AccessLevel::View]]);
        $viewDoc = manageDoc($viewOnly);
        $hiddenSubject = MedicalFixtures::subject();
        $hidden = manageDoc($hiddenSubject, ['title' => 'ZxCanaryMed']);

        // The page carries the signed-in member's own props, so the reference is taken per actor.
        $reference = fn (User $actor): string => MedicalPrivacy::normalise($this->actingAs($actor)->patch(docUrl((string) Str::uuid(), (string) Str::uuid()), ['title' => 'X']));
        $cases = [
            [$user, docUrl($person->id, (string) Str::uuid())],
            [$user, docUrl($person->id, 'abc')],
            [$user, docUrl($person->id, $hidden->id)],
            [$user, docUrl($hiddenSubject->id, $hidden->id)],
            [$viewer, docUrl($viewOnly->id, $viewDoc->id)],
        ];
        foreach ($cases as [$actor, $url]) {
            $response = $this->actingAs($actor)->patch($url, ['title' => 'Changed']);
            expect($response->getStatusCode())->toBe(403)->and(MedicalPrivacy::normalise($response))->toBe($reference($actor), $url);
        }
        expect(MedicalDocument::find($hidden->id)->title)->toBe('ZxCanaryMed')->and(MedicalDocument::find($viewDoc->id)->title)->toBe('Scan');
    });

    it('checks authorization before validation (an invalid body never gives a validation response)', function () {
        [$user, $person] = manageEditor();
        $viewer = User::factory()->create();
        MedicalFixtures::grantHealth($viewer);
        $viewOnly = MedicalFixtures::subject([], [[$viewer, AccessLevel::View]]);
        $ownOff = MedicalFixtures::subject(['user_id' => $user->id, 'self_access_enabled' => false]);
        $hiddenSubject = MedicalFixtures::subject();
        $uuid = (string) Str::uuid();

        $reference = fn (User $actor): string => MedicalPrivacy::normalise($this->actingAs($actor)->patch(docUrl($uuid, $uuid), ['title' => 'Valid']));
        $targets = [
            [$user, $hiddenSubject->id], [$user, $ownOff->id], [$user, $uuid], [$user, 'abc'], [$viewer, $viewOnly->id],
        ];
        foreach ($targets as [$actor, $personId]) {
            foreach ([['title' => ''], ['title' => str_repeat('a', 121)], ['title' => 'ok', 'visit_id' => str_repeat('a', 70)], []] as $body) {
                $response = $this->actingAs($actor)->patch(docUrl($personId, $uuid), $body);
                expect($response->getStatusCode())->toBe(403)
                    ->and(MedicalPrivacy::normalise($response))->toBe($reference($actor))
                    ->and(session('errors'))->toBeNull();
            }
        }
    });

    it('validates the title for an editor', function () {
        [$user, $person] = manageEditor();
        $document = manageDoc($person);

        $this->actingAs($user)->patchJson(docUrl($person->id, $document->id), ['title' => ''])->assertStatus(422)->assertJsonValidationErrors('title');
        $this->actingAs($user)->patchJson(docUrl($person->id, $document->id), ['title' => str_repeat('a', 121)])->assertStatus(422);
    });
});

describe('destroy', function () {
    it('removes the row and the files of an image and of a PDF', function () {
        [$user, $person] = manageEditor();
        $image = MedicalDocument::factory()->image()->create(['subject_id' => $person->id]);
        Storage::disk('medical')->put($image->path, ImageFixtures::jpeg());
        Storage::disk('medical')->put($image->thumb_path, ImageFixtures::jpeg());
        $pdf = manageDoc($person);
        expect(Storage::disk('medical')->allFiles())->toHaveCount(3);

        $this->actingAs($user)->delete(docUrl($person->id, $image->id))->assertRedirect("/health/people/{$person->id}")->assertSessionHas('toast.key', 'health.doc.deleted');
        expect(MedicalDocument::find($image->id))->toBeNull()
            ->and(Storage::disk('medical')->allFiles())->toBe([$pdf->path]);

        $this->actingAs($user)->delete(docUrl($person->id, $pdf->id))->assertRedirect();
        expect(Storage::disk('medical')->allFiles())->toBe([]);
    });

    it('answers the identical 403 and keeps rows and files for hidden, unknown, malformed and view-only', function () {
        [$user, $person] = manageEditor();
        $viewer = User::factory()->create();
        MedicalFixtures::grantHealth($viewer);
        $viewOnly = MedicalFixtures::subject([], [[$viewer, AccessLevel::View]]);
        $viewDoc = manageDoc($viewOnly);
        $hiddenSubject = MedicalFixtures::subject();
        $hidden = manageDoc($hiddenSubject);

        $reference = fn (User $actor): string => MedicalPrivacy::normalise($this->actingAs($actor)->delete(docUrl((string) Str::uuid(), (string) Str::uuid())));
        foreach ([[$user, docUrl($person->id, $hidden->id)], [$user, docUrl($hiddenSubject->id, $hidden->id)], [$user, docUrl($person->id, 'abc')], [$viewer, docUrl($viewOnly->id, $viewDoc->id)]] as [$actor, $url]) {
            $response = $this->actingAs($actor)->delete($url);
            expect($response->getStatusCode())->toBe(403)->and(MedicalPrivacy::normalise($response))->toBe($reference($actor));
        }
        expect(MedicalDocument::count())->toBe(2)
            ->and(Storage::disk('medical')->exists($hidden->path))->toBeTrue()
            ->and(Storage::disk('medical')->exists($viewDoc->path))->toBeTrue();
    });
});

describe('medical:prune-orphans', function () {
    function ageFile(string $relative, int $seconds): void
    {
        touch(Storage::disk('medical')->path($relative), time() - $seconds);
    }

    it('removes old unreferenced files and keeps fresh and referenced ones', function () {
        [, $person] = manageEditor();
        $referenced = MedicalDocument::factory()->image()->create(['subject_id' => $person->id]);
        foreach ([$referenced->path, $referenced->thumb_path] as $p) {
            Storage::disk('medical')->put($p, ImageFixtures::jpeg());
            ageFile($p, 86400);
        }
        $oldOrphan = $person->id.'/'.strtoupper((string) Str::ulid()).'.pdf';
        $freshOrphan = $person->id.'/'.strtoupper((string) Str::ulid()).'.pdf';
        $stray = $person->id.'/notes.txt';
        foreach ([$oldOrphan, $freshOrphan, $stray] as $p) {
            Storage::disk('medical')->put($p, 'x');
        }
        ageFile($oldOrphan, 7200);
        ageFile($stray, 7200);

        $this->artisan('medical:prune-orphans')->expectsOutput('Pruned 1 orphan files and 0 directories.')->assertExitCode(0);

        $disk = Storage::disk('medical');
        expect($disk->exists($oldOrphan))->toBeFalse()
            ->and($disk->exists($freshOrphan))->toBeTrue()
            ->and($disk->exists($stray))->toBeTrue()
            ->and($disk->exists($referenced->path))->toBeTrue()
            ->and($disk->exists($referenced->thumb_path))->toBeTrue();
    });

    it('removes the directory of a deleted person after the grace and keeps the others', function () {
        [, $person] = manageEditor();
        $gone = (string) Str::uuid();
        $fresh = (string) Str::uuid();
        $disk = Storage::disk('medical');
        $disk->makeDirectory($gone);
        $disk->makeDirectory($fresh);
        $disk->makeDirectory($person->id);
        $disk->makeDirectory('not-a-person');
        foreach ([$gone, $person->id, 'not-a-person'] as $dir) {
            touch($disk->path($dir), time() - 7200);
        }

        $this->artisan('medical:prune-orphans')->expectsOutput('Pruned 0 orphan files and 1 directories.')->assertExitCode(0);

        expect($disk->directoryExists($gone))->toBeFalse()
            ->and($disk->directoryExists($fresh))->toBeTrue()
            ->and($disk->directoryExists($person->id))->toBeTrue()
            ->and($disk->directoryExists('not-a-person'))->toBeTrue();
    });

    it('never touches Storage itself and is scheduled daily', function () {
        $source = (string) file_get_contents(app_path('Console/Commands/PruneMedicalOrphans.php'));
        expect($source)->not->toContain('Storage::');

        $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'medical-prune-orphans');
        expect($event)->not->toBeNull()
            ->and($event->command)->toContain('medical:prune-orphans')
            ->and($event->expression)->toBe('0 0 * * *');
    });
});
