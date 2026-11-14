<?php

use App\Access\AccessLevel;
use App\Models\MedicalDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

/** @return array{0: User, 1: App\Models\MedicalSubject} */
function documentPageEditor(): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user);

    return [$user, MedicalFixtures::subject([], [[$user, AccessLevel::Edit]])];
}

function documentPageVisit(string $subjectId, string $date = '2025-01-01'): string
{
    $id = (string) Str::uuid();
    DB::table('medical_visits')->insert(['id' => $id, 'subject_id' => $subjectId, 'visited_on' => $date, 'specialty_key' => 'dentist', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

describe('props', function () {
    it('lists the person documents without storage details and counts them per visit and unattached', function () {
        [$user, $person] = documentPageEditor();
        $visit = documentPageVisit($person->id);
        $attached = MedicalDocument::factory()->pdf()->create(['subject_id' => $person->id, 'visit_id' => $visit, 'title' => 'Attached']);
        $loose = MedicalDocument::factory()->image()->create(['subject_id' => $person->id, 'title' => 'Loose']);

        $response = $this->actingAs($user)->get("/health/people/{$person->id}")->assertOk();
        $response->assertInertia(fn ($page) => $page->has('documents', 2)
            ->where('limits', ['maxMb' => 10, 'filesAtOnce' => 10])
            ->where('visits.0.documentCount', 1)
            ->where('unattachedDocumentIds', [(string) $loose->getKey()])
            ->where('timelineFilters', ['dentist', 'documents'])
            ->has('timeline', 2));
        $json = json_encode($response->viewData('page')['props']['documents']);
        foreach (['path', 'thumb_path', 'sha256', 'mime'] as $secret) {
            expect($json)->not->toContain('"'.$secret.'"');
        }
        expect($json)->not->toContain($attached->sha256)->not->toContain($loose->path)->not->toContain((string) $loose->thumb_path);
        $first = $response->viewData('page')['props']['documents'][0];
        expect(array_keys($first))->toEqualCanonicalizing(['id', 'kind', 'title', 'visitId', 'uploadedOn', 'sizeBytes']);
    });

    it('filters unattached documents into the timeline and keeps them out of specialty filters', function () {
        [$user, $person] = documentPageEditor();
        documentPageVisit($person->id);
        MedicalDocument::factory()->pdf()->create(['subject_id' => $person->id]);

        $this->actingAs($user)->get("/health/people/{$person->id}?filter=documents")
            ->assertInertia(fn ($page) => $page->where('filter', 'documents')->has('timeline', 1)->where('timeline.0.type', 'document'));
        $this->actingAs($user)->get("/health/people/{$person->id}?filter=dentist")
            ->assertInertia(fn ($page) => $page->has('timeline', 1)->where('timeline.0.type', 'visit'));
        $this->actingAs($user)->get("/health/people/{$person->id}")
            ->assertInertia(fn ($page) => $page->has('timeline', 2));
    });

    it('offers no documents filter without unattached documents and never counts other people documents', function () {
        [$user, $person] = documentPageEditor();
        $visit = documentPageVisit($person->id);
        $other = MedicalFixtures::subject([], [[User::factory()->create(), AccessLevel::Edit]]);
        MedicalDocument::factory()->pdf()->create(['subject_id' => $person->id, 'visit_id' => $visit]);
        MedicalDocument::factory()->pdf()->create(['subject_id' => $other->id, 'title' => 'ZxCanaryMed']);

        $response = $this->actingAs($user)->get("/health/people/{$person->id}")
            ->assertInertia(fn ($page) => $page->has('documents', 1)->where('timelineFilters', ['dentist'])->where('unattachedDocumentIds', [])->where('visits.0.documentCount', 1));
        expect(json_encode($response->viewData('page')['props']))->not->toContain('ZxCanaryMed');
    });
});

describe('panels', function () {
    it('resolves document and new-document panels inside the person and aborts 403 otherwise', function () {
        [$user, $person] = documentPageEditor();
        $visit = documentPageVisit($person->id);
        $mine = MedicalDocument::factory()->pdf()->create(['subject_id' => $person->id]);
        $hidden = MedicalFixtures::subject([], [[User::factory()->create(), AccessLevel::Edit]]);
        $theirs = MedicalDocument::factory()->pdf()->create(['subject_id' => $hidden->id]);
        $theirVisit = documentPageVisit($hidden->id);
        $random = (string) Str::uuid();

        $this->actingAs($user)->get("/health/people/{$person->id}?panel=document&id={$mine->getKey()}")
            ->assertInertia(fn ($page) => $page->where('panel', ['kind' => 'document', 'id' => (string) $mine->getKey()]));
        $this->actingAs($user)->get("/health/people/{$person->id}?panel=new-document")
            ->assertInertia(fn ($page) => $page->where('panel', ['kind' => 'new-document', 'id' => null, 'visit' => null]));
        $this->actingAs($user)->get("/health/people/{$person->id}?panel=new-document&visit={$visit}")
            ->assertInertia(fn ($page) => $page->where('panel.visit', $visit));

        $reference = $this->actingAs($user)->get("/health/people/{$hidden->id}")->assertForbidden()->viewData('page');
        $queries = [
            "panel=document&id={$theirs->getKey()}", "panel=document&id={$random}", 'panel=document&id=abc', 'panel=document',
            "panel=new-document&id={$mine->getKey()}", "panel=new-document&visit={$theirVisit}", "panel=new-document&visit={$random}", 'panel=new-document&visit=abc',
        ];
        foreach ($queries as $query) {
            $page = $this->actingAs($user)->get("/health/people/{$person->id}?{$query}")->assertForbidden()->viewData('page');
            expect($page['props'])->toEqual($reference['props'], $query);
        }
    });

    it('shows a view-only member the documents read-only and refuses new-document', function () {
        $user = User::factory()->create();
        MedicalFixtures::grantHealth($user, AccessLevel::View);
        $person = MedicalFixtures::subject([], [[$user, AccessLevel::View]]);
        $row = MedicalDocument::factory()->pdf()->create(['subject_id' => $person->id]);

        $this->actingAs($user)->get("/health/people/{$person->id}?panel=document&id={$row->getKey()}")
            ->assertInertia(fn ($page) => $page->where('person.canEdit', false)->has('documents', 1)->where('panel.kind', 'document'));
        $this->actingAs($user)->get("/health/people/{$person->id}?panel=new-document")->assertForbidden();
    });
});
