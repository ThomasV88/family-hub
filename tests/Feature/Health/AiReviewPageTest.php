<?php

use App\Access\AccessLevel;
use App\Models\MedicalAiExtraction;
use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\MedicalFileFixtures;
use Tests\Support\MedicalFixtures;
use Tests\Support\MedicalPrivacy;

uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function reviewPayload(): array
{
    return [
        'schema_version' => 1, 'document_date' => '2026-01-05', 'specialty_key' => 'cardiology',
        'visits' => [['visited_on' => '2026-01-05', 'doctor' => 'Dr. Review', 'specialty_key' => 'cardiology', 'specialty_other' => null, 'diagnosis' => null, 'notes' => null]],
        'medications' => [['name' => 'Review med', 'dose' => '5 mg', 'schedule' => null, 'started_on' => null, 'ended_on' => null, 'notes' => null]],
        'allergies' => [['substance' => 'Pollen', 'reaction' => null, 'severity' => 'unknown']],
        'vaccinations' => [],
    ];
}

/** @return array{0: User, 1: MedicalSubject, 2: MedicalDocument, 3: MedicalAiExtraction} */
function reviewSetup(AccessLevel $level = AccessLevel::Edit, bool $consent = true, string $kind = 'pdf'): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user);
    $person = MedicalFixtures::subject(['ai_consent' => $consent], [[$user, $level]]);
    $document = MedicalDocument::factory()->{$kind}()->create(['subject_id' => $person->id]);
    $extraction = MedicalAiExtraction::factory()->forDocument($document)->ready(reviewPayload(), 3)->create(['started_by' => $user->id]);

    return [$user, $person, $document, $extraction];
}

function reviewUrl(MedicalSubject $person, string $document, string $query = ''): string
{
    return "/health/people/{$person->id}/documents/{$document}/review".$query;
}

/** Status and message only: the debug body of a 403 carries a stack trace with test line numbers. */
function reviewShape(TestResponse $response): string
{
    return $response->getStatusCode().'|'.(string) $response->json('message');
}

it('renders the proposal with fixed section order, entry keys and pending states, saving nothing', function () {
    [$user, $person, $document, $extraction] = reviewSetup();
    $records = fn () => DB::table('medical_visits')->count() + DB::table('medical_medications')->count() + DB::table('medical_allergies')->count();
    $before = [$records(), DB::table('medical_ai_decisions')->count()];

    $response = $this->actingAs($user)->get(reviewUrl($person, $document->id))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page->component('Health/AiReview')
        ->where('person.id', $person->id)
        ->where('document.id', $document->id)->where('document.kind', 'pdf')
        ->where('extraction.id', $extraction->id)->where('extraction.status', 'ready')
        ->where('extraction.readOnly', false)->where('extraction.pending', 3)
        ->where('extraction.documentDate', '2026-01-05')->where('extraction.specialtyKey', 'cardiology')
        ->where('extraction.sections.visits.0.key', 'visit-0')->where('extraction.sections.visits.0.state', 'pending')
        ->where('extraction.sections.medications.0.key', 'medication-0')
        ->where('extraction.sections.allergies.0.fields.severity', 'unknown')
        ->where('extraction.sections.vaccinations', [])
        ->where('newestExtractionId', null)->where('consentOn', true)
        ->has('specialties'));
    expect(array_keys($response->viewData('page')['props']['extraction']['sections']))->toBe(['visits', 'medications', 'allergies', 'vaccinations']);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect([$records(), DB::table('medical_ai_decisions')->count()])->toBe($before);
});

it('reports the page count of a PDF and null for an image', function () {
    Storage::fake('medical');
    [$user, $person, $document] = reviewSetup();
    Storage::disk('medical')->put($document->path, MedicalFileFixtures::pdfPages(3));
    $this->actingAs($user)->get(reviewUrl($person, $document->id))
        ->assertInertia(fn (Assert $page) => $page->where('document.pageCount', 3));

    [$user, $person, $image] = reviewSetup(kind: 'image');
    $this->actingAs($user)->get(reviewUrl($person, $image->id))
        ->assertInertia(fn (Assert $page) => $page->where('document.pageCount', null));
});

it('shows decision state and record id for decided entries', function () {
    [$user, $person, $document, $extraction] = reviewSetup();
    $record = (string) Str::uuid();
    DB::table('medical_ai_decisions')->insert(['extraction_id' => $extraction->id, 'entry_key' => 'visit-0', 'decision' => 'accepted', 'record_type' => 'visit', 'record_id' => $record, 'edited' => true, 'decided_by' => $user->id]);
    DB::table('medical_ai_decisions')->insert(['extraction_id' => $extraction->id, 'entry_key' => 'medication-0', 'decision' => 'rejected', 'decided_by' => $user->id]);

    $this->actingAs($user)->get(reviewUrl($person, $document->id))->assertInertia(fn (Assert $page) => $page
        ->where('extraction.pending', 1)
        ->where('extraction.sections.visits.0.state', 'accepted')->where('extraction.sections.visits.0.recordId', $record)->where('extraction.sections.visits.0.edited', true)
        ->where('extraction.sections.medications.0.state', 'rejected')->where('extraction.sections.medications.0.recordId', null));
});

it('opens an older superseded proposal read-only and points at the newest one', function () {
    [$user, $person, $document, $ready] = reviewSetup();
    $old = MedicalAiExtraction::factory()->forDocument($document)->superseded(reviewPayload())->create(['started_by' => $user->id]);

    $this->actingAs($user)->get(reviewUrl($person, $document->id, '?extraction='.$old->id))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('extraction.id', $old->id)->where('extraction.readOnly', true)->where('newestExtractionId', $ready->id));
});

it('refuses an extraction of another document and a malformed extraction id', function () {
    [$user, $person, $document] = reviewSetup();
    $other = MedicalDocument::factory()->pdf()->create(['subject_id' => $person->id]);
    $foreign = MedicalAiExtraction::factory()->forDocument($other)->ready(reviewPayload(), 3)->create(['started_by' => $user->id]);

    foreach (['?extraction='.$foreign->id, '?extraction=abc', '?extraction[]=x', '?extraction='.Str::uuid()] as $query) {
        $this->actingAs($user)->getJson(reviewUrl($person, $document->id, $query))->assertForbidden();
    }
});

it('redirects to the document panel when there is no ready proposal', function (string $state) {
    [$user, $person] = reviewSetup();
    $document = MedicalDocument::factory()->pdf()->create(['subject_id' => $person->id]);
    $factory = MedicalAiExtraction::factory()->forDocument($document);
    if ($state !== 'none') {
        $factory->{$state}()->create(['started_by' => $user->id]);
    }

    $this->actingAs($user)->get(reviewUrl($person, $document->id))
        ->assertRedirect(route('health.people.show', ['person' => $person->id, 'panel' => 'document', 'id' => $document->id]));
})->with(['queued', 'running', 'failed', 'cancelled', 'none']);

it('answers one identical 403 for view-only, hidden, unknown and malformed ids', function () {
    [$user, $person, $document] = reviewSetup();
    [, $viewOnly, $viewDocument] = reviewSetup(AccessLevel::View);
    MedicalFixtures::subject([], []);
    $hidden = MedicalFixtures::subject();
    $hiddenDocument = MedicalDocument::factory()->pdf()->create(['subject_id' => $hidden->id]);
    MedicalAiExtraction::factory()->forDocument($hiddenDocument)->ready(reviewPayload(), 3)->create();
    $viewMember = User::factory()->create();
    MedicalFixtures::grantHealth($viewMember);
    $viewable = MedicalFixtures::subject([], [[$viewMember, AccessLevel::View]]);
    $viewableDocument = MedicalDocument::factory()->pdf()->create(['subject_id' => $viewable->id]);
    MedicalAiExtraction::factory()->forDocument($viewableDocument)->ready(reviewPayload(), 3)->create();

    $shapes = [
        reviewShape($this->actingAs($viewMember)->getJson(reviewUrl($viewable, $viewableDocument->id))),
        reviewShape($this->actingAs($user)->getJson(reviewUrl($hidden, $hiddenDocument->id))),
        reviewShape($this->actingAs($user)->getJson(reviewUrl($person, (string) Str::uuid()))),
        reviewShape($this->actingAs($user)->getJson(reviewUrl($person, 'abc'))),
        reviewShape($this->actingAs($user)->getJson(reviewUrl($person, $hiddenDocument->id))),
        reviewShape($this->actingAs($user)->getJson(reviewUrl($person, $document->id, '?extraction=abc'))),
    ];

    expect(array_unique($shapes))->toHaveCount(1)->and($shapes[0])->toStartWith('403|');
});

it('still opens with consent switched off and reports it', function () {
    [$user, $person, $document] = reviewSetup(consent: false);

    $this->actingAs($user)->get(reviewUrl($person, $document->id))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('consentOn', false)->has('extraction.sections.visits', 1));
});

it('never shows proposal content to anyone but the Edit member', function () {
    [$user, $person, $document] = reviewSetup();
    $stranger = User::factory()->create();
    MedicalFixtures::grantHealth($stranger);
    MedicalAiExtraction::query()->update(['payload' => json_encode(['schema_version' => 1, 'document_date' => null, 'specialty_key' => null,
        'visits' => [['visited_on' => '2026-01-05', 'doctor' => MedicalPrivacy::CANARY, 'specialty_key' => 'cardiology', 'specialty_other' => null, 'diagnosis' => null, 'notes' => null]],
        'medications' => [], 'allergies' => [], 'vaccinations' => []])]);

    expect($this->actingAs($user)->get(reviewUrl($person, $document->id))->getContent())->toContain(MedicalPrivacy::CANARY);
    $response = $this->actingAs($stranger)->get(reviewUrl($person, $document->id));
    expect($response->getStatusCode())->toBe(403)->and($response->getContent())->not->toContain(MedicalPrivacy::CANARY);
});

describe('agents and waiting runs', function () {
    it('names the producing agent and model, and null for the default model', function () {
        [$user, $person, $document, $extraction] = reviewSetup();
        $url = reviewUrl($person, $document->id);

        $this->actingAs($user)->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('provenance.agent', 'codex')->where('provenance.model', null)->where('newerWaiting', null));

        DB::table('medical_ai_extractions')->where('id', $extraction->id)->update(['agent' => 'copilot', 'model' => 'gpt-test-copilot']);
        $this->actingAs($user)->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('provenance.agent', 'copilot')->where('provenance.model', 'gpt-test-copilot'));
    });

    it('keeps an older proposal reviewable beside a newer waiting run', function () {
        [$user, $person, $document, $extraction] = reviewSetup();
        DB::table('medical_ai_extractions')->where('id', $extraction->id)->update(['queued_at' => now()->subHour()]);
        $waiting = MedicalAiExtraction::factory()->forDocument($document)->waiting(now()->addMinutes(30))->create(['started_by' => $user->id]);

        $this->actingAs($user)->get(reviewUrl($person, $document->id))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('extraction.id', $extraction->id)->where('extraction.pending', 3)
            ->where('newerWaiting.id', $waiting->id)->has('newerWaiting.availableAt'));

        $this->actingAs($user)->postJson("/health/people/{$person->id}/ai/{$extraction->id}/entries/visit-0/accept")->assertSuccessful();
    });

    it('returns the waiting status block and no entries when a waiting run is opened', function () {
        [$user, $person] = reviewSetup();
        $document = MedicalDocument::factory()->pdf()->create(['subject_id' => $person->id]);
        $waiting = MedicalAiExtraction::factory()->forDocument($document)->waiting(now()->addMinutes(30))->create(['started_by' => $user->id]);

        foreach (['', '?extraction='.$waiting->id] as $query) {
            $this->actingAs($user)->get(reviewUrl($person, $document->id, $query))->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('extraction.status', 'waiting')->has('extraction.availableAt')
                ->where('extraction.sections.visits', [])->where('extraction.pending', 0)->where('provenance', null));
        }
    });
});
