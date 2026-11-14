<?php

use App\Access\AccessLevel;
use App\Medical\Queries\AllergyQuery;
use App\Medical\Viewer;
use App\Models\MedicalAllergy;
use App\Models\MedicalSubject;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

const ALLERGY_BODY = ['substance' => 'Peanuts', 'reaction' => 'Hives', 'severity' => 'severe'];

/** @return array{0: User, 1: MedicalSubject} an edit member and a person they can change */
function allergyEditor(): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user);

    return [$user, MedicalFixtures::subject([], [[$user, AccessLevel::Edit]])];
}

function allergyInsert(array $row): void
{
    DB::transaction(fn () => DB::table('medical_allergies')->insert($row + [
        'id' => (string) Str::uuid(), 'subject_id' => MedicalSubject::factory()->create()->id, 'substance' => 'x',
        'severity' => 'mild', 'created_at' => now(), 'updated_at' => now(),
    ]));
}

describe('schema', function () {
    it('refuses a bad severity, a manual row with verified_at and a bad source', function () {
        expect(fn () => allergyInsert(['severity' => 'x']))->toThrow(QueryException::class);
        expect(fn () => allergyInsert(['source' => 'manual', 'verified_at' => now()]))->toThrow(QueryException::class);
        expect(fn () => allergyInsert(['source' => 'x']))->toThrow(QueryException::class);
        allergyInsert(['source' => 'ai', 'verified_at' => now(), 'severity' => 'unknown']);
        expect(MedicalAllergy::count())->toBe(1);
    });
});

describe('write', function () {
    it('creates, updates and deletes an allergy as an edit member', function () {
        [$user, $person] = allergyEditor();

        $this->actingAs($user)->post("/health/people/{$person->id}/allergies", ALLERGY_BODY)
            ->assertRedirect("/health/people/{$person->id}")->assertSessionHas('toast.key', 'health.allergy.saved');
        $row = DB::table('medical_allergies')->first();
        expect($row->source)->toBe('manual')->and($row->verified_at)->toBeNull()->and($row->created_by)->toBe($user->id)->and($row->subject_id)->toBe($person->id);

        $this->actingAs($user)->patch("/health/people/{$person->id}/allergies/{$row->id}", ['severity' => 'mild', 'reaction' => null] + ALLERGY_BODY)
            ->assertRedirect("/health/people/{$person->id}");
        expect(DB::table('medical_allergies')->first())->severity->toBe('mild')->reaction->toBeNull();

        $this->actingAs($user)->delete("/health/people/{$person->id}/allergies/{$row->id}")->assertSessionHas('toast.key', 'health.allergy.deleted');
        expect(DB::table('medical_allergies')->count())->toBe(0);
    });

    it('refuses invalid bodies for a visible person with the message keys', function (array $override, string $field, string $key) {
        [$user, $person] = allergyEditor();
        $this->actingAs($user)->post("/health/people/{$person->id}/allergies", $override + ALLERGY_BODY)->assertSessionHasErrors([$field => $key]);
        expect(DB::table('medical_allergies')->count())->toBe(0);
    })->with([
        'unknown severity' => [['severity' => 'unknown'], 'severity', 'health.allergy.err_severity'],
        'missing severity' => [['severity' => ''], 'severity', 'health.allergy.err_severity'],
        'empty substance' => [['substance' => ''], 'substance', 'health.allergy.err_substance'],
        'long substance' => [['substance' => str_repeat('a', 121)], 'substance', 'health.allergy.err_substance'],
        'bad utf8' => [['substance' => "\xB1\x31"], 'substance', 'health.allergy.err_substance'],
    ]);

    it('does not let a client choose provenance', function () {
        [$user, $person] = allergyEditor();
        $this->actingAs($user)->post("/health/people/{$person->id}/allergies", ALLERGY_BODY + ['source' => 'ai', 'verified_at' => now()->toDateTimeString()]);
        expect(DB::table('medical_allergies')->first())->source->toBe('manual')->verified_at->toBeNull();
    });

    it('scopes the update and delete statements by membership', function () {
        [$user, $person] = allergyEditor();
        $allergy = MedicalAllergy::factory()->forSubject($person)->create();
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });

        app(AllergyQuery::class)->update(Viewer::for($user), $person, $allergy->id, ALLERGY_BODY);
        app(AllergyQuery::class)->delete(Viewer::for($user), $person, $allergy->id);

        $writes = array_values(array_filter($queries, fn ($s) => preg_match('/^(update|delete)/i', $s)));
        expect($writes)->toHaveCount(2);
        foreach ($writes as $sql) {
            expect($sql)->toContain('medical_subject_members')->toContain('"user_id" = ?');
        }
    });

    it('lists severe first, then moderate, mild and unknown, by substance', function () {
        [$user, $person] = allergyEditor();
        foreach ([['mild', 'Pollen'], ['severe', 'Wasp'], ['unknown', 'Dust'], ['moderate', 'Latex'], ['severe', 'bees']] as [$sev, $name]) {
            MedicalAllergy::factory()->forSubject($person)->severity($sev)->create(['substance' => $name]);
        }
        $list = app(AllergyQuery::class)->forSubject(Viewer::for($user), $person)->pluck('substance')->all();
        expect($list)->toBe(['bees', 'Wasp', 'Latex', 'Pollen', 'Dust']);
    });
});

describe('identical 403', function () {
    it('answers hidden, unknown, malformed, mismatched and view-only targets the same and changes nothing', function () {
        [$user, $person] = allergyEditor();
        $viewOnly = MedicalFixtures::subject([], [[$user, AccessLevel::View]]);
        $hidden = MedicalFixtures::subject([], [[User::factory()->create(), AccessLevel::Edit]]);
        $ownOff = MedicalSubject::factory()->linkedTo(User::factory()->create(), false)->create();
        $hiddenRow = MedicalAllergy::factory()->create(['subject_id' => $hidden->id, 'substance' => 'ZxCanaryMed']);
        $viewRow = MedicalAllergy::factory()->create(['subject_id' => $viewOnly->id, 'substance' => 'ZxCanaryMed']);
        $before = DB::table('medical_allergies')->orderBy('id')->get()->toArray();
        $random = (string) Str::uuid();

        $targets = [
            ['post', "/health/people/{$hidden->id}/allergies"],
            ['post', "/health/people/{$ownOff->id}/allergies"],
            ['post', "/health/people/{$random}/allergies"],
            ['post', '/health/people/abc/allergies'],
            ['post', "/health/people/{$viewOnly->id}/allergies"],
            ['patch', "/health/people/{$hidden->id}/allergies/{$hiddenRow->id}"],
            ['patch', "/health/people/{$person->id}/allergies/{$hiddenRow->id}"],
            ['patch', "/health/people/{$person->id}/allergies/{$random}"],
            ['patch', "/health/people/{$person->id}/allergies/abc"],
            ['patch', "/health/people/{$viewOnly->id}/allergies/{$viewRow->id}"],
            ['delete', "/health/people/{$hidden->id}/allergies/{$hiddenRow->id}"],
            ['delete', "/health/people/{$person->id}/allergies/{$hiddenRow->id}"],
            ['delete', "/health/people/{$person->id}/allergies/abc"],
            ['delete', "/health/people/{$viewOnly->id}/allergies/{$viewRow->id}"],
            ['delete', "/health/people/{$random}/allergies/{$random}"],
        ];
        $reference = null;
        foreach ($targets as [$method, $url]) {
            $response = $this->actingAs($user)->{$method}($url, $method === 'delete' ? [] : ALLERGY_BODY);
            $response->assertForbidden();
            $page = $response->viewData('page');
            expect(json_encode($page))->not->toContain('ZxCanaryMed');
            $reference ??= ['component' => $page['component'], 'props' => $page['props']];
            expect(['component' => $page['component'], 'props' => $page['props']])->toEqual($reference, "$method $url");
        }
        expect(DB::table('medical_allergies')->orderBy('id')->get()->toArray())->toEqual($before);
    });

    it('answers an invalid body to a hidden, unknown or malformed person like a valid one (authorize first)', function () {
        [$user, $person] = allergyEditor();
        $viewOnly = MedicalFixtures::subject([], [[$user, AccessLevel::View]]);
        $hidden = MedicalFixtures::subject([], [[User::factory()->create(), AccessLevel::Edit]]);
        $ownOff = MedicalSubject::factory()->linkedTo(User::factory()->create(), false)->create();
        $row = MedicalAllergy::factory()->forSubject($person)->create();
        $random = (string) Str::uuid();
        $invalid = ['substance' => '', 'severity' => 'unknown'];

        $baseline = $this->actingAs($user)->post("/health/people/{$random}/allergies", ALLERGY_BODY);
        $baseline->assertForbidden();
        $reference = $baseline->viewData('page');
        foreach ([$hidden->id, $ownOff->id, $random, 'abc', $viewOnly->id] as $id) {
            foreach ([['post', "/health/people/{$id}/allergies"], ['patch', "/health/people/{$id}/allergies/{$row->id}"]] as [$method, $url]) {
                $response = $this->actingAs($user)->{$method}($url, $invalid);
                $response->assertForbidden()->assertSessionHasNoErrors();
                $page = $response->viewData('page');
                expect(['component' => $page['component'], 'props' => $page['props']])->toEqual(['component' => $reference['component'], 'props' => $reference['props']], "$method $url");
            }
        }
    });
});

describe('person page', function () {
    it('lists allergies severe first and hides other people allergies', function () {
        [$user, $person] = allergyEditor();
        MedicalAllergy::factory()->forSubject($person)->severity('mild')->create(['substance' => 'Pollen']);
        MedicalAllergy::factory()->forSubject($person)->severity('severe')->create(['substance' => 'Wasp', 'reaction' => null]);
        $hidden = MedicalFixtures::subject([], [[User::factory()->create(), AccessLevel::Edit]]);
        MedicalAllergy::factory()->forSubject($hidden)->create(['substance' => 'ZxCanaryMed']);

        $response = $this->actingAs($user)->get("/health/people/{$person->id}");
        $response->assertInertia(fn ($page) => $page->has('allergies', 2)
            ->where('allergies.0.substance', 'Wasp')->where('allergies.0.severity', 'severe')->where('allergies.0.reaction', null)
            ->where('allergies.1.substance', 'Pollen')->has('allergies.0.id'));
        expect(json_encode($response->viewData('page')))->not->toContain('ZxCanaryMed');
    });

    it('resolves allergy and new-allergy panels inside the person and aborts 403 otherwise', function () {
        [$user, $person] = allergyEditor();
        $mine = MedicalAllergy::factory()->forSubject($person)->create();
        $hidden = MedicalFixtures::subject([], [[User::factory()->create(), AccessLevel::Edit]]);
        $theirs = MedicalAllergy::factory()->forSubject($hidden)->create();
        $random = (string) Str::uuid();

        $this->actingAs($user)->get("/health/people/{$person->id}?panel=allergy&id={$mine->id}")
            ->assertInertia(fn ($page) => $page->where('panel', ['kind' => 'allergy', 'id' => $mine->id]));
        $this->actingAs($user)->get("/health/people/{$person->id}?panel=new-allergy")
            ->assertInertia(fn ($page) => $page->where('panel', ['kind' => 'new-allergy', 'id' => null]));

        $reference = $this->actingAs($user)->get("/health/people/{$hidden->id}")->assertForbidden()->viewData('page');
        foreach (["panel=allergy&id={$theirs->id}", "panel=allergy&id={$random}", 'panel=allergy&id=abc', 'panel=allergy', "panel=new-allergy&id={$mine->id}"] as $query) {
            $page = $this->actingAs($user)->get("/health/people/{$person->id}?{$query}")->assertForbidden()->viewData('page');
            expect($page['props'])->toEqual($reference['props'], $query);
        }
    });

    it('shows a view-only member allergies read-only and refuses new-allergy', function () {
        $user = User::factory()->create();
        MedicalFixtures::grantHealth($user, AccessLevel::View);
        $person = MedicalFixtures::subject([], [[$user, AccessLevel::View]]);
        $row = MedicalAllergy::factory()->forSubject($person)->create();

        $this->actingAs($user)->get("/health/people/{$person->id}?panel=allergy&id={$row->id}")
            ->assertInertia(fn ($page) => $page->where('person.canEdit', false)->has('allergies', 1)->where('panel.kind', 'allergy'));
        $this->actingAs($user)->get("/health/people/{$person->id}?panel=new-allergy")->assertForbidden();
    });
});
