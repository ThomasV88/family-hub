<?php

use App\Access\AccessLevel;
use App\Medical\Queries\MedicationQuery;
use App\Medical\Viewer;
use App\Models\MedicalMedication;
use App\Models\MedicalSubject;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

const MEDICATION_BODY = ['name' => 'Ibuprofen', 'dose' => '200 mg', 'schedule' => 'Twice a day', 'started_on' => '2026-01-10', 'ended_on' => null, 'notes' => null];

/** @return array{0: User, 1: MedicalSubject} an edit member and a person they can change */
function medicationEditor(): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user);

    return [$user, MedicalFixtures::subject([], [[$user, AccessLevel::Edit]])];
}

function medicationInsert(array $row): void
{
    DB::transaction(fn () => DB::table('medical_medications')->insert($row + [
        'id' => (string) Str::uuid(), 'subject_id' => MedicalSubject::factory()->create()->id, 'name' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]));
}

describe('schema', function () {
    it('refuses an end before the start and a manual row with verified_at', function () {
        expect(fn () => medicationInsert(['started_on' => '2026-02-01', 'ended_on' => '2026-01-01']))->toThrow(QueryException::class);
        expect(fn () => medicationInsert(['source' => 'manual', 'verified_at' => now()]))->toThrow(QueryException::class);
        expect(fn () => medicationInsert(['source' => 'x']))->toThrow(QueryException::class);
        medicationInsert(['started_on' => '2026-02-01', 'ended_on' => '2026-02-01']);
        medicationInsert(['ended_on' => '2020-01-01']);
        medicationInsert(['source' => 'ai', 'verified_at' => now()]);
        expect(MedicalMedication::count())->toBe(3);
    });
});

describe('write', function () {
    it('creates, updates and deletes a medication as an edit member', function () {
        [$user, $person] = medicationEditor();

        $this->actingAs($user)->post("/health/people/{$person->id}/medications", MEDICATION_BODY)
            ->assertRedirect("/health/people/{$person->id}")->assertSessionHas('toast.key', 'health.medication.saved');
        $row = DB::table('medical_medications')->first();
        expect($row->source)->toBe('manual')->and($row->verified_at)->toBeNull()->and($row->created_by)->toBe($user->id)->and($row->subject_id)->toBe($person->id);

        $this->actingAs($user)->patch("/health/people/{$person->id}/medications/{$row->id}", ['dose' => null, 'ended_on' => '2026-03-01'] + MEDICATION_BODY)
            ->assertRedirect("/health/people/{$person->id}");
        expect(DB::table('medical_medications')->first())->dose->toBeNull()->ended_on->toBe('2026-03-01');

        $this->actingAs($user)->delete("/health/people/{$person->id}/medications/{$row->id}")->assertSessionHas('toast.key', 'health.medication.deleted');
        expect(DB::table('medical_medications')->count())->toBe(0);
    });

    it('refuses invalid bodies for a visible person with the message keys', function (array $override, string $field, string $key) {
        [$user, $person] = medicationEditor();
        $this->actingAs($user)->post("/health/people/{$person->id}/medications", $override + MEDICATION_BODY)->assertSessionHasErrors([$field => $key]);
        expect(DB::table('medical_medications')->count())->toBe(0);
    })->with([
        'empty name' => [['name' => ''], 'name', 'health.medication.err_name'],
        'long name' => [['name' => str_repeat('a', 121)], 'name', 'health.medication.err_name'],
        'bad utf8' => [['name' => "\xB1\x31"], 'name', 'health.medication.err_name'],
        'end before start' => [['started_on' => '2026-05-02', 'ended_on' => '2026-05-01'], 'ended_on', 'health.medication.err_end_before_start'],
        'long notes' => [['notes' => str_repeat('a', 1001)], 'notes', 'health.error.load'],
    ]);

    it('does not let a client choose provenance', function () {
        [$user, $person] = medicationEditor();
        $this->actingAs($user)->post("/health/people/{$person->id}/medications", MEDICATION_BODY + ['source' => 'ai', 'verified_at' => now()->toDateTimeString()]);
        expect(DB::table('medical_medications')->first())->source->toBe('manual')->verified_at->toBeNull();
    });

    it('scopes the update and delete statements by membership', function () {
        [$user, $person] = medicationEditor();
        $row = MedicalMedication::factory()->forSubject($person)->create();
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });

        app(MedicationQuery::class)->update(Viewer::for($user), $person, $row->id, MEDICATION_BODY);
        app(MedicationQuery::class)->delete(Viewer::for($user), $person, $row->id);

        $writes = array_values(array_filter($queries, fn ($s) => preg_match('/^(update|delete)/i', $s)));
        expect($writes)->toHaveCount(2);
        foreach ($writes as $sql) {
            expect($sql)->toContain('medical_subject_members')->toContain('"user_id" = ?');
        }
    });
});

describe('ongoing and ended', function () {
    afterEach(fn () => Carbon::setTestNow());

    it('lists ongoing first by name, then ended by end date descending', function () {
        [$user, $person] = medicationEditor();
        Carbon::setTestNow(Carbon::parse('2026-06-15 10:00', 'Europe/Bratislava'));
        MedicalMedication::factory()->forSubject($person)->ended('2026-01-01')->create(['name' => 'Old']);
        MedicalMedication::factory()->forSubject($person)->ongoing()->create(['name' => 'zinc']);
        MedicalMedication::factory()->forSubject($person)->ended('2026-05-01')->create(['name' => 'Recent']);
        MedicalMedication::factory()->forSubject($person)->ended('2026-06-15')->create(['name' => 'Ends today']);
        MedicalMedication::factory()->forSubject($person)->ongoing()->create(['name' => 'Aspirin']);

        $list = app(MedicationQuery::class)->forSubject(Viewer::for($user), $person, '2026-06-15')->pluck('name')->all();
        expect($list)->toBe(['Aspirin', 'Ends today', 'zinc', 'Recent', 'Old']);
    });

    it('current() includes no end date and an end date of today, excludes yesterday, with the family date at the timezone edge', function () {
        [$user, $person] = medicationEditor();
        // 23:30 UTC on 14 June is already 15 June in Bratislava (UTC+2).
        Carbon::setTestNow(Carbon::parse('2026-06-14 23:30:00', 'UTC'));
        $today = now(config('family.timezone'))->toDateString();
        expect($today)->toBe('2026-06-15')->and(now('UTC')->toDateString())->toBe('2026-06-14');
        MedicalMedication::factory()->forSubject($person)->ongoing()->create(['name' => 'Open']);
        MedicalMedication::factory()->forSubject($person)->ended('2026-06-15')->create(['name' => 'Today']);
        MedicalMedication::factory()->forSubject($person)->ended('2026-06-14')->create(['name' => 'Yesterday']);

        $names = app(MedicationQuery::class)->current(Viewer::for($user), $person, $today)->pluck('name')->all();
        expect($names)->toBe(['Open', 'Today']);

        $response = $this->actingAs($user)->get("/health/people/{$person->id}");
        $response->assertInertia(fn ($page) => $page->has('medications', 3)
            ->where('medications.0.name', 'Open')->where('medications.0.ongoing', true)
            ->where('medications.1.name', 'Today')->where('medications.1.ongoing', true)
            ->where('medications.2.name', 'Yesterday')->where('medications.2.ongoing', false)
            ->where('medications.2.endedOn', '2026-06-14'));
    });
});

describe('identical 403', function () {
    it('answers hidden, unknown, malformed, mismatched and view-only targets the same and changes nothing', function () {
        [$user, $person] = medicationEditor();
        $viewOnly = MedicalFixtures::subject([], [[$user, AccessLevel::View]]);
        $hidden = MedicalFixtures::subject([], [[User::factory()->create(), AccessLevel::Edit]]);
        $ownOff = MedicalSubject::factory()->linkedTo(User::factory()->create(), false)->create();
        $hiddenRow = MedicalMedication::factory()->create(['subject_id' => $hidden->id, 'name' => 'ZxCanaryMed']);
        $viewRow = MedicalMedication::factory()->create(['subject_id' => $viewOnly->id, 'name' => 'ZxCanaryMed']);
        $before = DB::table('medical_medications')->orderBy('id')->get()->toArray();
        $random = (string) Str::uuid();

        $targets = [
            ['post', "/health/people/{$hidden->id}/medications"],
            ['post', "/health/people/{$ownOff->id}/medications"],
            ['post', "/health/people/{$random}/medications"],
            ['post', '/health/people/abc/medications'],
            ['post', "/health/people/{$viewOnly->id}/medications"],
            ['patch', "/health/people/{$hidden->id}/medications/{$hiddenRow->id}"],
            ['patch', "/health/people/{$person->id}/medications/{$hiddenRow->id}"],
            ['patch', "/health/people/{$person->id}/medications/{$random}"],
            ['patch', "/health/people/{$person->id}/medications/abc"],
            ['patch', "/health/people/{$viewOnly->id}/medications/{$viewRow->id}"],
            ['delete', "/health/people/{$hidden->id}/medications/{$hiddenRow->id}"],
            ['delete', "/health/people/{$person->id}/medications/{$hiddenRow->id}"],
            ['delete', "/health/people/{$person->id}/medications/abc"],
            ['delete', "/health/people/{$viewOnly->id}/medications/{$viewRow->id}"],
            ['delete', "/health/people/{$random}/medications/{$random}"],
        ];
        $reference = null;
        foreach ($targets as [$method, $url]) {
            $response = $this->actingAs($user)->{$method}($url, $method === 'delete' ? [] : MEDICATION_BODY);
            $response->assertForbidden();
            $page = $response->viewData('page');
            expect(json_encode($page))->not->toContain('ZxCanaryMed');
            $reference ??= ['component' => $page['component'], 'props' => $page['props']];
            expect(['component' => $page['component'], 'props' => $page['props']])->toEqual($reference, "$method $url");
        }
        expect(DB::table('medical_medications')->orderBy('id')->get()->toArray())->toEqual($before);
    });

    it('answers an invalid body to a hidden, unknown or malformed person like a valid one (authorize first)', function () {
        [$user, $person] = medicationEditor();
        $viewOnly = MedicalFixtures::subject([], [[$user, AccessLevel::View]]);
        $hidden = MedicalFixtures::subject([], [[User::factory()->create(), AccessLevel::Edit]]);
        $ownOff = MedicalSubject::factory()->linkedTo(User::factory()->create(), false)->create();
        $row = MedicalMedication::factory()->forSubject($person)->create();
        $random = (string) Str::uuid();
        $invalid = ['name' => '', 'started_on' => '2026-05-02', 'ended_on' => '2026-05-01'];

        $baseline = $this->actingAs($user)->post("/health/people/{$random}/medications", MEDICATION_BODY);
        $baseline->assertForbidden();
        $reference = $baseline->viewData('page');
        foreach ([$hidden->id, $ownOff->id, $random, 'abc', $viewOnly->id] as $id) {
            foreach ([['post', "/health/people/{$id}/medications"], ['patch', "/health/people/{$id}/medications/{$row->id}"]] as [$method, $url]) {
                $response = $this->actingAs($user)->{$method}($url, $invalid);
                $response->assertForbidden()->assertSessionHasNoErrors();
                $page = $response->viewData('page');
                expect(['component' => $page['component'], 'props' => $page['props']])->toEqual(['component' => $reference['component'], 'props' => $reference['props']], "$method $url");
            }
        }
    });
});

describe('person page', function () {
    it('lists medications and hides other people medications', function () {
        [$user, $person] = medicationEditor();
        MedicalMedication::factory()->forSubject($person)->ended('2000-01-01')->create(['name' => 'Old']);
        MedicalMedication::factory()->forSubject($person)->create(['name' => 'Current', 'notes' => null]);
        $hidden = MedicalFixtures::subject([], [[User::factory()->create(), AccessLevel::Edit]]);
        MedicalMedication::factory()->forSubject($hidden)->create(['name' => 'ZxCanaryMed']);

        $response = $this->actingAs($user)->get("/health/people/{$person->id}");
        $response->assertInertia(fn ($page) => $page->has('medications', 2)
            ->where('medications.0.name', 'Current')->where('medications.0.ongoing', true)->where('medications.0.endedOn', null)
            ->where('medications.1.name', 'Old')->where('medications.1.ongoing', false)->has('medications.0.id'));
        expect(json_encode($response->viewData('page')))->not->toContain('ZxCanaryMed');
    });

    it('resolves medication and new-medication panels inside the person and aborts 403 otherwise', function () {
        [$user, $person] = medicationEditor();
        $mine = MedicalMedication::factory()->forSubject($person)->create();
        $hidden = MedicalFixtures::subject([], [[User::factory()->create(), AccessLevel::Edit]]);
        $theirs = MedicalMedication::factory()->forSubject($hidden)->create();
        $random = (string) Str::uuid();

        $this->actingAs($user)->get("/health/people/{$person->id}?panel=medication&id={$mine->id}")
            ->assertInertia(fn ($page) => $page->where('panel', ['kind' => 'medication', 'id' => $mine->id]));
        $this->actingAs($user)->get("/health/people/{$person->id}?panel=new-medication")
            ->assertInertia(fn ($page) => $page->where('panel', ['kind' => 'new-medication', 'id' => null]));

        $reference = $this->actingAs($user)->get("/health/people/{$hidden->id}")->assertForbidden()->viewData('page');
        foreach (["panel=medication&id={$theirs->id}", "panel=medication&id={$random}", 'panel=medication&id=abc', 'panel=medication', "panel=new-medication&id={$mine->id}"] as $query) {
            $page = $this->actingAs($user)->get("/health/people/{$person->id}?{$query}")->assertForbidden()->viewData('page');
            expect($page['props'])->toEqual($reference['props'], $query);
        }
    });

    it('shows a view-only member medications read-only and refuses new-medication', function () {
        $user = User::factory()->create();
        MedicalFixtures::grantHealth($user, AccessLevel::View);
        $person = MedicalFixtures::subject([], [[$user, AccessLevel::View]]);
        $row = MedicalMedication::factory()->forSubject($person)->create();

        $this->actingAs($user)->get("/health/people/{$person->id}?panel=medication&id={$row->id}")
            ->assertInertia(fn ($page) => $page->where('person.canEdit', false)->has('medications', 1)->where('panel.kind', 'medication'));
        $this->actingAs($user)->get("/health/people/{$person->id}?panel=new-medication")->assertForbidden();
    });
});
