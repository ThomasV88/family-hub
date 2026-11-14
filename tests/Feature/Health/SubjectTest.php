<?php

use App\Access\AccessLevel;
use App\Audit\AuditLogger;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Viewer;
use App\Medical\Visibility;
use App\Models\AuditEntry;
use App\Models\MedicalSubject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        expect($bad(['sex' => 'x']))->toThrow(Illuminate\Database\QueryException::class);
        expect($bad(['blood_type' => 'C+']))->toThrow(Illuminate\Database\QueryException::class);

        $user = User::factory()->create();
        MedicalSubject::factory()->linkedTo($user)->create();
        expect(fn () => DB::transaction(fn () => MedicalSubject::factory()->linkedTo($user)->create()))->toThrow(Illuminate\Database\QueryException::class);

        $subject = MedicalSubject::factory()->create();
        expect(fn () => DB::transaction(fn () => DB::table('medical_subject_members')->insert([
            'subject_id' => $subject->id, 'user_id' => $user->id, 'level' => 3,
        ])))->toThrow(Illuminate\Database\QueryException::class);
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
        expect(fn () => Viewer::for($admin))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
        $linked = User::factory()->create();
        MedicalSubject::factory()->linkedTo($linked)->create();
        expect(fn () => Viewer::for($linked))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
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
            ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    });
});
