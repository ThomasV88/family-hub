<?php

use App\Access\AccessLevel;
use App\Audit\AuditLogger;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Viewer;
use App\Medical\Visibility;
use App\Models\AuditEntry;
use App\Models\MedicalSubject;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\FailingAuditLogger;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

function viewerFor(User $user, AccessLevel $level = AccessLevel::Edit): Viewer
{
    MedicalFixtures::grantHealth($user, $level);

    return Viewer::for($user);
}

describe('schema', function () {
    it('refuses values outside the enums and a second profile for one user', function () {
        // Each refused statement runs in its own savepoint so the aborted Postgres transaction does not poison the test.
        $bad = fn (array $row) => fn () => DB::transaction(fn () => DB::table('medical_subjects')->insert(array_merge(
            ['id' => (string) Str::uuid(), 'name' => 'A', 'created_at' => now(), 'updated_at' => now()],
            $row,
        )));
        expect($bad(['sex' => 'x']))->toThrow(QueryException::class);
        expect($bad(['blood_type' => 'C+']))->toThrow(QueryException::class);

        $user = User::factory()->create();
        MedicalSubject::factory()->linkedTo($user)->create();
        expect(fn () => DB::transaction(fn () => MedicalSubject::factory()->linkedTo($user)->create()))->toThrow(QueryException::class);

        $subject = MedicalSubject::factory()->create();
        expect(fn () => DB::transaction(fn () => DB::table('medical_subject_members')->insert([
            'subject_id' => $subject->id, 'user_id' => $user->id, 'level' => 3,
        ])))->toThrow(QueryException::class);
    });

    it('uses random version 4 uuids', function () {
        $subject = MedicalSubject::factory()->create();
        expect($subject->id[14])->toBe('4');
    });
});

describe('visibility scope', function () {
    it('resolves the two branches and the Edit scope', function () {
        $v = User::factory()->create();
        $other = User::factory()->create();
        $a = MedicalSubject::factory()->withMember($v, AccessLevel::View)->create(['name' => 'Alpha']);
        $s = MedicalSubject::factory()->linkedTo($v)->create(['name' => 'Self']);
        $b = MedicalSubject::factory()->withMember($other)->create(['name' => 'Bravo']);
        $c = MedicalSubject::factory()->linkedTo($other, false)->create(['name' => 'Charlie']);

        $viewer = viewerFor($v);
        $ids = fn (AccessLevel $min) => DB::query()->fromSub(Visibility::subjectIds($viewer, $min), 'x')->pluck('subject_id')->all();

        expect($ids(AccessLevel::View))->toEqualCanonicalizing([$a->id, $s->id]);
        expect($ids(AccessLevel::Edit))->toBe([$s->id]);
        expect(DB::table('medical_subjects')->whereIn('id', Visibility::subjectIds($viewer))->count())->toBe(2);

        $query = app(SubjectQuery::class);
        expect($query->visible($viewer)->pluck('name')->all())->toBe(['Alpha', 'Self']);
        expect($query->findVisible($viewer, $b->id))->toBeNull();
        expect($query->findVisible($viewer, $c->id))->toBeNull();
        expect($query->findVisible($viewer, $a->id, AccessLevel::Edit))->toBeNull();
        expect($query->findVisible($viewer, $s->id, AccessLevel::Edit)?->id)->toBe($s->id);
    });

    it('hides an own profile whose self access is off', function () {
        $v = User::factory()->create();
        MedicalSubject::factory()->linkedTo($v, false)->create();

        expect(app(SubjectQuery::class)->visible(viewerFor($v)))->toHaveCount(0);
    });

    it('computes the effective level as the minimum of module and subject', function (AccessLevel $module, ?AccessLevel $member, bool $own, AccessLevel $expected) {
        $v = User::factory()->create();
        $subject = MedicalSubject::factory()->create($own ? ['user_id' => $v->id] : []);
        if ($member !== null) {
            DB::table('medical_subject_members')->insert(['subject_id' => $subject->id, 'user_id' => $v->id, 'level' => $member->value, 'created_at' => now()]);
        }

        expect(app(SubjectQuery::class)->levelFor(viewerFor($v, $module), $subject->fresh()))->toBe($expected);
    })->with([
        'module edit, member view' => [AccessLevel::Edit, AccessLevel::View, false, AccessLevel::View],
        'module view, member edit' => [AccessLevel::View, AccessLevel::Edit, false, AccessLevel::View],
        'module edit, own linked' => [AccessLevel::Edit, null, true, AccessLevel::Edit],
        'module view, own linked' => [AccessLevel::View, null, true, AccessLevel::View],
        'module edit, no relation' => [AccessLevel::Edit, null, false, AccessLevel::None],
    ]);

    it('has no admin branch', function () {
        $admin = User::factory()->admin()->create();
        $subject = MedicalSubject::factory()->withMember(User::factory()->create())->create();
        $viewer = viewerFor($admin);

        $query = app(SubjectQuery::class);
        expect($query->visible($viewer))->toHaveCount(0);
        expect($query->findVisible($viewer, $subject->id))->toBeNull();
    });

    it('refuses a viewer without the Health module', function () {
        $admin = User::factory()->admin()->create();
        expect(fn () => Viewer::for($admin))->toThrow(HttpException::class);
        $linked = User::factory()->create();
        MedicalSubject::factory()->linkedTo($linked)->create();
        expect(fn () => Viewer::for($linked))->toThrow(HttpException::class);
    });

    it('does not touch the database for a malformed id or an Edit lookup by a view-only module viewer', function () {
        $v = User::factory()->create();
        $subject = MedicalSubject::factory()->withMember($v)->create();
        $viewer = viewerFor($v, AccessLevel::View);
        $query = app(SubjectQuery::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        expect($query->findVisible($viewer, 'abc'))->toBeNull();
        expect($query->findVisible($viewer, $subject->id, AccessLevel::Edit))->toBeNull();
        expect(DB::getQueryLog())->toBe([]);
        DB::disableQueryLog();
    });

    it('queries the membership table with a bound user id', function () {
        $v = User::factory()->create();
        MedicalSubject::factory()->withMember($v)->create();
        $viewer = viewerFor($v);

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(SubjectQuery::class)->visible($viewer);
        $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        expect($sql)->toMatch('/medical_subject_members.*where.*user_id.* = \?/s')->and($sql)->toContain('union');
    });
});

describe('create', function () {
    it('creates the subject, the creator grant and one content-free audit row', function () {
        $user = User::factory()->create();
        $subject = app(SubjectQuery::class)->create(viewerFor($user), ['name' => 'Secret Name', 'sex' => 'female']);

        expect($subject->id[14])->toBe('4');
        $member = DB::table('medical_subject_members')->where('subject_id', $subject->id)->first();
        expect($member->user_id)->toBe($user->id)->and($member->level)->toBe(2)->and($member->granted_by)->toBe($user->id);
        $rows = AuditEntry::where('event', 'medical_subject_created')->get();
        expect($rows)->toHaveCount(1);
        expect($rows[0]->meta)->toBe(['subject' => $subject->id]);
        expect(json_encode($rows[0]->getAttributes()))->not->toContain('Secret');
    });

    it('rolls everything back when the audit write fails', function () {
        $user = User::factory()->create();
        $viewer = viewerFor($user);
        app()->instance(AuditLogger::class, new FailingAuditLogger);

        expect(fn () => app(SubjectQuery::class)->create($viewer, ['name' => 'X']))->toThrow(RuntimeException::class);
        expect(MedicalSubject::count())->toBe(0);
        expect(DB::table('medical_subject_members')->count())->toBe(0);
    });

    it('refuses a view-only module viewer', function () {
        $user = User::factory()->create();
        expect(fn () => app(SubjectQuery::class)->create(viewerFor($user, AccessLevel::View), ['name' => 'X']))
            ->toThrow(HttpException::class);
    });
});

/** @return array{component: string, props: array<string, mixed>} the Inertia page of an html response */
function inertiaPage($response): array
{
    return $response->viewData('page');
}

function healthRows(string $event): int
{
    return AuditEntry::where('event', $event)->count();
}

describe('routes', function () {
    it('redirects /health to the first visible person alphabetically, or shows the empty page', function () {
        $user = User::factory()->create();
        MedicalFixtures::grantHealth($user);
        $this->actingAs($user)->get('/health')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Health/Person')->where('person', null)->where('people', [])->where('canCreate', true));

        $zed = MedicalFixtures::subject(['name' => 'Zed'], [[$user, AccessLevel::View]]);
        $amy = MedicalFixtures::subject(['name' => 'amy'], [[$user, AccessLevel::View]]);
        $this->actingAs($user)->get('/health')->assertRedirect('/health/people/'.$amy->id);
        expect($zed->id)->not->toBe($amy->id);
    });

    it('answers hidden, unknown, malformed and mismatched panel ids with one identical 403 and no access_denied row', function () {
        $v = User::factory()->create();
        MedicalFixtures::grantHealth($v);
        $mine = MedicalFixtures::subject(['name' => 'Mine'], [[$v, AccessLevel::Edit]]);
        $hidden = MedicalFixtures::subject(['name' => 'Hidden canary'], [[User::factory()->create(), AccessLevel::Edit]]);
        $random = (string) Str::uuid();

        $urls = [
            "/health/people/{$hidden->id}",
            "/health/people/{$random}",
            '/health/people/abc',
            "/health/people/{$mine->id}?panel=new-person&id={$random}",
            "/health/people/{$mine->id}?panel=new-person&id={$hidden->id}",
        ];
        $baseline = null;
        foreach ($urls as $url) {
            $before = healthRows('health_read');
            $response = $this->actingAs($v)->get($url);
            $response->assertForbidden();
            $page = inertiaPage($response);
            expect($page['component'])->toBe('Errors/Forbidden');
            expect(json_encode($page))->not->toContain('canary');
            $baseline ??= $page;
            expect($page['props'])->toEqual($baseline['props'], $url);
            expect(healthRows('health_read') - $before)->toBe(1, $url);
        }
        expect(healthRows('access_denied'))->toBe(0);
    });

    it('answers a member without the Health grant with the same 403 shape', function () {
        $v = User::factory()->create();
        MedicalFixtures::grantHealth($v);
        $mine = MedicalFixtures::subject([], [[$v, AccessLevel::Edit]]);
        $none = User::factory()->create();
        $linked = User::factory()->create();
        $own = MedicalSubject::factory()->linkedTo($linked)->create();

        $reference = inertiaPage($this->actingAs($v)->get('/health/people/abc'));
        foreach ([[$none, "/health/people/{$mine->id}"], [$none, '/health/people/abc'], [$linked, "/health/people/{$own->id}"]] as [$user, $url]) {
            $response = $this->actingAs($user)->get($url);
            $response->assertForbidden();
            $page = inertiaPage($response);
            expect($page['component'])->toBe($reference['component']);
            expect(array_keys($page['props']))->toEqual(array_keys($reference['props']));
            expect($page['props'])->not->toHaveKeys(['people', 'person']);
        }
    });

    it('creates a person, grants the creator Can change and lands on the person page', function () {
        $user = User::factory()->create();
        MedicalFixtures::grantHealth($user);

        $response = $this->actingAs($user)->post('/health/people', [
            'name' => 'Ada Novak', 'birth_date' => '2015-03-04', 'sex' => 'female', 'blood_type' => 'AB-', 'notes' => 'Note',
        ]);
        $subject = MedicalSubject::firstOrFail();
        $response->assertRedirect('/health/people/'.$subject->id);
        $response->assertSessionHas('toast', ['key' => 'health.person.saved']);
        $row = AuditEntry::where('event', 'medical_subject_created')->firstOrFail();
        expect($row->meta)->toBe(['subject' => $subject->id]);
        expect(json_encode($row->getAttributes()))->not->toContain('Ada');

        $this->actingAs($user)->get('/health/people/'.$subject->id)->assertInertia(fn ($page) => $page
            ->component('Health/Person')
            ->where('person.name', 'Ada Novak')
            ->where('person.bloodType', 'AB-')
            ->where('person.canEdit', true)
            ->where('person.hasAccount', false)
            ->where('people.0.initials', 'AN')
            ->missing('person.user_id')
            ->missing('person.userId'));
    });

    it('refuses invalid input on create', function (array $data, string $field) {
        $user = User::factory()->create();
        MedicalFixtures::grantHealth($user);

        $this->actingAs($user)->post('/health/people', $data)->assertSessionHasErrors($field);
        expect(MedicalSubject::count())->toBe(0);
    })->with([
        'empty name' => [['name' => ''], 'name'],
        'long name' => [['name' => str_repeat('a', 121)], 'name'],
        'invalid utf8' => [['name' => "bad\xC3\x28"], 'name'],
        'future birth date' => [['name' => 'X', 'birth_date' => '2999-01-01'], 'birth_date'],
        'sex' => [['name' => 'X', 'sex' => 'x'], 'sex'],
        'blood type' => [['name' => 'X', 'blood_type' => 'C+'], 'blood_type'],
    ]);

    it('refuses creation to a Health view member', function () {
        $user = User::factory()->create();
        MedicalFixtures::grantHealth($user, AccessLevel::View);

        $this->actingAs($user)->post('/health/people', ['name' => 'X'])->assertForbidden();
        expect(MedicalSubject::count())->toBe(0);
    });

    it('lists only visible people and never leaks another person into the props', function () {
        $v = User::factory()->create();
        MedicalFixtures::grantHealth($v);
        $mine = MedicalFixtures::subject(['name' => 'Visible', 'notes' => 'n1'], [[$v, AccessLevel::View]]);
        MedicalFixtures::subject(['name' => 'Canary Other', 'notes' => 'canary-note'], [[User::factory()->create(), AccessLevel::Edit]]);

        $response = $this->actingAs($v)->get('/health/people/'.$mine->id);
        $response->assertInertia(fn ($page) => $page->where('people', fn ($p) => collect($p)->pluck('name')->all() === ['Visible'])->where('person.canEdit', false));
        expect(json_encode(inertiaPage($response)))->not->toContain('anary');
    });

    it('shows a child with self access off the same page as a family without people', function () {
        $child = User::factory()->create();
        MedicalFixtures::grantHealth($child);
        MedicalSubject::factory()->linkedTo($child, false)->create(['name' => 'Canary Child']);
        $lonely = User::factory()->create();
        MedicalFixtures::grantHealth($lonely);

        $pick = fn ($response) => array_intersect_key(inertiaPage($response)['props'], array_flip(['people', 'person', 'canCreate', 'panel']));
        $childPage = $this->actingAs($child)->get('/health');
        $childPage->assertOk();
        expect($pick($childPage))->toEqual($pick($this->actingAs($lonely)->get('/health')));
        expect(json_encode(inertiaPage($childPage)))->not->toContain('Canary');
    });

    it('shows a linked member their own profile while self access is on', function () {
        $user = User::factory()->create();
        MedicalFixtures::grantHealth($user);
        $own = MedicalSubject::factory()->linkedTo($user)->create(['name' => 'Me']);

        $this->actingAs($user)->get('/health')->assertRedirect('/health/people/'.$own->id);
        $this->actingAs($user)->get('/health/people/'.$own->id)->assertInertia(fn ($page) => $page->where('person.canEdit', true)->where('person.hasAccount', true));
    });

    it('ignores an unknown panel kind and the new-person panel for a view-only member', function () {
        $user = User::factory()->create();
        MedicalFixtures::grantHealth($user, AccessLevel::View);
        $subject = MedicalFixtures::subject([], [[$user, AccessLevel::View]]);

        $this->actingAs($user)->get("/health/people/{$subject->id}?panel=whatever&id=zzz")->assertOk()->assertInertia(fn ($page) => $page->where('panel', null));
        $this->actingAs($user)->get("/health/people/{$subject->id}?panel=new-person")->assertOk()->assertInertia(fn ($page) => $page->where('panel', null));
    });

    it('opens the new-person panel for a creator', function () {
        $user = User::factory()->create();
        MedicalFixtures::grantHealth($user);

        $this->actingAs($user)->get('/health?panel=new-person')->assertInertia(fn ($page) => $page->where('panel', ['kind' => 'new-person', 'id' => null]));
    });
});
