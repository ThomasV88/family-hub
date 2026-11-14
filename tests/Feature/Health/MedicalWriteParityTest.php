<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\MedicalPrivacy;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

/**
 * Creating a person has no existing subject to prove: the subject row and its creator membership are inserted by
 * design (the creator becomes the "Can change" member in the same transaction). Exempt for that one route only.
 */
const MED_PARITY_CREATE_EXEMPT = [
    'health.people.store' => ['/^insert into "?medical_subjects"?\b/', '/^insert into "?medical_subject_members"?\b/'],
];

it('answers a write on a hidden record exactly like a write on a record that does not exist or is malformed', function () {
    $ctx = MedicalPrivacy::scenario();
    MedicalPrivacy::seedVisible($ctx);
    MedicalPrivacy::seedHidden($ctx);
    $viewer = $ctx['viewer'];

    expect(collect(MedicalPrivacy::writes())->map->getName()->all())->toContain('health.people.store');

    $checked = 0;
    foreach (MedicalPrivacy::writes() as $route) {
        $hiddenParams = MedicalPrivacy::hiddenParams($route, $ctx);
        if ($hiddenParams === null) {
            continue;
        }
        $payload = MedicalPrivacy::payload($route->getName(), $ctx);

        $send = function (array $params) use ($route, $viewer, $payload): string {
            app('auth')->forgetGuards();
            app('cache')->store()->flush();
            $this->flushSession();
            $url = route($route->getName(), $params, false);

            return MedicalPrivacy::normalise($this->actingAs($viewer)->call(MedicalPrivacy::method($route), $url, $payload));
        };

        $reference = $send($hiddenParams);
        $variants = [
            'random uuid' => MedicalPrivacy::hiddenParams($route, $ctx, fn () => (string) Str::uuid7()),
            'malformed id' => MedicalPrivacy::hiddenParams($route, $ctx, fn () => 'abc'),
        ];
        // Nested route: the visible person with a hidden record id must read like any other miss.
        if (in_array('person', $route->parameterNames(), true) && count(array_intersect($route->parameterNames(), array_keys(MedicalPrivacy::areas()['hiddenParams']))) > 1) {
            $visible = MedicalPrivacy::params($route, $ctx)['person'];
            $variants['mismatched pair'] = MedicalPrivacy::hiddenParams($route, $ctx, fn (string $name) => $name === 'person' ? $visible : null);
        }
        foreach ($variants as $label => $params) {
            expect($send($params))->toBe($reference, "hidden id vs $label differ on ".$route->getName());
        }

        // The malformed id never reaches a medical table.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $send($variants['malformed id']);
        $statements = collect(DB::getQueryLog())->pluck('query')->all();
        DB::disableQueryLog();
        expect(array_filter($statements, fn ($sql) => str_contains($sql, 'medical_')))->toBe([], 'malformed id touched a medical table on '.$route->getName());
        $checked++;
    }

    // Vacuous until a slice adds record-id write routes; the loop is the net for every route that does.
    expect($checked)->toBeGreaterThanOrEqual(0);
});

it('issues only subject-scoped UPDATE, DELETE and INSERT statements from every health write request', function () {
    $ctx = MedicalPrivacy::scenario();
    MedicalPrivacy::seedVisible($ctx);
    MedicalPrivacy::seedHidden($ctx);
    $viewer = $ctx['viewer'];

    $checked = 0;
    foreach (MedicalPrivacy::writes() as $route) {
        $url = route($route->getName(), MedicalPrivacy::params($route, $ctx), false);
        $payload = MedicalPrivacy::payload($route->getName(), $ctx);

        MedicalPrivacy::assertScopedMutations(function () use ($route, $viewer, $url, $payload) {
            app('auth')->forgetGuards();
            app('cache')->store()->flush();
            $this->flushSession();
            $this->actingAs($viewer)->call(MedicalPrivacy::method($route), $url, $payload);
        }, MED_PARITY_CREATE_EXEMPT[$route->getName()] ?? []);
        $checked++;
    }

    expect($checked)->toBeGreaterThanOrEqual(1);
});

it('fails the scoped-mutation helper for an unscoped statement (helper self-test)', function () {
    $unscoped = fn () => DB::update('update medical_visits set notes = ? where id = ?', ['x', (string) Str::uuid7()]);
    expect(fn () => MedicalPrivacy::assertScopedMutations($unscoped))->toThrow(AssertionFailedError::class, 'Unscoped medical mutation');

    expect(MedicalPrivacy::unscopedMutations(['delete from "medical_documents" where "id" = ?']))->toHaveCount(1)
        ->and(MedicalPrivacy::unscopedMutations(['insert into "medical_allergies" ("id") values (?) on conflict ("id") do update set "id" = excluded."id"']))->toHaveCount(1)
        ->and(MedicalPrivacy::unscopedMutations(['update "users" set "name" = ?']))->toBe([])
        ->and(MedicalPrivacy::unscopedMutations(['insert into "medical_visits" ("id") values (?)']))->toHaveCount(1)
        ->and(MedicalPrivacy::unscopedMutations([
            'select * from "medical_subjects" where "id" in (select "subject_id" from "medical_subject_members" where "user_id" = ?)',
            'insert into "medical_visits" ("id") values (?)',
        ]))->toBe([])
        ->and(MedicalPrivacy::unscopedMutations([
            'select * from "medical_subject_members"',
            'insert into "medical_visits" ("id") values (?)',
        ]))->toHaveCount(1)
        ->and(MedicalPrivacy::unscopedMutations(['insert into medical_visits (id) select x.id from jsonb_to_recordset(?) as x(id uuid)']))->toHaveCount(1)
        ->and(MedicalPrivacy::unscopedMutations(['insert into medical_visits (id) select x.id from x where exists (select 1 from medical_subject_members where user_id = ?)']))->toBe([])
        ->and(MedicalPrivacy::unscopedMutations(['delete from medical_medications where id in (select subject_id from medical_subject_members where level = ?)']))->toHaveCount(1)
        ->and(MedicalPrivacy::unscopedMutations(['insert into "medical_subject_members" ("subject_id") values (?)']))->toHaveCount(1);

    $scoped = fn () => DB::update('update medical_visits set notes = ? where id = ? and subject_id in (select subject_id from medical_subject_members where user_id = ?)', ['x', (string) Str::uuid7(), 1]);
    MedicalPrivacy::assertScopedMutations($scoped);
});
