<?php

use App\Access\AccessLevel;
use App\Medical\Queries\VisitQuery;
use App\Medical\Specialty;
use App\Medical\Viewer;
use App\Models\MedicalSubject;
use App\Models\MedicalVisit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

const VISIT_BODY = ['visited_on' => '2026-01-10', 'doctor' => 'Dr. Ada', 'specialty_key' => 'cardiology', 'diagnosis' => 'Fine', 'notes' => 'Rest'];

/** @return array{0: User, 1: MedicalSubject} an edit member and a person they can change */
function visitEditor(): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user);

    return [$user, MedicalFixtures::subject([], [[$user, AccessLevel::Edit]])];
}

function visitInsert(array $row): void
{
    DB::transaction(fn () => DB::table('medical_visits')->insert($row + [
        'id' => (string) Str::uuid(), 'subject_id' => MedicalSubject::factory()->create()->id, 'visited_on' => '2026-01-01',
        'specialty_key' => 'cardiology', 'created_at' => now(), 'updated_at' => now(),
    ]));
}

describe('schema and taxonomy', function () {
    it('refuses combinations the CHECK constraints forbid', function () {
        expect(fn () => visitInsert(['specialty_key' => 'other']))->toThrow(QueryException::class);
        expect(fn () => visitInsert(['specialty_key' => 'cardiology', 'specialty_other' => 'x']))->toThrow(QueryException::class);
        expect(fn () => visitInsert(['source' => 'x']))->toThrow(QueryException::class);
        expect(fn () => visitInsert(['source' => 'manual', 'verified_at' => now()]))->toThrow(QueryException::class);
        visitInsert(['source' => 'ai', 'verified_at' => null]);
        visitInsert(['specialty_key' => 'other', 'specialty_other' => 'Sleep clinic']);
        expect(MedicalVisit::count())->toBe(2);
    });

    it('has 30 keys with a label in every locale', function () {
        expect(Specialty::cases())->toHaveCount(30)->and(Specialty::TAXONOMY_VERSION)->toBe(1);
        expect(Specialty::tryFrom('other'))->toBe(Specialty::Other);
        foreach (['en', 'sk', 'pl'] as $locale) {
            foreach (Specialty::cases() as $case) {
                expect($case->label($locale))->not->toBe('health.specialty.'.$case->value)->not->toBe('');
            }
        }
    });
});

describe('write', function () {
    it('creates, updates and deletes a visit as an edit member', function () {
        [$user, $person] = visitEditor();

        $response = $this->actingAs($user)->post("/health/people/{$person->id}/visits", VISIT_BODY);
        $response->assertRedirect("/health/people/{$person->id}")->assertSessionHas('toast.key', 'health.visit.saved');
        $row = DB::table('medical_visits')->first();
        expect($row->source)->toBe('manual')->and($row->verified_at)->toBeNull()->and($row->subject_id)->toBe($person->id)->and($row->created_by)->toBe($user->id);

        $this->actingAs($user)->patch("/health/people/{$person->id}/visits/{$row->id}", ['specialty_key' => 'other', 'specialty_other' => 'Sleep clinic'] + VISIT_BODY)
            ->assertRedirect("/health/people/{$person->id}");
        $after = DB::table('medical_visits')->first();
        expect($after->specialty_key)->toBe('other')->and($after->specialty_other)->toBe('Sleep clinic');

        $this->actingAs($user)->delete("/health/people/{$person->id}/visits/{$row->id}")->assertSessionHas('toast.key', 'health.visit.deleted');
        expect(DB::table('medical_visits')->count())->toBe(0);
    });

    it('refuses invalid bodies for a visible person', function (array $override, string $field) {
        [$user, $person] = visitEditor();
        $this->actingAs($user)->post("/health/people/{$person->id}/visits", $override + VISIT_BODY)->assertSessionHasErrors($field);
        expect(DB::table('medical_visits')->count())->toBe(0);
    })->with([
        'unknown specialty' => [['specialty_key' => 'x'], 'specialty_key'],
        'other without text' => [['specialty_key' => 'other'], 'specialty_other'],
        'text without other' => [['specialty_other' => 'x'], 'specialty_other'],
        'missing date' => [['visited_on' => ''], 'visited_on'],
        'future date' => [['visited_on' => '2999-01-01'], 'visited_on'],
        'long doctor' => [['doctor' => str_repeat('a', 121)], 'doctor'],
        'long notes' => [['notes' => str_repeat('a', 4001)], 'notes'],
        'bad utf8' => [['doctor' => "\xB1\x31"], 'doctor'],
    ]);

    it('uses the specialty message key for other without text', function () {
        [$user, $person] = visitEditor();
        $this->actingAs($user)->post("/health/people/{$person->id}/visits", ['specialty_key' => 'other'] + VISIT_BODY)
            ->assertSessionHasErrors(['specialty_other' => 'health.visit.err_specialty_other']);
    });

    it('does not let a client choose provenance', function () {
        [$user, $person] = visitEditor();
        $this->actingAs($user)->post("/health/people/{$person->id}/visits", VISIT_BODY + ['source' => 'ai', 'verified_at' => now()->toDateTimeString()]);
        expect(DB::table('medical_visits')->first())->source->toBe('manual')->verified_at->toBeNull();
    });

    it('scopes the update and delete statements by membership', function () {
        [$user, $person] = visitEditor();
        $visit = MedicalVisit::factory()->create(['subject_id' => $person->id]);
        $viewer = Viewer::for($user);
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });

        app(VisitQuery::class)->update($viewer, $person, $visit->id, VISIT_BODY);
        app(VisitQuery::class)->delete($viewer, $person, $visit->id);

        $writes = array_values(array_filter($queries, fn ($s) => preg_match('/^(update|delete)/i', $s)));
        expect($writes)->toHaveCount(2);
        foreach ($writes as $sql) {
            expect($sql)->toContain('medical_subject_members')->toContain('"user_id" = ?');
        }
    });
});

describe('identical 403', function () {
    it('answers hidden, unknown, malformed, mismatched and view-only targets the same and changes nothing', function () {
        [$user, $person] = visitEditor();
        $viewOnly = MedicalFixtures::subject([], [[$user, AccessLevel::View]]);
        $stranger = User::factory()->create();
        $hidden = MedicalFixtures::subject([], [[$stranger, AccessLevel::Edit]]);
        $ownOff = MedicalSubject::factory()->linkedTo(User::factory()->create(), false)->create();
        $hiddenVisit = MedicalVisit::factory()->create(['subject_id' => $hidden->id, 'doctor' => 'ZxCanaryMed']);
        $viewVisit = MedicalVisit::factory()->create(['subject_id' => $viewOnly->id, 'doctor' => 'ZxCanaryMed']);
        $before = DB::table('medical_visits')->orderBy('id')->get()->toArray();
        $random = (string) Str::uuid();

        $targets = [
            ['post', "/health/people/{$hidden->id}/visits"],
            ['post', "/health/people/{$ownOff->id}/visits"],
            ['post', "/health/people/{$random}/visits"],
            ['post', '/health/people/abc/visits'],
            ['post', "/health/people/{$viewOnly->id}/visits"],
            ['patch', "/health/people/{$hidden->id}/visits/{$hiddenVisit->id}"],
            ['patch', "/health/people/{$person->id}/visits/{$hiddenVisit->id}"],
            ['patch', "/health/people/{$person->id}/visits/{$random}"],
            ['patch', "/health/people/{$person->id}/visits/abc"],
            ['patch', "/health/people/{$viewOnly->id}/visits/{$viewVisit->id}"],
            ['delete', "/health/people/{$hidden->id}/visits/{$hiddenVisit->id}"],
            ['delete', "/health/people/{$person->id}/visits/{$hiddenVisit->id}"],
            ['delete', "/health/people/{$person->id}/visits/abc"],
            ['delete', "/health/people/{$viewOnly->id}/visits/{$viewVisit->id}"],
            ['delete', "/health/people/{$random}/visits/{$random}"],
        ];
        $reference = null;
        foreach ($targets as [$method, $url]) {
            $response = $this->actingAs($user)->{$method}($url, $method === 'delete' ? [] : VISIT_BODY);
            $response->assertForbidden();
            $page = $response->viewData('page');
            expect(json_encode($page))->not->toContain('ZxCanaryMed');
            $reference ??= ['component' => $page['component'], 'props' => $page['props']];
            expect(['component' => $page['component'], 'props' => $page['props']])->toEqual($reference, "$method $url");
        }
        expect(DB::table('medical_visits')->orderBy('id')->get()->toArray())->toEqual($before);
    });

    it('answers an invalid body to a hidden, unknown or malformed person like a valid one (authorize first)', function () {
        [$user, $person] = visitEditor();
        $viewOnly = MedicalFixtures::subject([], [[$user, AccessLevel::View]]);
        $hidden = MedicalFixtures::subject([], [[User::factory()->create(), AccessLevel::Edit]]);
        $ownOff = MedicalSubject::factory()->linkedTo(User::factory()->create(), false)->create();
        $visit = MedicalVisit::factory()->create(['subject_id' => $person->id]);
        $random = (string) Str::uuid();
        $invalid = ['visited_on' => '', 'specialty_key' => 'x'];

        $baseline = $this->actingAs($user)->post("/health/people/{$random}/visits", VISIT_BODY);
        $baseline->assertForbidden();
        $reference = $baseline->viewData('page');
        foreach ([$hidden->id, $ownOff->id, $random, 'abc', $viewOnly->id] as $id) {
            foreach ([['post', "/health/people/{$id}/visits"], ['patch', "/health/people/{$id}/visits/{$visit->id}"]] as [$method, $url]) {
                $response = $this->actingAs($user)->{$method}($url, $invalid);
                $response->assertForbidden()->assertSessionHasNoErrors();
                $page = $response->viewData('page');
                expect(['component' => $page['component'], 'props' => $page['props']])->toEqual(['component' => $reference['component'], 'props' => $reference['props']], "$method $url");
            }
        }
    });
});
