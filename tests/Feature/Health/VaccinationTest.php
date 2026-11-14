<?php

use App\Access\AccessLevel;
use App\Medical\Queries\VaccinationQuery;
use App\Medical\Viewer;
use App\Models\MedicalSubject;
use App\Models\MedicalVaccination;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

const VACCINATION_BODY = ['vaccine' => 'Tetanus', 'given_on' => '2026-01-10', 'next_due_on' => '2036-01-10', 'notes' => null];

/** @return array{0: User, 1: MedicalSubject} an edit member and a person they can change */
function vaccinationEditor(): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user);

    return [$user, MedicalFixtures::subject([], [[$user, AccessLevel::Edit]])];
}

function vaccinationInsert(array $row): void
{
    DB::transaction(fn () => DB::table('medical_vaccinations')->insert($row + [
        'public_id' => (string) Str::uuid(), 'subject_id' => MedicalSubject::factory()->create()->id, 'vaccine' => 'x',
        'given_on' => '2026-01-01', 'created_at' => now(), 'updated_at' => now(),
    ]));
}

describe('schema', function () {
    it('refuses a due date before the date given, a duplicate public id and a manual row with verified_at', function () {
        expect(fn () => vaccinationInsert(['given_on' => '2026-02-01', 'next_due_on' => '2026-01-01']))->toThrow(QueryException::class);
        expect(fn () => vaccinationInsert(['source' => 'manual', 'verified_at' => now()]))->toThrow(QueryException::class);
        $id = (string) Str::uuid();
        vaccinationInsert(['public_id' => $id]);
        expect(fn () => vaccinationInsert(['public_id' => $id]))->toThrow(QueryException::class);
        vaccinationInsert(['given_on' => '2026-02-01', 'next_due_on' => '2026-02-01']);
        expect(MedicalVaccination::count())->toBe(2);
    });

    it('hides the bigint id from serialization', function () {
        $row = MedicalVaccination::factory()->forSubject(MedicalSubject::factory()->create())->create();
        expect($row->toArray())->not->toHaveKey('id')->toHaveKey('public_id');
    });
});

describe('due status', function () {
    afterEach(fn () => Carbon::setTestNow());

    it('computes overdue, due soon, later and none with the family date and counts attention for this person only', function () {
        [$user, $person] = vaccinationEditor();
        $today = '2026-06-15';
        $make = fn (string $name, ?string $due) => MedicalVaccination::factory()->forSubject($person)->create(['vaccine' => $name, 'next_due_on' => $due, 'given_on' => '2020-01-01']);
        $make('Yesterday', '2026-06-14');
        $make('Today', '2026-06-15');
        $make('In30', '2026-07-15');
        $make('In31', '2026-07-16');
        $make('None', null);
        $hidden = MedicalFixtures::subject([], [[User::factory()->create(), AccessLevel::Edit]]);
        MedicalVaccination::factory()->forSubject($hidden)->due('2020-01-01')->create(['vaccine' => 'ZxCanaryMed']);

        $q = app(VaccinationQuery::class);
        $list = $q->forSubject(Viewer::for($user), $person, $today)->mapWithKeys(fn ($v) => [$v->vaccine => [$v->status, $v->dueInDays]])->all();
        expect($list)->toBe([
            'Yesterday' => ['overdue', -1], 'Today' => ['due_soon', 0], 'In30' => ['due_soon', 30], 'In31' => ['later', 31], 'None' => ['none', null],
        ]);
        expect($q->attentionCount(Viewer::for($user), $person, $today))->toBe(3);
        expect($q->attentionCount(Viewer::for($user), $hidden, $today))->toBe(0);
    });

    it('orders due items soonest first, then past vaccinations by date given', function () {
        [$user, $person] = vaccinationEditor();
        MedicalVaccination::factory()->forSubject($person)->given('2019-01-01')->create(['vaccine' => 'OldA']);
        MedicalVaccination::factory()->forSubject($person)->given('2022-01-01')->create(['vaccine' => 'OldB']);
        MedicalVaccination::factory()->forSubject($person)->given('2019-01-01')->due('2030-01-01')->create(['vaccine' => 'Later']);
        MedicalVaccination::factory()->forSubject($person)->given('2019-01-01')->due('2027-01-01')->create(['vaccine' => 'Soon']);

        $names = app(VaccinationQuery::class)->forSubject(Viewer::for($user), $person, '2026-06-15')->pluck('vaccine')->all();
        expect($names)->toBe(['Soon', 'Later', 'OldB', 'OldA']);
    });
});

describe('write', function () {
    it('creates, updates and deletes a vaccination with the public id only', function () {
        [$user, $person] = vaccinationEditor();

        $this->actingAs($user)->post("/health/people/{$person->id}/vaccinations", VACCINATION_BODY)
            ->assertRedirect("/health/people/{$person->id}")->assertSessionHas('toast.key', 'health.vaccination.saved');
        $row = DB::table('medical_vaccinations')->first();
        expect($row->source)->toBe('manual')->and($row->verified_at)->toBeNull()->and($row->created_by)->toBe($user->id)->and($row->subject_id)->toBe($person->id);
        expect(Str::isUuid($row->public_id))->toBeTrue();

        $this->actingAs($user)->patch("/health/people/{$person->id}/vaccinations/{$row->public_id}", ['next_due_on' => null] + VACCINATION_BODY)
            ->assertRedirect("/health/people/{$person->id}");
        expect(DB::table('medical_vaccinations')->first())->next_due_on->toBeNull();

        $this->actingAs($user)->delete("/health/people/{$person->id}/vaccinations/{$row->public_id}")->assertSessionHas('toast.key', 'health.vaccination.deleted');
        expect(DB::table('medical_vaccinations')->count())->toBe(0);
    });

    it('refuses invalid bodies for a visible person with the message keys', function (array $override, string $field, string $key) {
        [$user, $person] = vaccinationEditor();
        $this->actingAs($user)->post("/health/people/{$person->id}/vaccinations", $override + VACCINATION_BODY)->assertSessionHasErrors([$field => $key]);
        expect(DB::table('medical_vaccinations')->count())->toBe(0);
    })->with([
        'empty vaccine' => [['vaccine' => ''], 'vaccine', 'health.vaccination.err_vaccine'],
        'long vaccine' => [['vaccine' => str_repeat('a', 121)], 'vaccine', 'health.vaccination.err_vaccine'],
        'bad utf8' => [['vaccine' => "\xB1\x31"], 'vaccine', 'health.vaccination.err_vaccine'],
        'missing given' => [['given_on' => ''], 'given_on', 'health.vaccination.err_given_on'],
        'given in the future' => [['given_on' => '2999-01-01', 'next_due_on' => null], 'given_on', 'health.vaccination.err_given_on'],
        'due before given' => [['next_due_on' => '2025-01-01'], 'next_due_on', 'health.vaccination.err_next_due'],
    ]);

    it('does not let a client choose provenance', function () {
        [$user, $person] = vaccinationEditor();
        $this->actingAs($user)->post("/health/people/{$person->id}/vaccinations", VACCINATION_BODY + ['source' => 'ai', 'verified_at' => now()->toDateTimeString()]);
        expect(DB::table('medical_vaccinations')->first())->source->toBe('manual')->verified_at->toBeNull();
    });

    it('scopes the update and delete statements by membership', function () {
        [$user, $person] = vaccinationEditor();
        $row = MedicalVaccination::factory()->forSubject($person)->create();
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });

        app(VaccinationQuery::class)->update(Viewer::for($user), $person, $row->public_id, VACCINATION_BODY);
        app(VaccinationQuery::class)->markGiven(Viewer::for($user), $person, $row->public_id, '2026-02-01', null);
        app(VaccinationQuery::class)->delete(Viewer::for($user), $person, $row->public_id);

        $writes = array_values(array_filter($queries, fn ($s) => preg_match('/^(update|delete)/i', $s)));
        expect($writes)->toHaveCount(3);
        foreach ($writes as $sql) {
            expect($sql)->toContain('medical_subject_members')->toContain('"user_id" = ?');
        }
    });
});

describe('mark as given', function () {
    it('records the new dose and clears the old next due date', function () {
        [$user, $person] = vaccinationEditor();
        $old = MedicalVaccination::factory()->forSubject($person)->given('2020-01-01')->due('2026-01-01')->create(['vaccine' => 'Booster']);

        $this->actingAs($user)->post("/health/people/{$person->id}/vaccinations/{$old->public_id}/given", ['given_on' => '2026-02-01', 'next_due_on' => '2031-02-01'])
            ->assertRedirect("/health/people/{$person->id}")->assertSessionHas('toast.key', 'health.vaccination.given');

        $rows = DB::table('medical_vaccinations')->orderBy('id')->get();
        expect($rows)->toHaveCount(2);
        expect($rows[0])->next_due_on->toBeNull()->given_on->toBe('2020-01-01');
        expect($rows[1])->vaccine->toBe('Booster')->given_on->toBe('2026-02-01')->next_due_on->toBe('2031-02-01')->subject_id->toBe($person->id)->source->toBe('manual');
        expect($rows[1]->public_id)->not->toBe($old->public_id);
    });

    it('is atomic: a failing insert leaves the old row unchanged', function () {
        [$user, $person] = vaccinationEditor();
        $old = MedicalVaccination::factory()->forSubject($person)->given('2020-01-01')->due('2026-01-01')->create();
        // The CHECK refuses the new row (due before given) after the UPDATE already ran inside the transaction.
        expect(fn () => app(VaccinationQuery::class)->markGiven(Viewer::for($user), $person, $old->public_id, '2026-02-01', '2026-01-01'))->toThrow(QueryException::class);

        expect(DB::table('medical_vaccinations')->count())->toBe(1);
        expect(DB::table('medical_vaccinations')->first())->next_due_on->toBe('2026-01-01');
    });

    it('validates the body', function (array $body, string $field, string $key) {
        [$user, $person] = vaccinationEditor();
        $old = MedicalVaccination::factory()->forSubject($person)->due('2026-01-01')->create();
        $this->actingAs($user)->post("/health/people/{$person->id}/vaccinations/{$old->public_id}/given", $body)->assertSessionHasErrors([$field => $key]);
        expect(DB::table('medical_vaccinations')->count())->toBe(1);
    })->with([
        'missing date' => [[], 'given_on', 'health.vaccination.err_given_on'],
        'future date' => [['given_on' => '2999-01-01'], 'given_on', 'health.vaccination.err_given_on'],
        'due before given' => [['given_on' => '2026-02-01', 'next_due_on' => '2026-01-01'], 'next_due_on', 'health.vaccination.err_next_due'],
    ]);
});

describe('identical 403', function () {
    it('answers hidden, unknown, malformed, mismatched and view-only targets the same and changes nothing', function () {
        [$user, $person] = vaccinationEditor();
        $viewOnly = MedicalFixtures::subject([], [[$user, AccessLevel::View]]);
        $hidden = MedicalFixtures::subject([], [[User::factory()->create(), AccessLevel::Edit]]);
        $ownOff = MedicalSubject::factory()->linkedTo(User::factory()->create(), false)->create();
        $hiddenRow = MedicalVaccination::factory()->forSubject($hidden)->due('2026-01-01')->create(['vaccine' => 'ZxCanaryMed']);
        $viewRow = MedicalVaccination::factory()->forSubject($viewOnly)->due('2026-01-01')->create(['vaccine' => 'ZxCanaryMed']);
        $before = DB::table('medical_vaccinations')->orderBy('id')->get()->toArray();
        $random = (string) Str::uuid();
        $h = $hiddenRow->public_id;
        $v = $viewRow->public_id;

        $targets = [
            ['post', "/health/people/{$hidden->id}/vaccinations"],
            ['post', "/health/people/{$ownOff->id}/vaccinations"],
            ['post', "/health/people/{$random}/vaccinations"],
            ['post', '/health/people/abc/vaccinations'],
            ['post', "/health/people/{$viewOnly->id}/vaccinations"],
            ['patch', "/health/people/{$hidden->id}/vaccinations/{$h}"],
            ['patch', "/health/people/{$person->id}/vaccinations/{$h}"],
            ['patch', "/health/people/{$person->id}/vaccinations/{$random}"],
            ['patch', "/health/people/{$person->id}/vaccinations/abc"],
            ['patch', "/health/people/{$viewOnly->id}/vaccinations/{$v}"],
            ['delete', "/health/people/{$hidden->id}/vaccinations/{$h}"],
            ['delete', "/health/people/{$person->id}/vaccinations/{$h}"],
            ['delete', "/health/people/{$person->id}/vaccinations/abc"],
            ['delete', "/health/people/{$viewOnly->id}/vaccinations/{$v}"],
            ['delete', "/health/people/{$random}/vaccinations/{$random}"],
            ['post', "/health/people/{$hidden->id}/vaccinations/{$h}/given"],
            ['post', "/health/people/{$person->id}/vaccinations/{$h}/given"],
            ['post', "/health/people/{$person->id}/vaccinations/{$random}/given"],
            ['post', "/health/people/{$person->id}/vaccinations/abc/given"],
            ['post', "/health/people/{$viewOnly->id}/vaccinations/{$v}/given"],
        ];
        $reference = null;
        foreach ($targets as [$method, $url]) {
            $body = $method === 'delete' ? [] : (str_ends_with($url, '/given') ? ['given_on' => '2026-02-01'] : VACCINATION_BODY);
            $response = $this->actingAs($user)->{$method}($url, $body);
            $response->assertForbidden();
            $page = $response->viewData('page');
            expect(json_encode($page))->not->toContain('ZxCanaryMed');
            $reference ??= ['component' => $page['component'], 'props' => $page['props']];
            expect(['component' => $page['component'], 'props' => $page['props']])->toEqual($reference, "$method $url");
        }
        expect(DB::table('medical_vaccinations')->orderBy('id')->get()->toArray())->toEqual($before);
    });

    it('answers an invalid body to a hidden, unknown or malformed person like a valid one (authorize first)', function () {
        [$user, $person] = vaccinationEditor();
        $viewOnly = MedicalFixtures::subject([], [[$user, AccessLevel::View]]);
        $hidden = MedicalFixtures::subject([], [[User::factory()->create(), AccessLevel::Edit]]);
        $ownOff = MedicalSubject::factory()->linkedTo(User::factory()->create(), false)->create();
        $row = MedicalVaccination::factory()->forSubject($person)->create();
        $random = (string) Str::uuid();

        $baseline = $this->actingAs($user)->post("/health/people/{$random}/vaccinations", VACCINATION_BODY);
        $baseline->assertForbidden();
        $reference = $baseline->viewData('page');
        foreach ([$hidden->id, $ownOff->id, $random, 'abc', $viewOnly->id] as $id) {
            $calls = [
                ['post', "/health/people/{$id}/vaccinations", ['vaccine' => '']],
                ['patch', "/health/people/{$id}/vaccinations/{$row->public_id}", ['vaccine' => '']],
                ['post', "/health/people/{$id}/vaccinations/{$row->public_id}/given", []],
            ];
            foreach ($calls as [$method, $url, $invalid]) {
                $response = $this->actingAs($user)->{$method}($url, $invalid);
                $response->assertForbidden()->assertSessionHasNoErrors();
                $page = $response->viewData('page');
                expect(['component' => $page['component'], 'props' => $page['props']])->toEqual(['component' => $reference['component'], 'props' => $reference['props']], "$method $url");
            }
        }
    });
});

describe('person page', function () {
    it('never exposes the bigint id in the page props', function () {
        [$user, $person] = vaccinationEditor();
        MedicalVaccination::factory()->forSubject($person)->count(3)->create();
        $row = MedicalVaccination::factory()->forSubject($person)->due('2099-01-01')->create(['vaccine' => 'Marker']);
        $internal = DB::table('medical_vaccinations')->where('vaccine', 'Marker')->value('id');
        expect($internal)->toBeGreaterThan(3);

        $response = $this->actingAs($user)->get("/health/people/{$person->id}");
        $props = $response->viewData('page')['props'];
        $found = collect($props['vaccinations'])->firstWhere('vaccine', 'Marker');
        expect($found['id'])->toBe($row->public_id)->and(array_keys($found))->not->toContain('internalId');
        expect($found)->not->toContain($internal);
    });

    it('lists vaccinations, counts attention for this person only and merges them into the timeline', function () {
        [$user, $person] = vaccinationEditor();
        $today = now(config('family.timezone'))->toDateString();
        MedicalVaccination::factory()->forSubject($person)->given('2010-01-01')->due('2010-06-01')->create(['vaccine' => 'Overdue one']);
        MedicalVaccination::factory()->forSubject($person)->given('2012-01-01')->create(['vaccine' => 'Past']);
        MedicalVaccination::factory()->forSubject($person)->given('2013-01-01')->due('2099-01-01')->create(['vaccine' => 'Far']);
        $hidden = MedicalFixtures::subject([], [[User::factory()->create(), AccessLevel::Edit]]);
        MedicalVaccination::factory()->forSubject($hidden)->given('2010-01-01')->due('2010-06-01')->count(4)->create(['vaccine' => 'ZxCanaryMed']);

        $response = $this->actingAs($user)->get("/health/people/{$person->id}");
        $response->assertInertia(fn ($page) => $page->has('vaccinations', 3)->where('vaccinationAttention', 1)
            ->where('vaccinations.0.vaccine', 'Overdue one')->where('vaccinations.0.status', 'overdue')
            ->where('vaccinations.1.vaccine', 'Far')->where('vaccinations.1.status', 'later')
            ->where('vaccinations.2.status', 'none')->where('timelineFilters', ['vaccinations'])
            ->has('timeline', 3)->where('timeline.0.type', 'vaccination')->where('timeline.0.date', '2013-01-01'));
        expect(json_encode($response->viewData('page')))->not->toContain('ZxCanaryMed');
        expect($today)->not->toBe('');

        $this->actingAs($user)->get("/health/people/{$person->id}?view=timeline&filter=vaccinations")
            ->assertInertia(fn ($page) => $page->where('filter', 'vaccinations')->has('timeline', 3));
        $this->actingAs($user)->get("/health/people/{$person->id}?filter=nonsense")
            ->assertInertia(fn ($page) => $page->where('filter', 'all'));
    });

    it('filters visits out of the vaccinations filter and vaccinations out of a specialty filter', function () {
        [$user, $person] = vaccinationEditor();
        MedicalVaccination::factory()->forSubject($person)->create();
        DB::table('medical_visits')->insert(['id' => (string) Str::uuid(), 'subject_id' => $person->id, 'visited_on' => '2024-01-01', 'specialty_key' => 'dentist', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($user)->get("/health/people/{$person->id}?filter=vaccinations")
            ->assertInertia(fn ($page) => $page->has('timeline', 1)->where('timeline.0.type', 'vaccination'));
        $this->actingAs($user)->get("/health/people/{$person->id}?filter=dentist")
            ->assertInertia(fn ($page) => $page->has('timeline', 1)->where('timeline.0.type', 'visit'));
        $this->actingAs($user)->get("/health/people/{$person->id}")
            ->assertInertia(fn ($page) => $page->has('timeline', 2));
    });

    it('resolves vaccination and new-vaccination panels inside the person and aborts 403 otherwise', function () {
        [$user, $person] = vaccinationEditor();
        $mine = MedicalVaccination::factory()->forSubject($person)->create();
        $hidden = MedicalFixtures::subject([], [[User::factory()->create(), AccessLevel::Edit]]);
        $theirs = MedicalVaccination::factory()->forSubject($hidden)->create();
        $random = (string) Str::uuid();

        $this->actingAs($user)->get("/health/people/{$person->id}?panel=vaccination&id={$mine->public_id}")
            ->assertInertia(fn ($page) => $page->where('panel', ['kind' => 'vaccination', 'id' => $mine->public_id]));
        $this->actingAs($user)->get("/health/people/{$person->id}?panel=new-vaccination")
            ->assertInertia(fn ($page) => $page->where('panel', ['kind' => 'new-vaccination', 'id' => null]));

        $reference = $this->actingAs($user)->get("/health/people/{$hidden->id}")->assertForbidden()->viewData('page');
        foreach (["panel=vaccination&id={$theirs->public_id}", "panel=vaccination&id={$random}", 'panel=vaccination&id=abc', 'panel=vaccination', "panel=new-vaccination&id={$mine->public_id}"] as $query) {
            $page = $this->actingAs($user)->get("/health/people/{$person->id}?{$query}")->assertForbidden()->viewData('page');
            expect($page['props'])->toEqual($reference['props'], $query);
        }
    });

    it('shows a view-only member vaccinations read-only and refuses new-vaccination', function () {
        $user = User::factory()->create();
        MedicalFixtures::grantHealth($user, AccessLevel::View);
        $person = MedicalFixtures::subject([], [[$user, AccessLevel::View]]);
        $row = MedicalVaccination::factory()->forSubject($person)->create();

        $this->actingAs($user)->get("/health/people/{$person->id}?panel=vaccination&id={$row->public_id}")
            ->assertInertia(fn ($page) => $page->where('person.canEdit', false)->has('vaccinations', 1)->where('panel.kind', 'vaccination'));
        $this->actingAs($user)->get("/health/people/{$person->id}?panel=new-vaccination")->assertForbidden();
    });
});
