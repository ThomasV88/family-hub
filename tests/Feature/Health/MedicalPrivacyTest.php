<?php

use App\Access\AccessLevel;
use App\Medical\Viewer;
use App\Medical\Visibility;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Tests\Support\MedicalPrivacy;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

it('serves the viewer identical surfaces with and without hidden-person data, free of canaries', function () {
    $ctx = MedicalPrivacy::scenario();
    MedicalPrivacy::seedVisible($ctx);
    $viewer = $ctx['viewer'];

    $names = collect(MedicalPrivacy::surfaces())->map->getName()->all();
    expect($names)->toContain('health.index', 'health.people.show', 'home', 'settings.members');

    $before = MedicalPrivacy::capture($this, $viewer, $ctx);
    MedicalPrivacy::seedHidden($ctx);
    expect($ctx['hidden'])->toHaveKeys(['person', 'own_off']);
    $after = MedicalPrivacy::capture($this, $viewer, $ctx);

    expect($after)->toEqual($before);
    // Guard against a vacuous pass: the Inertia captures really are rendered pages, including the panel variant.
    expect(collect($after)->filter(fn ($b, $k) => str_starts_with($k, 'inertia ') && str_contains($k, 'panel=new-person') && str_starts_with($b, '200')))->not->toBeEmpty();
    expect(collect($after)->keys()->filter(fn ($k) => str_contains($k, '/health/people/')))->not->toBeEmpty();

    foreach ($after as $key => $body) {
        $scan = MedicalPrivacy::scannable($body);
        foreach (MedicalPrivacy::CANARY_NEEDLES as $needle) {
            expect($scan)->not->toContain($needle, "canary '$needle' in $key");
        }
    }
});

it('puts the membership subquery in every medical statement a viewer request issues', function () {
    $ctx = MedicalPrivacy::scenario();
    MedicalPrivacy::seedVisible($ctx);
    MedicalPrivacy::seedHidden($ctx);

    DB::flushQueryLog();
    DB::enableQueryLog();
    MedicalPrivacy::capture($this, $ctx['viewer'], $ctx);
    $statements = collect(DB::getQueryLog())->pluck('query')->all();
    DB::disableQueryLog();

    $medical = array_filter($statements, fn ($sql) => str_contains($sql, 'medical_'));
    expect($medical)->not->toBeEmpty()
        ->and(MedicalPrivacy::unscopedReads($statements))->toBe([]);
});

it('matches the literal SQL Visibility generates and nothing weaker (helper self-test)', function () {
    $viewer = Viewer::for(MedicalPrivacy::scenario()['viewer']);
    $scope = Visibility::subjectIds($viewer)->toSql();
    $in = 'select * from "medical_visits" where "subject_id" in ('.$scope.')';

    expect(MedicalPrivacy::unscopedReads([$in]))->toBe([])
        ->and(MedicalPrivacy::hasMembershipPredicate($scope))->toBeTrue()
        ->and(MedicalPrivacy::unscopedReads(['select * from "medical_subjects" where "id" in (select "subject_id" from "medical_subject_members" where "user_id" = ? and "level" >= ?)']))->toBe([])
        ->and(MedicalPrivacy::unscopedReads(['select 1 from medical_visits v where exists (select 1 from medical_subject_members m where m.user_id = ? and m.subject_id = v.subject_id)']))->toBe([])
        ->and(MedicalPrivacy::unscopedReads(['select 1 from medical_visits v join medical_subject_members m on m.user_id = ? and m.subject_id = v.subject_id']))->toBe([])
        // The members branch without its user_id, with the own branch's user_id after the union, is not a scope.
        ->and(MedicalPrivacy::unscopedReads(['select * from "medical_visits" where "subject_id" in (select "subject_id" from "medical_subject_members" where "level" >= ? union select "id" from "medical_subjects" where "user_id" = ?)']))->toHaveCount(1)
        // A bare mention, another table's user_id or another column is not a scope.
        ->and(MedicalPrivacy::unscopedReads(['select * from "medical_visits" where "subject_id" in (select "subject_id" from "medical_subject_members")']))->toHaveCount(1)
        ->and(MedicalPrivacy::unscopedReads(['select * from "medical_visits" where "id" = ?']))->toHaveCount(1)
        ->and(MedicalPrivacy::unscopedReads(['select * from "medical_documents" d join "medical_subject_members" m on m.subject_id = d.subject_id where "level" = ?']))->toHaveCount(1)
        ->and(MedicalPrivacy::unscopedReads(['select * from "medical_visits" where exists (select 1 from "users" where "user_id" = ?) and "subject_id" in (select "subject_id" from "medical_subject_members")']))->toHaveCount(1);
});

it('classifies every medical table as scope-checked or declared', function () {
    $tables = collect(DB::select("select table_name from information_schema.tables where table_schema = current_schema() and table_name like 'medical\_%'"))
        ->pluck('table_name')->all();
    $owned = (new ReflectionClass(MedicalPrivacy::class))->getReflectionConstant('READ_TABLES')->getValue();

    expect($tables)->not->toBeEmpty()
        ->and(array_keys(MedicalPrivacy::FAMILY_WIDE_TABLES))->toBe(['medical_subject_members']);
    foreach ($tables as $table) {
        expect(in_array($table, $owned, true) || array_key_exists($table, MedicalPrivacy::FAMILY_WIDE_TABLES))
            ->toBeTrue("medical table $table is neither scope-checked nor declared");
    }
    expect(array_intersect($owned, array_keys(MedicalPrivacy::FAMILY_WIDE_TABLES)))->toBe([]);
});

it('reports a missing provider with route and parameter name', function () {
    $route = new Route('GET', '/health/probe/{nosuchparam}', []);
    $route->name('health.probe');

    expect(fn () => MedicalPrivacy::urls($route, []))
        ->toThrow(RuntimeException::class, 'health.probe');
});

it('keeps the viewer scenario at Edit so the differential runs the write-capable pages', function () {
    $ctx = MedicalPrivacy::scenario();
    expect(Viewer::for($ctx['viewer'])->level)->toBe(AccessLevel::Edit);
});
