<?php

use App\Access\AccessLevel;
use App\Medical\Documents\Text\SearchTerms;
use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use App\Models\MedicalVisit;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\DocumentTextFixtures;
use Tests\Support\MedicalFixtures;
use Tests\Support\MedicalPrivacy;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

const DOC_SEARCH_ALPHABET = 'áäčďéíĺľňóôŕšťúýžąćęłńśźżÁÄČĎÉÍĹĽŇÓÔŔŠŤÚÝŽĄĆĘŁŃŚŹŻ';

/** @return array{0: User, 1: MedicalSubject} an editor and a person of theirs */
function docSearchScene(): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user, AccessLevel::Edit);

    return [$user, MedicalFixtures::subject(['name' => 'Search Person'], [[$user, AccessLevel::Edit]])];
}

/** @param  list<string>|null  $pages  null stores no text (a title-only document) */
function docSearchDoc(MedicalSubject $subject, string $title, ?array $pages = null, array $attrs = []): MedicalDocument
{
    $doc = MedicalDocument::factory()->forSubject($subject)->create(['title' => $title] + $attrs);
    if ($pages !== null) {
        DocumentTextFixtures::save($doc, $pages);
    }

    return $doc;
}

function docSearchPost(object $test, User $user, string $q, array $extra = []): TestResponse
{
    return $test->actingAs($user)->postJson('/health/search', ['q' => $q] + $extra);
}

/** @return list<string> */
function docSearchIds(TestResponse $response): array
{
    return array_column($response->assertOk()->json('items'), 'documentId');
}

it('folds every Slovak and Polish letter the same in SQL and in PHP', function () {
    $diff = [];
    foreach (mb_str_split(DOC_SEARCH_ALPHABET) as $letter) {
        $sql = (string) DB::selectOne('select lower(public.fh_unaccent(?)) as f', [$letter])->f;
        if ($sql !== SearchTerms::fold($letter)) {
            $diff[] = $letter.': sql='.$sql.' php='.SearchTerms::fold($letter);
        }
    }

    expect($diff)->toBe([]);
});

it('finds text without accents and by word prefix', function () {
    [$user, $subject] = docSearchScene();
    $pl = docSearchDoc($subject, 'Recepta', [DocumentTextFixtures::POLISH]);
    $en = docSearchDoc($subject, 'Instructions', [DocumentTextFixtures::ENGLISH]);

    expect(docSearchIds(docSearchPost($this, $user, 'lukasz')))->toBe([$pl->id])
        ->and(docSearchIds(docSearchPost($this, $user, 'ŁUKA')))->toBe([$pl->id])
        ->and(docSearchIds(docSearchPost($this, $user, 'paracetamolu')))->toBe([$en->id]);
});

it('finds base words from Slovak and Polish endings', function (string $query, string $stored) {
    [$user, $subject] = docSearchScene();
    $doc = docSearchDoc($subject, 'Report', ['Zápis: '.$stored.' dnes']);
    docSearchDoc($subject, 'Other', ['Nothing to see here']);

    expect(docSearchIds(docSearchPost($this, $user, $query)))->toBe([$doc->id]);
})->with([
    'ending -u' => ['paracetamolu', 'paracetamol'],
    'ending -om' => ['antibiotikom', 'antibiotik'],
    'ending -ov' => ['pacientov', 'pacient'],
    'ending -ie' => ['vyšetrenie', 'vyšetrenia'],
    'ending -ach' => ['pacjentach', 'pacjenta'],
    'ending -ego' => ['przewlekłego', 'przewlekłe'],
]);

it('requires every word of the query', function () {
    [$user, $subject] = docSearchScene();
    $both = docSearchDoc($subject, 'Both', ['Penicilín a žltačka']);
    docSearchDoc($subject, 'One', ['Penicilín 500 mg']);
    docSearchDoc($subject, 'Other', ['Žltačka sama']);

    expect(docSearchIds(docSearchPost($this, $user, 'Penicilín žltačka')))->toBe([$both->id]);
});

it('finds a document without text by its title only, with the plain fields', function () {
    [$user, $subject] = docSearchScene();
    $doc = docSearchDoc($subject, 'Prepúšťacia správa');

    $item = docSearchPost($this, $user, 'sprava')->assertOk()->json('items.0');

    expect($item['documentId'])->toBe($doc->id)
        ->and($item['personId'])->toBe($subject->id)->and($item['personName'])->toBe('Search Person')
        ->and($item['title'])->toBe('Prepúšťacia správa')
        ->and($item['textComplete'])->toBeTrue()->and($item['page'])->toBeNull()->and($item['snippets'])->toBe([])
        ->and($item['pagesRead'])->toBeNull()->and($item['pagesTotal'])->toBeNull()
        ->and($item['visitId'])->toBeNull()->and($item['visitName'])->toBeNull()
        ->and($item['uploadedOn'])->toMatch('/^\d{4}-\d{2}-\d{2}$/');
});

it('returns plain segments with the hit page', function () {
    [$user, $subject] = docSearchScene();
    docSearchDoc($subject, 'Report', ['First page', 'Second page with <b>Žltačka</b> here']);

    $item = docSearchPost($this, $user, 'zltacka')->assertOk()->json('items.0');

    expect($item['page'])->toBe(2)
        ->and($item['snippets'])->toHaveCount(1)
        ->and(collect($item['snippets'][0])->firstWhere('hit', true))->toBe(['text' => 'Žltačka', 'hit' => true])
        ->and(implode('', array_column($item['snippets'][0], 'text')))->toContain('<b>')
        ->and($item['textComplete'])->toBeTrue()->and($item['pagesRead'])->toBe(2)->and($item['pagesTotal'])->toBe(2);
});

it('ranks a text match above a newer title-only match', function () {
    [$user, $subject] = docSearchScene();
    $text = docSearchDoc($subject, 'Scan A', ['Contains the word anamneza many times anamneza']);
    $title = docSearchDoc($subject, 'Anamneza', null, ['created_at' => now()->addMinute()]);

    expect(docSearchIds(docSearchPost($this, $user, 'anamneza')))->toBe([$text->id, $title->id]);
});

it('pages by twenty with a has-more flag and no total', function () {
    [$user, $subject] = docSearchScene();
    foreach (range(1, 25) as $i) {
        docSearchDoc($subject, 'Doc '.$i, ['pagingword '.$i]);
    }

    $first = docSearchPost($this, $user, 'pagingword')->assertOk();
    $second = docSearchPost($this, $user, 'pagingword', ['page' => 2])->assertOk();

    expect($first->json('items'))->toHaveCount(20)->and($first->json('hasMore'))->toBeTrue()->and($first->json('page'))->toBe(1)
        ->and($second->json('items'))->toHaveCount(5)->and($second->json('hasMore'))->toBeFalse()->and($second->json('page'))->toBe(2)
        ->and(array_keys($first->json()))->toBe(['items', 'hasMore', 'page', 'dropped'])
        ->and(array_intersect(docSearchIds($first), docSearchIds($second)))->toBe([]);
});

it('limits to one person and treats a malformed filter as an empty result without a query', function () {
    [$user, $subject] = docSearchScene();
    $other = MedicalFixtures::subject(['name' => 'Second'], [[$user, AccessLevel::Edit]]);
    $a = docSearchDoc($subject, 'A', ['sharedword']);
    $b = docSearchDoc($other, 'B', ['sharedword']);

    expect(docSearchIds(docSearchPost($this, $user, 'sharedword')))->toContain($a->id, $b->id)
        ->and(docSearchIds(docSearchPost($this, $user, 'sharedword', ['person' => $other->id])))->toBe([$b->id]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $response = docSearchPost($this, $user, 'sharedword', ['person' => 'not-a-uuid'])->assertOk();
    $medical = array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $sql) => str_contains($sql, 'medical_'));
    DB::disableQueryLog();

    expect($response->json())->toBe(['items' => [], 'hasMore' => false, 'page' => 1, 'dropped' => 0])->and($medical)->toBe([]);
});

it('answers a query without a usable word with an empty page, and operator characters are only text', function () {
    [$user, $subject] = docSearchScene();
    docSearchDoc($subject, 'Report', ['alpha beta']);

    foreach (['a', '!!', '&|!()<>:*', "' OR 1=1 --"] as $q) {
        $response = docSearchPost($this, $user, $q)->assertOk();
        expect($response->json('items'))->toBe([], $q)->and($response->json('hasMore'))->toBeFalse();
    }
    expect(docSearchIds(docSearchPost($this, $user, 'alpha & beta | !gamma')))->toBe([]);
    expect(docSearchIds(docSearchPost($this, $user, 'alpha:* <-> beta')))->toHaveCount(1);
});

it('validates the request shape', function () {
    [$user] = docSearchScene();

    $this->actingAs($user)->postJson('/health/search', [])->assertStatus(422);
    $this->actingAs($user)->postJson('/health/search', ['q' => str_repeat('a', 201)])->assertStatus(422);
    $this->actingAs($user)->postJson('/health/search', ['q' => 'abc', 'page' => 51])->assertStatus(422);
    $this->actingAs($user)->postJson('/health/search', ['q' => 'abc', 'page' => 0])->assertStatus(422);
    $this->actingAs($user)->postJson('/health/search', ['q' => "abc\xC3\x28"])->assertStatus(422);
    $this->actingAs($user)->postJson('/health/search', ['q' => 'abc', 'person' => 'x'])->assertOk();
});

it('hides an archived visit but shows a visible one with its doctor', function () {
    [$user, $subject] = docSearchScene();
    $visit = MedicalVisit::factory()->forSubject($subject)->create(['doctor' => 'Dr. Visible']);
    $archived = MedicalVisit::factory()->forSubject($subject)->create(['doctor' => 'Dr. Archived']);
    $a = docSearchDoc($subject, 'Attached', ['visitword'], ['visit_id' => $visit->id]);
    $b = docSearchDoc($subject, 'Detached', ['visitword'], ['visit_id' => $archived->id]);
    DB::table('medical_visits')->where('id', $archived->id)->update(['archived_at' => now()]);

    $items = collect(docSearchPost($this, $user, 'visitword')->assertOk()->json('items'))->keyBy('documentId');

    expect($items[$a->id]['visitId'])->toBe($visit->id)->and($items[$a->id]['visitName'])->toBe('Dr. Visible')
        ->and($items[$b->id]['visitId'])->toBeNull()->and($items[$b->id]['visitName'])->toBeNull();
});

it('reports a partial text', function () {
    [$user, $subject] = docSearchScene();
    $doc = MedicalDocument::factory()->forSubject($subject)->create(['title' => 'Long']);
    DocumentTextFixtures::save($doc, ['one partialword', 'two', 'three'], 8, false);

    $item = docSearchPost($this, $user, 'partialword')->assertOk()->json('items.0');

    expect($item['textComplete'])->toBeFalse()->and($item['pagesRead'])->toBe(3)->and($item['pagesTotal'])->toBe(8);
});

it('keeps the query out of the logs, the audit and the response headers', function () {
    [$user, $subject] = docSearchScene();
    docSearchDoc($subject, 'Report', ['unique']);
    $secret = 'Zxsecretquery';

    $response = docSearchPost($this, $user, $secret)->assertOk();
    $logs = '';
    foreach (glob(storage_path('logs/*')) ?: [] as $file) {
        $logs .= (string) file_get_contents($file);
    }
    $audit = json_encode(DB::table('audit_logs')->get()->all());

    expect(str_contains(strtolower($logs), strtolower($secret)))->toBeFalse()
        ->and(str_contains(strtolower((string) $audit), strtolower($secret)))->toBeFalse()
        ->and(str_contains(strtolower((string) $response->headers), strtolower($secret)))->toBeFalse()
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

it('is audited as a read, not a write', function () {
    [$user] = docSearchScene();
    $before = DB::table('audit_logs')->max('id') ?? 0;

    docSearchPost($this, $user, 'anything')->assertOk();

    expect(DB::table('audit_logs')->where('id', '>', $before)->pluck('event')->all())->toBe(['health_read']);
});

it('answers a member without Health access with 403 and a guest with a redirect', function () {
    $nobody = User::factory()->create();

    $this->actingAs($nobody)->postJson('/health/search', ['q' => 'abc'])->assertForbidden();
    auth()->logout();
    $this->postJson('/health/search', ['q' => 'abc'])->assertUnauthorized();
});

it('throttles the search', function () {
    [$user] = docSearchScene();

    foreach (range(1, 30) as $i) {
        docSearchPost($this, $user, 'zz')->assertOk();
    }
    docSearchPost($this, $user, 'zz')->assertStatus(429);
});

/** @return array<string, mixed> the Inertia props of the person page */
function docSearchPageProps(object $test, User $user, MedicalSubject $subject): array
{
    $version = MedicalPrivacy::inertiaVersion();

    return $test->actingAs($user)->get("/health/people/{$subject->id}", ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->assertOk()->json('props');
}

it('tells the person page which documents have text and whether search is on, never the text', function () {
    [$user, $subject] = docSearchScene();
    $withText = docSearchDoc($subject, 'With', [MedicalPrivacy::CANARY.' text body']);
    $without = docSearchDoc($subject, 'Without');

    $props = docSearchPageProps($this, $user, $subject);
    $flags = collect($props['documents'])->pluck('hasText', 'id')->all();

    expect($flags)->toEqual([$withText->id => true, $without->id => false])
        ->and($props['search'])->toBe(['enabled' => true, 'textReliability' => 'reliable'])
        ->and(json_encode($props))->not->toContain(MedicalPrivacy::CANARY);
});

it('turns the search box on only with the flag or a visible text, and reads reliability from config', function () {
    [$user, $subject] = docSearchScene();
    docSearchDoc($subject, 'Plain');

    config(['ai.ocr.enabled' => false]);
    expect(docSearchPageProps($this, $user, $subject)['search'])->toBe(['enabled' => false, 'textReliability' => 'reliable']);

    config(['ai.ocr.enabled' => true, 'ai.ocr.text_reliability' => 'reliable']);
    expect(docSearchPageProps($this, $user, $subject)['search'])->toBe(['enabled' => true, 'textReliability' => 'reliable']);

    config(['ai.ocr.enabled' => false, 'ai.ocr.text_reliability' => 'nonsense']);
    $hidden = MedicalFixtures::subject(['name' => 'Elsewhere'], [[User::factory()->create(), AccessLevel::Edit]]);
    docSearchDoc($hidden, 'Hidden', ['hidden text']);
    expect(docSearchPageProps($this, $user, $subject)['search'])->toBe(['enabled' => false, 'textReliability' => 'uncertain']);

    docSearchDoc($subject, 'Texted', ['visible text']);
    expect(docSearchPageProps($this, $user, $subject)['search'])->toBe(['enabled' => true, 'textReliability' => 'uncertain']);
});
