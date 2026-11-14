<?php

use App\Access\AccessLevel;
use App\Audit\AuditLogger;
use App\Medical\Reminders\VaccinationDueHandler;
use App\Models\AuditEntry;
use App\Models\MedicalAllergy;
use App\Models\MedicalContact;
use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use App\Models\MedicalVaccination;
use App\Models\MedicalVisit;
use App\Models\OutboxItem;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FailingAuditLogger;
use Tests\Support\MedicalFileFixtures;
use Tests\Support\MedicalFixtures;
use Tests\Support\MedicalPrivacy;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    Storage::fake('medical');
});

/** @return array{0: User, 1: MedicalSubject} */
function personEditor(): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user);

    return [$user, MedicalFixtures::subject(['name' => 'Old Name'], [[$user, AccessLevel::Edit]])];
}

const PERSON_BODY = ['name' => 'New Name', 'birth_date' => '2015-04-02', 'sex' => 'female', 'blood_type' => 'A+', 'notes' => 'n'];

describe('contacts and update', function () {
    it('stores 0..3 contacts in order on create and update', function () {
        [$user, $person] = personEditor();

        $this->actingAs($user)->post('/health/people', ['name' => 'Kid', 'contacts' => [
            ['name' => 'Mum', 'relation' => 'Mother', 'phone' => '+421 900 111 222'],
            ['name' => 'Dad', 'relation' => '', 'phone' => '(02) 555-1234'],
        ]])->assertRedirect();
        $created = MedicalSubject::where('name', 'Kid')->sole();
        expect(MedicalContact::where('subject_id', $created->id)->orderBy('sort')->get(['name', 'relation', 'sort'])->map->only(['name', 'relation', 'sort'])->all())
            ->toBe([['name' => 'Mum', 'relation' => 'Mother', 'sort' => 0], ['name' => 'Dad', 'relation' => null, 'sort' => 1]]);

        $three = array_map(fn ($i) => ['name' => "C$i", 'phone' => '12345'.$i], [1, 2, 3]);
        $this->actingAs($user)->patch("/health/people/{$person->id}", PERSON_BODY + ['contacts' => $three])->assertRedirect("/health/people/{$person->id}");
        expect(MedicalContact::where('subject_id', $person->id)->orderBy('sort')->pluck('name')->all())->toBe(['C1', 'C2', 'C3']);

        $this->actingAs($user)->patch("/health/people/{$person->id}", PERSON_BODY + ['contacts' => [['name' => 'Solo', 'phone' => '999']]]);
        expect(MedicalContact::where('subject_id', $person->id)->pluck('name')->all())->toBe(['Solo']);

        $this->actingAs($user)->patch("/health/people/{$person->id}", PERSON_BODY + ['contacts' => []]);
        expect(MedicalContact::where('subject_id', $person->id)->count())->toBe(0);
    });

    it('updates the profile as an editor', function () {
        [$user, $person] = personEditor();

        $this->actingAs($user)->patch("/health/people/{$person->id}", PERSON_BODY)->assertSessionHas('toast.key', 'health.person.saved');
        $fresh = MedicalSubject::find($person->id);
        expect([$fresh->name, $fresh->birth_date->format('Y-m-d'), $fresh->sex, $fresh->blood_type, $fresh->notes])->toBe(['New Name', '2015-04-02', 'female', 'A+', 'n']);
    });

    it('refuses a fourth contact and a hostile phone', function () {
        [$user, $person] = personEditor();
        $four = array_map(fn ($i) => ['name' => "C$i", 'phone' => '12345'], [1, 2, 3, 4]);

        $this->actingAs($user)->patch("/health/people/{$person->id}", PERSON_BODY + ['contacts' => $four])
            ->assertSessionHasErrors(['contacts' => 'health.person.err_contacts_max']);
        $this->actingAs($user)->patch("/health/people/{$person->id}", PERSON_BODY + ['contacts' => [['name' => 'X', 'phone' => 'abc<script>']]])
            ->assertSessionHasErrors(['contacts.0.phone' => 'health.person.err_phone']);
        $this->actingAs($user)->patch("/health/people/{$person->id}", PERSON_BODY + ['contacts' => [['name' => '', 'phone' => '123']]])
            ->assertSessionHasErrors(['contacts.0.name' => 'health.person.err_name']);
        expect(MedicalContact::count())->toBe(0)->and(MedicalSubject::find($person->id)->name)->toBe('Old Name');
    });

    it('keeps stored fields when a PATCH sends only the name', function () {
        [$user, $person] = personEditor();
        $this->actingAs($user)->patch("/health/people/{$person->id}", PERSON_BODY)->assertRedirect();

        $this->actingAs($user)->patch("/health/people/{$person->id}", ['name' => 'Renamed'])->assertRedirect();
        $row = MedicalSubject::find($person->id);
        expect($row->name)->toBe('Renamed')->and($row->blood_type)->toBe('A+')->and($row->sex)->toBe('female')
            ->and($row->notes)->toBe('n')->and($row->birth_date->toDateString())->toBe('2015-04-02');

        $this->actingAs($user)->patch("/health/people/{$person->id}", ['name' => 'Renamed', 'blood_type' => null])->assertRedirect();
        expect(MedicalSubject::find($person->id)->blood_type)->toBeNull()->and(MedicalSubject::find($person->id)->notes)->toBe('n');
    });

    it('lets a linked member edit their own record', function () {
        $user = User::factory()->create();
        MedicalFixtures::grantHealth($user);
        $own = MedicalSubject::factory()->linkedTo($user)->create(['name' => 'Me']);

        $this->actingAs($user)->patch("/health/people/{$own->id}", ['name' => 'Me Too'])->assertRedirect();
        expect(MedicalSubject::find($own->id)->name)->toBe('Me Too');
    });
});

describe('refusals', function () {
    it('answers the identical 403 for hidden, own_off, random, malformed and view-only, even with an invalid body', function () {
        [$user, $person] = personEditor();
        $other = User::factory()->create();
        $hidden = MedicalFixtures::subject(['name' => 'Hidden'], [[$other, AccessLevel::Edit]]);
        $ownOff = MedicalFixtures::subject(['name' => 'Off', 'user_id' => $user->id, 'self_access_enabled' => false]);
        $viewOnly = MedicalFixtures::subject(['name' => 'Viewable'], [[$user, AccessLevel::View]]);
        MedicalContact::factory()->forSubject($hidden)->create(['name' => 'Keep']);

        $invalid = ['name' => '', 'contacts' => array_fill(0, 4, ['name' => '', 'phone' => 'x'])];
        $valid = PERSON_BODY;
        $send = function (string $id, array $body) use ($user) {
            $this->flushSession();
            $response = $this->actingAs($user)->patch("/health/people/$id", $body);

            return $response;
        };

        $reference = $send((string) Str::uuid(), $valid);
        $reference->assertForbidden();
        foreach ([$hidden->id, $ownOff->id, (string) Str::uuid(), 'abc', $viewOnly->id] as $id) {
            $response = $send($id, $invalid);
            $response->assertForbidden()->assertSessionHasNoErrors();
            expect(MedicalPrivacy::normalise($response))->toBe(MedicalPrivacy::normalise($reference));
        }
        expect(MedicalSubject::find($hidden->id)->name)->toBe('Hidden')
            ->and(MedicalContact::where('subject_id', $hidden->id)->pluck('name')->all())->toBe(['Keep'])
            ->and(MedicalSubject::find($viewOnly->id)->name)->toBe('Viewable');
    });

    it('answers the identical 403 on delete and removes nothing', function () {
        [$user, $person] = personEditor();
        $hidden = MedicalFixtures::subject([], [[User::factory()->create(), AccessLevel::Edit]]);
        $viewOnly = MedicalFixtures::subject([], [[$user, AccessLevel::View]]);

        $reference = $this->actingAs($user)->delete('/health/people/'.Str::uuid());
        $reference->assertForbidden();
        foreach ([$hidden->id, 'abc', $viewOnly->id] as $id) {
            $response = $this->actingAs($user)->delete("/health/people/$id");
            $response->assertForbidden();
            expect(MedicalPrivacy::normalise($response))->toBe(MedicalPrivacy::normalise($reference));
        }
        expect(MedicalSubject::count())->toBe(3);
    });

    it('statements of update and delete carry the members predicate', function () {
        [$user, $person] = personEditor();
        $log = [];
        DB::listen(function ($q) use (&$log) {
            if (preg_match('/^(update|delete from) "?medical_subjects"?\b/i', $q->sql)) {
                $log[] = $q->sql;
            }
        });
        $this->actingAs($user)->patch("/health/people/{$person->id}", PERSON_BODY);
        $this->actingAs($user)->delete("/health/people/{$person->id}");

        expect($log)->toHaveCount(2);
        foreach ($log as $sql) {
            expect($sql)->toContain('medical_subject_members')->toContain('user_id');
        }
    });

    it('serves the edit-person panel to editors only', function () {
        [$user, $person] = personEditor();
        $viewOnly = MedicalFixtures::subject([], [[$user, AccessLevel::View]]);

        $this->actingAs($user)->get("/health/people/{$person->id}?panel=edit-person")
            ->assertOk()->assertInertia(fn ($page) => $page->where('panel.kind', 'edit-person')->where('person.contacts', []));
        $this->actingAs($user)->get("/health/people/{$viewOnly->id}?panel=edit-person")->assertForbidden();
        $this->actingAs($user)->get("/health/people/{$person->id}?panel=edit-person&id=x")->assertForbidden();
    });

    it('exposes contacts to a viewer who can see the person', function () {
        [$user, $person] = personEditor();
        MedicalContact::factory()->forSubject($person)->create(['name' => 'Mum', 'relation' => 'Mother', 'phone' => '123']);

        $this->actingAs($user)->get("/health/people/{$person->id}")
            ->assertInertia(fn ($page) => $page->where('person.contacts', [['name' => 'Mum', 'relation' => 'Mother', 'phone' => '123']]));
    });
});

describe('hard delete', function () {
    it('removes the person, every child row and the files after commit, with a content-free audit row', function () {
        [$user, $person] = personEditor();
        $other = MedicalFixtures::subject(['name' => 'Bystander'], [[$user, AccessLevel::Edit]]);
        $visit = MedicalVisit::factory()->forSubject($person)->create();
        MedicalAllergy::factory()->forSubject($person)->create();
        MedicalVaccination::factory()->forSubject($person)->create();
        MedicalContact::factory()->forSubject($person)->create();
        DB::table('medical_medications')->insert(['id' => (string) Str::uuid(), 'subject_id' => $person->id, 'name' => 'Med', 'created_at' => now(), 'updated_at' => now()]);
        $document = MedicalDocument::factory()->pdf()->create(['subject_id' => $person->id, 'visit_id' => $visit->id, 'path' => $person->id.'/A.pdf']);
        Storage::disk('medical')->put($document->path, MedicalFileFixtures::pdf());
        $keep = MedicalDocument::factory()->pdf()->create(['subject_id' => $other->id, 'path' => $other->id.'/B.pdf']);
        Storage::disk('medical')->put($keep->path, MedicalFileFixtures::pdf());

        $this->actingAs($user)->delete("/health/people/{$person->id}")
            ->assertRedirect('/health')->assertSessionHas('toast.key', 'health.person.deleted');

        foreach (['medical_visits', 'medical_medications', 'medical_allergies', 'medical_vaccinations', 'medical_contacts', 'medical_documents', 'medical_subject_members'] as $table) {
            expect(DB::table($table)->where('subject_id', $person->id)->count())->toBe(0, $table);
        }
        expect(MedicalSubject::find($person->id))->toBeNull()
            ->and(Storage::disk('medical')->exists($document->path))->toBeFalse()
            ->and(Storage::disk('medical')->exists($keep->path))->toBeTrue()
            ->and(MedicalSubject::find($other->id))->not->toBeNull();

        $audit = AuditEntry::where('event', 'medical_subject_deleted')->sole();
        expect($audit->meta)->toBe(['subject' => $person->id])->and(json_encode($audit->getAttributes()))->not->toContain('Old Name');
    });

    it('deletes nothing and keeps the files when the audit write fails', function () {
        [$user, $person] = personEditor();
        $document = MedicalDocument::factory()->pdf()->create(['subject_id' => $person->id, 'path' => $person->id.'/A.pdf']);
        Storage::disk('medical')->put($document->path, MedicalFileFixtures::pdf());
        app()->instance(AuditLogger::class, new FailingAuditLogger);
        $this->withoutExceptionHandling();

        expect(fn () => $this->actingAs($user)->delete("/health/people/{$person->id}"))->toThrow(RuntimeException::class);
        expect(MedicalSubject::find($person->id))->not->toBeNull()
            ->and(MedicalDocument::find($document->id))->not->toBeNull()
            ->and(Storage::disk('medical')->exists($document->path))->toBeTrue();
    });

    it('makes pending vaccination reminders irrelevant at send time', function () {
        [$user, $person] = personEditor();
        MedicalVaccination::factory()->forSubject($person)->due(now((string) config('family.timezone'))->addDays(14)->toDateString())->create();
        Artisan::call('medical:enqueue-reminders', ['--force' => true]);
        $row = OutboxItem::query()->first();
        expect($row)->not->toBeNull()
            ->and(app(VaccinationDueHandler::class)->stillRelevant($row, $user))->toBeTrue();

        $this->actingAs($user)->delete("/health/people/{$person->id}")->assertRedirect('/health');

        expect(app(VaccinationDueHandler::class)->stillRelevant($row, $user))->toBeFalse();
    });
});
