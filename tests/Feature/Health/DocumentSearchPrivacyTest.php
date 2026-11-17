<?php

use App\Access\AccessLevel;
use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DocumentTextFixtures;
use Tests\Support\MedicalFixtures;
use Tests\Support\MedicalPrivacy;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

const DOC_SEARCH_PRIV_WORD = 'Zxsearchprivword';

/**
 * Normalised answer of the search: the whole JSON body, status and the compared headers.
 */
function docSearchPrivAnswer(object $test, User $viewer, array $payload): string
{
    app('auth')->forgetGuards();
    app('cache')->store()->flush();
    $test->flushSession();

    return MedicalPrivacy::normalise($test->actingAs($viewer)->postJson('/health/search', $payload));
}

/** Viewer V (Health edit) with a visible person, a visible document and the hidden-case builders. */
function docSearchPrivBase(): array
{
    $viewer = User::factory()->create();
    $other = User::factory()->create();
    MedicalFixtures::grantHealth($viewer, AccessLevel::Edit);
    MedicalFixtures::grantHealth($other, AccessLevel::Edit);
    $visible = MedicalFixtures::subject(['name' => 'Visible'], [[$viewer, AccessLevel::Edit], [$other, AccessLevel::Edit]]);
    $shown = MedicalDocument::factory()->forSubject($visible)->create(['title' => 'Shown '.DOC_SEARCH_PRIV_WORD]);
    DocumentTextFixtures::save($shown, ['visible text '.DOC_SEARCH_PRIV_WORD]);

    return [$viewer, $other, $visible, $shown];
}

/** @return array<string, Closure(User, User): void> the ways a matching document can be out of the viewer's reach */
function docSearchPrivHiddenCases(): array
{
    $text = fn (MedicalDocument $d) => DocumentTextFixtures::save($d, ['hidden text '.DOC_SEARCH_PRIV_WORD]);

    return [
        'no membership' => function (User $viewer, User $other) use ($text) {
            $person = MedicalFixtures::subject(['name' => 'Hidden'], [[$other, AccessLevel::Edit]]);
            $text(MedicalDocument::factory()->forSubject($person)->create(['title' => 'Hidden '.DOC_SEARCH_PRIV_WORD]));
        },
        'own profile with self access off' => function (User $viewer) use ($text) {
            $person = MedicalFixtures::subject(['name' => 'Own off', 'user_id' => $viewer->id, 'self_access_enabled' => false]);
            $text(MedicalDocument::factory()->forSubject($person)->create(['title' => 'Own off '.DOC_SEARCH_PRIV_WORD]));
        },
        'archived person' => function (User $viewer) use ($text) {
            $person = MedicalFixtures::subject(['name' => 'Archived person'], [[$viewer, AccessLevel::Edit]]);
            $text(MedicalDocument::factory()->forSubject($person)->create(['title' => 'Archived person '.DOC_SEARCH_PRIV_WORD]));
            DB::table('medical_subjects')->where('id', $person->id)->update(['archived_at' => now()]);
        },
        'archived document' => function (User $viewer) use ($text) {
            $person = MedicalFixtures::subject(['name' => 'Has archived'], [[$viewer, AccessLevel::Edit]]);
            $document = MedicalDocument::factory()->forSubject($person)->create(['title' => 'Archived doc '.DOC_SEARCH_PRIV_WORD]);
            $text($document);
            DB::table('medical_documents')->where('id', $document->id)->update(['archived_at' => now()]);
        },
    ];
}

it('answers a hidden, archived or foreign document byte for byte like a family without it', function (string $case) {
    // The same viewer and the same payloads in two otherwise identical worlds: with the matching document, and without.
    $payloads = [
        ['q' => DOC_SEARCH_PRIV_WORD],
        ['q' => 'hidden text'],
        ['q' => DOC_SEARCH_PRIV_WORD, 'page' => 2],
        ['q' => DOC_SEARCH_PRIV_WORD, 'person' => 'not-a-uuid'],
    ];

    [$viewer, $other, $visible] = docSearchPrivBase();
    $without = [];
    foreach ($payloads as $i => $payload) {
        $without[$i] = docSearchPrivAnswer($this, $viewer, $payload + ['person' => null]);
    }
    // Person filters aimed at the person that will be hidden.
    $hiddenPerson = null;
    docSearchPrivHiddenCases()[$case]($viewer, $other);
    $hiddenPerson = MedicalSubject::query()->where('name', '!=', 'Visible')->value('id');
    $with = [];
    foreach ($payloads as $i => $payload) {
        $with[$i] = docSearchPrivAnswer($this, $viewer, $payload + ['person' => null]);
    }

    expect($with)->toBe($without);

    // A filter on the hidden person gives the same answer as a filter on a random person id of a family without one.
    $filteredHidden = docSearchPrivAnswer($this, $viewer, ['q' => DOC_SEARCH_PRIV_WORD, 'person' => $hiddenPerson]);
    $filteredRandom = docSearchPrivAnswer($this, $viewer, ['q' => DOC_SEARCH_PRIV_WORD, 'person' => (string) Str::uuid7()]);
    expect($filteredHidden)->toBe($filteredRandom);

    // The answer carries neither the hidden title nor a count of any kind.
    $body = json_decode(substr($with[0], strpos($with[0], '{')), true);
    expect(array_keys($body))->toBe(['items', 'hasMore', 'page'])
        ->and(array_column($body['items'], 'documentId'))->toHaveCount(1)
        ->and($with[0])->not->toContain('Hidden')->not->toContain('Archived')->not->toContain('Own off');
})->with(fn () => array_keys(docSearchPrivHiddenCases()));

it('finds what a view-only member may open and nothing for an administrator who is a member of nobody', function () {
    [$viewer, $other, $visible, $shown] = docSearchPrivBase();
    $viewOnly = User::factory()->create();
    MedicalFixtures::grantHealth($viewOnly, AccessLevel::View);
    DB::table('medical_subject_members')->insert(['subject_id' => $visible->id, 'user_id' => $viewOnly->id, 'level' => AccessLevel::View->value, 'granted_by' => null, 'created_at' => now()]);
    $admin = User::factory()->admin()->create();
    MedicalFixtures::grantHealth($admin, AccessLevel::Edit);

    $ids = fn (User $u) => array_column($this->actingAs($u)->postJson('/health/search', ['q' => DOC_SEARCH_PRIV_WORD])->assertOk()->json('items'), 'documentId');

    expect($ids($viewOnly))->toBe([$shown->id])
        ->and($ids($admin))->toBe([]);
});

it('makes an archived document findable again when restored and drops a deleted one', function () {
    [$viewer, , $visible, $shown] = docSearchPrivBase();
    $ids = fn () => array_column($this->actingAs($viewer)->postJson('/health/search', ['q' => DOC_SEARCH_PRIV_WORD])->assertOk()->json('items'), 'documentId');

    DB::table('medical_documents')->where('id', $shown->id)->update(['archived_at' => now()]);
    expect($ids())->toBe([]);
    DB::table('medical_documents')->where('id', $shown->id)->update(['archived_at' => null]);
    expect($ids())->toBe([$shown->id]);
    DB::table('medical_documents')->where('id', $shown->id)->delete();
    expect($ids())->toBe([]);
});

it('does not let a title or text of a hidden person appear in the answer to a title query', function () {
    [$viewer, $other] = docSearchPrivBase();
    $hidden = MedicalFixtures::subject(['name' => 'Hidden Person'], [[$other, AccessLevel::Edit]]);
    $document = MedicalDocument::factory()->forSubject($hidden)->create(['title' => MedicalPrivacy::CANARY.' title']);
    DocumentTextFixtures::save($document, [MedicalPrivacy::CANARY.' body']);

    $answer = docSearchPrivAnswer($this, $viewer, ['q' => MedicalPrivacy::CANARY]);

    expect($answer)->not->toContain('Hidden Person');
    foreach (MedicalPrivacy::CANARY_NEEDLES as $needle) {
        expect(str_contains($answer, $needle))->toBeFalse();
    }
});

it('issues one statement that carries the membership predicate and no count query', function () {
    [$viewer] = docSearchPrivBase();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($viewer)->postJson('/health/search', ['q' => DOC_SEARCH_PRIV_WORD])->assertOk();
    $statements = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    $medical = array_values(array_filter($statements, fn (string $sql) => str_contains($sql, 'medical_document_texts')));
    expect($medical)->toHaveCount(1)
        ->and(MedicalPrivacy::hasMembershipPredicate($medical[0]))->toBeTrue()
        ->and(strtolower($medical[0]))->not->toContain('count(')->not->toContain('ts_headline');
    expect(MedicalPrivacy::unscopedReads($statements))->toBe([]);
});
