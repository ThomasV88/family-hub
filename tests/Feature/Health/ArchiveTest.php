<?php

use App\Access\AccessLevel;
use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Medical\AiConsent;
use App\Medical\Documents\DocumentRejected;
use App\Medical\Queries\AiBulkEligibility;
use App\Medical\Queries\AiJobQuery;
use App\Medical\Queries\ArchiveQuery;
use App\Medical\Queries\DocumentQuery;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Queries\SystemQuery;
use App\Medical\Retention;
use App\Medical\Viewer;
use App\Models\AuditEntry;
use App\Models\MedicalAiExtraction;
use App\Models\MedicalAllergy;
use App\Models\MedicalDocument;
use App\Models\MedicalMedication;
use App\Models\MedicalSubject;
use App\Models\MedicalVaccination;
use App\Models\MedicalVisit;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FailingAuditLogger;
use Tests\Support\MedicalFileFixtures;
use Tests\Support\MedicalFixtures;
use Tests\Support\MedicalPrivacy;

/**
 * Owner decision B: a person or record can be deleted for good only inside medical.delete_window_hours of its creation;
 * afterwards it is archived (hidden everywhere, restorable by a member with Edit). The server decides.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    Storage::fake('medical');
});

const ARC_CANARY = 'ZxCanaryArchive';

/** @return array{0: User, 1: MedicalSubject} an Edit member and the person they can change */
function arcEditor(array $subject = []): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user);

    return [$user, MedicalFixtures::subject($subject, [[$user, AccessLevel::Edit]])];
}

/** @return array{0: User, 1: MedicalSubject} a view-only member */
function arcViewer(): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user, AccessLevel::View);

    return [$user, MedicalFixtures::subject([], [[$user, AccessLevel::View]])];
}

/**
 * One record of the given type. $age is the number of hours since creation.
 *
 * @return array{id: string, url: string, table: string, key: string}
 */
function arcRecord(string $type, MedicalSubject $person, int $age = 24 * 30, array $attrs = []): array
{
    $at = now()->subHours($age);
    $times = ['created_at' => $at, 'updated_at' => $at];
    $base = "/health/people/{$person->id}";
    switch ($type) {
        case 'visit':
            $row = MedicalVisit::factory()->forSubject($person)->create($times + $attrs + ['doctor' => ARC_CANARY.' doctor', 'diagnosis' => ARC_CANARY.' diagnosis']);

            return ['id' => (string) $row->getKey(), 'url' => "{$base}/visits/{$row->getKey()}", 'table' => 'medical_visits', 'key' => 'id'];
        case 'medication':
            $row = MedicalMedication::factory()->forSubject($person)->create($times + $attrs + ['name' => ARC_CANARY.' medication']);

            return ['id' => (string) $row->getKey(), 'url' => "{$base}/medications/{$row->getKey()}", 'table' => 'medical_medications', 'key' => 'id'];
        case 'allergy':
            $row = MedicalAllergy::factory()->forSubject($person)->severity('severe')->create($times + $attrs + ['substance' => ARC_CANARY.' allergy']);

            return ['id' => (string) $row->getKey(), 'url' => "{$base}/allergies/{$row->getKey()}", 'table' => 'medical_allergies', 'key' => 'id'];
        case 'vaccination':
            $row = MedicalVaccination::factory()->forSubject($person)->create($times + $attrs + ['vaccine' => ARC_CANARY.' vaccine']);

            return ['id' => (string) $row->public_id, 'url' => "{$base}/vaccinations/{$row->public_id}", 'table' => 'medical_vaccinations', 'key' => 'public_id'];
        default:
            $path = $person->id.'/'.strtoupper((string) Str::ulid()).'.pdf';
            $row = MedicalDocument::factory()->pdf()->create($times + $attrs + ['subject_id' => $person->id, 'title' => ARC_CANARY.' document', 'path' => $path, 'sha256' => hash('sha256', $path)]);
            Storage::disk('medical')->put($row->path, MedicalFileFixtures::pdf());

            return ['id' => (string) $row->getKey(), 'url' => "{$base}/documents/{$row->getKey()}", 'table' => 'medical_documents', 'key' => 'id'];
    }
}

const ARC_TYPES = ['visit', 'medication', 'allergy', 'vaccination', 'document'];

/** @return array<string, mixed> Inertia props of the person page */
function arcProps(User $user, MedicalSubject|string $person, string $query = ''): array
{
    $id = $person instanceof MedicalSubject ? $person->id : $person;

    return test()->actingAs($user)->get("/health/people/{$id}{$query}", ['X-Inertia' => 'true', 'X-Inertia-Version' => MedicalPrivacy::inertiaVersion()])
        ->assertOk()->json('props');
}

function arcRow(array $record): ?object
{
    return DB::table($record['table'])->where($record['key'], $record['id'])->first();
}

describe('the delete window', function () {
    it('deletes a record created inside the window for good and says so', function (string $type) {
        [$user, $person] = arcEditor();
        $record = arcRecord($type, $person, 2);
        $path = $type === 'document' ? MedicalDocument::query()->where('subject_id', $person->id)->value('path') : null;

        $this->actingAs($user)->delete($record['url'])
            ->assertRedirect("/health/people/{$person->id}")
            ->assertSessionHas('toast.outcome', 'deleted');

        expect(arcRow($record))->toBeNull();
        if ($path !== null) {
            expect(Storage::disk('medical')->exists($path))->toBeFalse();
        }
        expect(AuditEntry::where('event', 'medical_archived')->count())->toBe(0);
    })->with(ARC_TYPES);

    it('archives a record older than the window, keeps its row and file, and says so', function (string $type) {
        [$user, $person] = arcEditor();
        $record = arcRecord($type, $person, 24 * 30);
        $path = $type === 'document' ? MedicalDocument::query()->where('subject_id', $person->id)->value('path') : null;

        $this->actingAs($user)->delete($record['url'])
            ->assertRedirect("/health/people/{$person->id}")
            ->assertSessionHas('toast.outcome', 'archived')
            ->assertSessionHas('toast.key', 'health.archive.archived');

        $row = arcRow($record);
        expect($row)->not->toBeNull()
            ->and($row->archived_at)->not->toBeNull()
            ->and((int) $row->archived_by)->toBe($user->id);
        if ($path !== null) {
            expect(Storage::disk('medical')->exists($path))->toBeTrue();
        }
        $audit = AuditEntry::where('event', 'medical_archived')->sole();
        expect($audit->meta)->toEqual(['subject' => $person->id, 'type' => $type, 'record' => $record['id']]);
    })->with(ARC_TYPES);

    it('keeps an archived record on a second delete (nothing is ever deleted for good after the window)', function (string $type) {
        [$user, $person] = arcEditor();
        $record = arcRecord($type, $person, 24 * 30);
        $this->actingAs($user)->delete($record['url'])->assertSessionHas('toast.outcome', 'archived');

        // The record is archived, so it is hidden: the same 403 as any miss, and the row stays.
        $this->actingAs($user)->deleteJson($record['url'])->assertForbidden();
        expect(arcRow($record))->not->toBeNull();
    })->with(ARC_TYPES);

    it('tells the page what a delete will do as a display-only hint', function () {
        [$user, $person] = arcEditor();
        $person->forceFill(['created_at' => now()->subHours(2)])->save();
        $fresh = arcRecord('visit', $person, 2);
        $old = arcRecord('visit', $person, 24 * 30);

        $props = arcProps($user, $person);
        $byId = collect($props['visits'])->keyBy('id');
        expect($byId[$fresh['id']]['permanentDelete'])->toBeTrue()->and($byId[$old['id']]['permanentDelete'])->toBeFalse()
            ->and($props['person']['permanentDelete'])->toBeTrue();

        config(['medical.delete_window_hours' => 0]);
        expect(arcProps($user, $person)['person']['permanentDelete'])->toBeFalse();
    });

    it('decides on the server: a client cannot make an old record deletable', function () {
        [$user, $person] = arcEditor();
        $record = arcRecord('visit', $person, 24 * 30);

        $this->actingAs($user)->delete($record['url'], ['permanent' => true, 'force' => 1, 'outcome' => 'deleted'])
            ->assertSessionHas('toast.outcome', 'archived');
        expect(arcRow($record))->not->toBeNull();
    });

    it('respects the configured window, including 0 and the clamp', function () {
        [$user, $person] = arcEditor();

        config(['medical.delete_window_hours' => 1]);
        $twoHours = arcRecord('allergy', $person, 2);
        $this->actingAs($user)->delete($twoHours['url'])->assertSessionHas('toast.outcome', 'archived');

        config(['medical.delete_window_hours' => 24 * 100]);
        $old = arcRecord('allergy', $person, 24 * 50);
        $this->actingAs($user)->delete($old['url'])->assertSessionHas('toast.outcome', 'deleted');
        expect(arcRow($old))->toBeNull();

        config(['medical.delete_window_hours' => 0]);
        $fresh = arcRecord('allergy', $person, 0);
        $this->actingAs($user)->delete($fresh['url'])->assertSessionHas('toast.outcome', 'archived');
        expect(arcRow($fresh))->not->toBeNull();

        config(['medical.delete_window_hours' => -5]);
        expect(Retention::hours())->toBe(0);
        config(['medical.delete_window_hours' => 99999]);
        expect(Retention::hours())->toBe(8760);
    });

    it('reads MEDICAL_DELETE_WINDOW_HOURS with the default 72 and clamps it to 0..8760', function () {
        $repository = Env::getRepository();
        $read = function (?string $value) use ($repository): int {
            $repository->clear('MEDICAL_DELETE_WINDOW_HOURS');
            if ($value !== null) {
                $repository->set('MEDICAL_DELETE_WINDOW_HOURS', $value);
            }

            return (require config_path('medical.php'))['delete_window_hours'];
        };
        try {
            expect($read(null))->toBe(72)->and($read('0'))->toBe(0)->and($read('24'))->toBe(24)
                ->and($read('-3'))->toBe(0)->and($read('999999'))->toBe(8760);
        } finally {
            $repository->clear('MEDICAL_DELETE_WINDOW_HOURS');
        }
    });

    it('archives a person older than the window with everything below it, deleting nothing', function () {
        [$user, $person] = arcEditor();
        $person->forceFill(['created_at' => now()->subDays(30)])->save();
        $records = array_map(fn (string $t) => arcRecord($t, $person), ARC_TYPES);
        $path = MedicalDocument::query()->where('subject_id', $person->id)->value('path');

        $this->actingAs($user)->delete("/health/people/{$person->id}")
            ->assertRedirect('/health')->assertSessionHas('toast.outcome', 'archived');

        $row = DB::table('medical_subjects')->where('id', $person->id)->first();
        expect($row->archived_at)->not->toBeNull()->and((int) $row->archived_by)->toBe($user->id);
        foreach ($records as $record) {
            expect(arcRow($record))->not->toBeNull();
        }
        expect(Storage::disk('medical')->exists($path))->toBeTrue()
            ->and(AuditEntry::where('event', 'medical_subject_deleted')->count())->toBe(0);
        expect(AuditEntry::where('event', 'medical_archived')->sole()->meta)->toEqual(['subject' => $person->id, 'type' => 'person']);
    });

    it('deletes a person created inside the window for good, files included', function () {
        [$user, $person] = arcEditor();
        $record = arcRecord('document', $person, 2);
        $path = MedicalDocument::query()->where('subject_id', $person->id)->value('path');

        $this->actingAs($user)->delete("/health/people/{$person->id}")->assertRedirect('/health')->assertSessionHas('toast.outcome', 'deleted');

        expect(DB::table('medical_subjects')->where('id', $person->id)->exists())->toBeFalse()->and(arcRow($record))->toBeNull()
            ->and(Storage::disk('medical')->exists($path))->toBeFalse()
            ->and(AuditEntry::where('event', 'medical_subject_deleted')->count())->toBe(1);
    });

    it('rolls an archive back when the audit row cannot be written', function () {
        [$user, $person] = arcEditor();
        $record = arcRecord('visit', $person);
        app()->bind(AuditLogger::class, fn () => new FailingAuditLogger);

        $this->actingAs($user)->deleteJson($record['url'])->assertStatus(500);

        expect(arcRow($record)->archived_at)->toBeNull();
    });
});

describe('archived rows are hidden everywhere', function () {
    it('leaves the person page lists, timeline, folders and counts', function () {
        [$user, $person] = arcEditor();
        $visit = arcRecord('visit', $person, 24 * 30, ['specialty_key' => 'cardiology']);
        $doc = arcRecord('document', $person, 24 * 30);
        $med = arcRecord('medication', $person);
        $allergy = arcRecord('allergy', $person);
        $vacc = arcRecord('vaccination', $person);
        $keep = arcRecord('visit', $person, 24 * 30, ['specialty_key' => 'dentistry', 'doctor' => 'Dr Keep', 'diagnosis' => 'Fine']);
        foreach ([$visit, $doc, $med, $allergy, $vacc] as $record) {
            $this->actingAs($user)->delete($record['url'])->assertSessionHas('toast.outcome', 'archived');
        }

        $props = arcProps($user, $person, '?view=timeline');
        expect(collect($props['visits'])->pluck('id')->all())->toBe([$keep['id']])
            ->and($props['documents'])->toBe([])->and($props['medications'])->toBe([])->and($props['allergies'])->toBe([])->and($props['vaccinations'])->toBe([])
            ->and(collect($props['folders'])->pluck('key')->all())->toBe(['dentistry'])
            ->and(collect($props['timeline'])->pluck('id')->all())->toBe([$keep['id']])
            ->and($props['timelineFilters'])->toBe(['dentistry'])
            ->and($props['vaccinationAttention'])->toBe(0);
        // The Archive section (Edit members only) is the one place that names archived records.
        expect(json_encode(Arr::except($props, 'archive')))->not->toContain(ARC_CANARY)->not->toContain('archived_at');
    });

    it('serves no preview, page or download of an archived document', function () {
        [$user, $person] = arcEditor();
        $doc = arcRecord('document', $person);
        $this->actingAs($user)->delete($doc['url'])->assertSessionHas('toast.outcome', 'archived');

        foreach (["{$doc['url']}/file", "{$doc['url']}/preview/thumb", "{$doc['url']}/preview/full", "{$doc['url']}/pages/1"] as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }
        $this->actingAs($user)->get("/health/people/{$person->id}?panel=document&id={$doc['id']}")->assertForbidden();
    });

    it('drops archived rows from every export format and the emergency card', function () {
        [$user, $person] = arcEditor();
        $keepAllergy = arcRecord('allergy', $person, 24 * 30, ['substance' => 'Keep allergy']);
        $keepMed = arcRecord('medication', $person, 24 * 30, ['name' => 'Keep medication']);
        $records = [arcRecord('visit', $person), arcRecord('medication', $person), arcRecord('allergy', $person), arcRecord('vaccination', $person), arcRecord('document', $person)];
        foreach ($records as $record) {
            $this->actingAs($user)->delete($record['url'])->assertSessionHas('toast.outcome', 'archived');
        }

        $print = $this->actingAs($user)->get("/health/people/{$person->id}/export/print?scope=all&include_unverified=1")->assertOk()->getContent();
        $json = $this->actingAs($user)->get("/health/people/{$person->id}/export/download?scope=all&format=json&include_unverified=1")->assertOk()->getContent();
        $md = $this->actingAs($user)->get("/health/people/{$person->id}/export/download?scope=all&format=md&include_unverified=1")->assertOk()->getContent();
        foreach ([$print, $json, $md] as $body) {
            expect($body)->not->toContain(ARC_CANARY)->toContain('Keep allergy');
        }
        $card = $this->actingAs($user)->get("/health/people/{$person->id}/emergency-card", ['X-Inertia' => 'true', 'X-Inertia-Version' => MedicalPrivacy::inertiaVersion()])->assertOk()->json('props');
        expect(collect($card['allergies'])->pluck('substance')->all())->toBe(['Keep allergy'])
            ->and(collect($card['medications'])->pluck('name')->all())->toBe(['Keep medication'])
            ->and(json_encode($card))->not->toContain(ARC_CANARY);
        unset($keepAllergy, $keepMed);
    });

    it('does not export a reference to an archived visit and lists no unverified archived rows', function () {
        [$user, $person] = arcEditor();
        $visit = arcRecord('visit', $person);
        $doc = arcRecord('document', $person, 24 * 30, ['visit_id' => $visit['id']]);
        $this->actingAs($user)->delete($visit['url'])->assertSessionHas('toast.outcome', 'archived');

        $json = json_decode($this->actingAs($user)->get("/health/people/{$person->id}/export/download?scope=all&format=json&include_unverified=1")->assertOk()->getContent(), true);
        expect(collect($json['documents'])->pluck('visit_id')->all())->toBe([null])->and($json['visits'])->toBe([]);

        // In the person page the document reads as unattached until the visit is restored.
        $props = arcProps($user, $person);
        expect($props['documents'][0]['visitId'])->toBeNull()->and($props['unattachedDocumentIds'])->toBe([$doc['id']]);
        $this->actingAs($user)->post("/health/people/{$person->id}/archive/visit/{$visit['id']}/restore")->assertRedirect();
        expect(arcProps($user, $person)['documents'][0]['visitId'])->toBe($visit['id']);
    });

    it('sends no vaccination reminder for an archived vaccination or person, and cancels a queued one at send time', function () {
        [$user, $person] = arcEditor();
        $due = now('Europe/Bratislava')->addDays(10)->format('Y-m-d');
        $vacc = arcRecord('vaccination', $person, 24 * 30, ['next_due_on' => $due]);
        $id = (int) MedicalVaccination::query()->where('public_id', $vacc['id'])->value('id');
        $system = app(SystemQuery::class);
        $from = now('Europe/Bratislava')->format('Y-m-d');
        $to = now('Europe/Bratislava')->addDays(30)->format('Y-m-d');

        expect($system->dueVaccinations($from, $to))->toHaveCount(1)->and($system->vaccinationDue($id))->not->toBeNull();

        $this->actingAs($user)->delete($vacc['url'])->assertSessionHas('toast.outcome', 'archived');
        expect($system->dueVaccinations($from, $to))->toBe([])->and($system->vaccinationDue($id))->toBeNull();

        $this->actingAs($user)->post("/health/people/{$person->id}/archive/vaccination/{$vacc['id']}/restore")->assertRedirect();
        expect($system->dueVaccinations($from, $to))->toHaveCount(1);

        DB::table('medical_subjects')->where('id', $person->id)->update(['archived_at' => now()]);
        expect($system->dueVaccinations($from, $to))->toBe([])->and($system->vaccinationDue($id))->toBeNull()->and($system->subjectDisplayName($person->id))->toBeNull();
    });

    it('hides an archived person from the switcher, the page and every nested route', function () {
        [$user, $person] = arcEditor(['name' => ARC_CANARY.' Person']);
        $other = MedicalFixtures::subject(['name' => 'Other person'], [[$user, AccessLevel::Edit]]);
        $person->forceFill(['created_at' => now()->subDays(30)])->save();
        $doc = arcRecord('document', $person);
        $this->actingAs($user)->delete("/health/people/{$person->id}")->assertSessionHas('toast.outcome', 'archived');

        $props = arcProps($user, $other);
        expect(collect($props['people'])->pluck('id')->all())->toBe([$other->id])->and(json_encode($props['people']))->not->toContain(ARC_CANARY);
        $this->actingAs($user)->get("/health/people/{$person->id}")->assertForbidden();
        $this->actingAs($user)->get("{$doc['url']}/file")->assertForbidden();
        $this->actingAs($user)->get("/health/people/{$person->id}/export/print?scope=all")->assertForbidden();
        $this->actingAs($user)->get("/health/people/{$person->id}/emergency-card")->assertForbidden();
        $this->actingAs($user)->patch("/health/people/{$person->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->actingAs($user)->post("/health/people/{$person->id}/visits", ['visited_on' => '2026-01-01', 'specialty_key' => 'cardiology'])->assertForbidden();
        expect(DB::table('medical_subjects')->where('id', $person->id)->value('name'))->toStartWith(ARC_CANARY);
    });
});

describe('AI ignores archived rows', function () {
    it('refuses Run AI for an archived document and an archived person like a hidden one', function () {
        [$user, $person] = arcEditor(['ai_consent' => true]);
        $doc = MedicalDocument::factory()->image()->create(['subject_id' => $person->id, 'created_at' => now()->subDays(30)]);
        $run = fn () => $this->actingAs($user)->postJson("/health/people/{$person->id}/documents/{$doc->id}/ai", ['acknowledged' => true, 'providers' => ['codex']]);
        $unknown = $this->actingAs($user)->postJson("/health/people/{$person->id}/documents/".Str::uuid7().'/ai', ['acknowledged' => true, 'providers' => ['codex']]);

        DB::table('medical_documents')->where('id', $doc->id)->update(['archived_at' => now()]);
        $archivedDoc = $run();
        expect([$archivedDoc->getStatusCode(), $archivedDoc->json('message')])->toBe([$unknown->getStatusCode(), $unknown->json('message')])->and($unknown->getStatusCode())->toBe(403);

        DB::table('medical_documents')->where('id', $doc->id)->update(['archived_at' => null]);
        DB::table('medical_subjects')->where('id', $person->id)->update(['archived_at' => now()]);
        $archivedPerson = $run();
        expect($archivedPerson->getStatusCode())->toBe(403)->and(MedicalAiExtraction::query()->count())->toBe(0);
    });

    it('cancels a running job when its document or person is archived meanwhile', function () {
        [$user, $person] = arcEditor(['ai_consent' => true]);
        $doc = MedicalDocument::factory()->image()->create(['subject_id' => $person->id]);
        $run = MedicalAiExtraction::factory()->forDocument($doc)->running()->create(['started_by' => $user->id]);
        $jobs = app(AiJobQuery::class);

        expect($jobs->abortReason($run->id))->toBeNull()->and($jobs->document($run->id))->not->toBeNull();
        DB::table('medical_documents')->where('id', $doc->id)->update(['archived_at' => now()]);
        expect($jobs->abortReason($run->id))->toBe('access')->and($jobs->document($run->id))->toBeNull();

        DB::table('medical_documents')->where('id', $doc->id)->update(['archived_at' => null]);
        DB::table('medical_subjects')->where('id', $person->id)->update(['archived_at' => now()]);
        expect($jobs->abortReason($run->id))->not->toBeNull()->and(app(AiConsent::class)::allows($person->id))->toBeFalse();
    });

    it('refuses to accept a suggestion of an archived document', function () {
        [$user, $person] = arcEditor(['ai_consent' => true]);
        $doc = MedicalDocument::factory()->pdf()->create(['subject_id' => $person->id]);
        $payload = ['schema_version' => 1, 'document_date' => null, 'specialty_key' => null, 'visits' => [], 'medications' => [['name' => 'Ibuprofen', 'dose' => null, 'schedule' => null, 'started_on' => null, 'ended_on' => null, 'notes' => null]], 'allergies' => [], 'vaccinations' => []];
        $extraction = MedicalAiExtraction::factory()->forDocument($doc)->ready($payload, 1)->create(['started_by' => $user->id]);
        $url = "/health/people/{$person->id}/ai/{$extraction->id}/entries/medication-0/accept";

        DB::table('medical_documents')->where('id', $doc->id)->update(['archived_at' => now()]);
        $this->actingAs($user)->postJson($url)->assertForbidden();
        $this->actingAs($user)->postJson("/health/people/{$person->id}/ai/{$extraction->id}/entries/medication-0/reject")->assertForbidden();
        expect(MedicalMedication::query()->count())->toBe(0);

        DB::table('medical_documents')->where('id', $doc->id)->update(['archived_at' => null]);
        $this->actingAs($user)->postJson($url)->assertOk();
        expect(MedicalMedication::query()->count())->toBe(1);
    });

    it('does not link an AI suggestion to an archived record with the same content', function () {
        [$user, $person] = arcEditor(['ai_consent' => true]);
        $archived = arcRecord('medication', $person, 24 * 30, ['name' => 'Ibuprofen', 'dose' => null]);
        $this->actingAs($user)->delete($archived['url'])->assertSessionHas('toast.outcome', 'archived');
        $doc = MedicalDocument::factory()->pdf()->create(['subject_id' => $person->id]);
        $payload = ['schema_version' => 1, 'document_date' => null, 'specialty_key' => null, 'visits' => [], 'medications' => [['name' => 'Ibuprofen', 'dose' => null, 'schedule' => null, 'started_on' => null, 'ended_on' => null, 'notes' => null]], 'allergies' => [], 'vaccinations' => []];
        $extraction = MedicalAiExtraction::factory()->forDocument($doc)->ready($payload, 1)->create(['started_by' => $user->id]);

        $this->actingAs($user)->postJson("/health/people/{$person->id}/ai/{$extraction->id}/entries/medication-0/accept")->assertOk();

        expect(MedicalMedication::query()->whereNull('archived_at')->count())->toBe(1)->and(MedicalMedication::query()->count())->toBe(2);
    });

    it('leaves archived documents and people out of the bulk eligibility', function () {
        [, $person] = arcEditor(['ai_consent' => true]);
        $doc = MedicalDocument::factory()->image()->create(['subject_id' => $person->id]);
        $bulk = app(AiBulkEligibility::class);
        expect($bulk->counts('all')['eligible'])->toBe(1);

        DB::table('medical_documents')->where('id', $doc->id)->update(['archived_at' => now()]);
        expect($bulk->counts('all')['eligible'])->toBe(0)->and($bulk->nextCandidate('all', (string) Str::uuid()))->toBeNull();

        DB::table('medical_documents')->where('id', $doc->id)->update(['archived_at' => null]);
        DB::table('medical_subjects')->where('id', $person->id)->update(['archived_at' => now()]);
        expect($bulk->counts('all')['eligible'])->toBe(0);
    });
});

describe('the Archive section and restore', function () {
    it('lists archived records grouped for a member with Edit, and archived people', function () {
        [$user, $person] = arcEditor();
        $other = MedicalFixtures::subject(['name' => 'Archived Aunt', 'created_at' => now()->subDays(40)], [[$user, AccessLevel::Edit]]);
        $this->actingAs($user)->delete("/health/people/{$other->id}")->assertSessionHas('toast.outcome', 'archived');
        $records = array_map(fn (string $t) => arcRecord($t, $person), ARC_TYPES);
        foreach ($records as $record) {
            $this->actingAs($user)->delete($record['url'])->assertSessionHas('toast.outcome', 'archived');
        }

        $props = arcProps($user, $person);
        expect(collect($props['archive']['items'])->pluck('type')->sort()->values()->all())->toBe(['allergy', 'document', 'medication', 'vaccination', 'visit'])
            ->and(collect($props['archive']['items'])->pluck('id')->sort()->values()->all())->toBe(collect($records)->pluck('id')->sort()->values()->all())
            ->and($props['archive']['people'])->toBe([['id' => $other->id, 'name' => 'Archived Aunt', 'initials' => 'AA']]);
        $this->actingAs($user)->get("/health/people/{$person->id}?panel=archive")->assertOk();
    });

    it('restores a record, a person and clears the archive columns, with an audit row', function () {
        [$user, $person] = arcEditor();
        $record = arcRecord('allergy', $person);
        $this->actingAs($user)->delete($record['url'])->assertSessionHas('toast.outcome', 'archived');

        $this->actingAs($user)->post("/health/people/{$person->id}/archive/allergy/{$record['id']}/restore")
            ->assertRedirect("/health/people/{$person->id}")->assertSessionHas('toast.key', 'health.archive.restored');
        $row = arcRow($record);
        expect($row->archived_at)->toBeNull()->and($row->archived_by)->toBeNull();
        expect(collect(arcProps($user, $person)['allergies'])->pluck('id')->all())->toBe([$record['id']]);
        expect(AuditEntry::where('event', 'medical_restored')->sole()->meta)->toEqual(['subject' => $person->id, 'type' => 'allergy', 'record' => $record['id']]);

        $person->forceFill(['created_at' => now()->subDays(30)])->save();
        $this->actingAs($user)->delete("/health/people/{$person->id}")->assertSessionHas('toast.outcome', 'archived');
        $this->actingAs($user)->post("/health/people/{$person->id}/restore")->assertRedirect("/health/people/{$person->id}");
        expect(DB::table('medical_subjects')->where('id', $person->id)->value('archived_at'))->toBeNull();
        expect(AuditEntry::where('event', 'medical_restored')->where('meta->type', 'person')->count())->toBe(1);
        $this->actingAs($user)->get("/health/people/{$person->id}")->assertOk();
    });

    it('lets only a member with Edit on that person restore or archive', function () {
        [$viewer, $viewPerson] = arcViewer();
        $record = arcRecord('visit', $viewPerson, 24 * 30);
        DB::table('medical_visits')->where('id', $record['id'])->update(['archived_at' => now()]);
        $viewPerson->forceFill(['archived_at' => null])->save();

        $this->actingAs($viewer)->postJson("/health/people/{$viewPerson->id}/archive/visit/{$record['id']}/restore")->assertForbidden();
        expect(arcRow($record)->archived_at)->not->toBeNull();

        $live = arcRecord('allergy', $viewPerson);
        $this->actingAs($viewer)->deleteJson($live['url'])->assertForbidden();
        expect(arcRow($live)->archived_at)->toBeNull();

        // A view-only member sees no archive at all.
        $props = arcProps($viewer, $viewPerson);
        expect($props['archive'])->toBe(['items' => [], 'people' => []]);
        $this->actingAs($viewer)->get("/health/people/{$viewPerson->id}?panel=archive")->assertForbidden();

        // Neither does a module-Edit user who is only a View member of this person.
        $mixed = User::factory()->create();
        MedicalFixtures::grantHealth($mixed);
        DB::table('medical_subject_members')->insert(['subject_id' => $viewPerson->id, 'user_id' => $mixed->id, 'level' => AccessLevel::View->value, 'created_at' => now()]);
        $this->actingAs($mixed)->postJson("/health/people/{$viewPerson->id}/archive/visit/{$record['id']}/restore")->assertForbidden();
        expect(arcProps($mixed, $viewPerson)['archive']['items'])->toBe([]);
    });

    it('answers the same 403 for a hidden, unknown, malformed or not archived id and an unknown type', function () {
        [$user, $person] = arcEditor();
        $live = arcRecord('visit', $person);
        [, $hiddenPerson] = arcEditor();
        $hiddenRecord = arcRecord('visit', $hiddenPerson);
        DB::table('medical_visits')->where('id', $hiddenRecord['id'])->update(['archived_at' => now()]);

        $urls = [
            "/health/people/{$person->id}/archive/visit/{$live['id']}/restore", // active record
            "/health/people/{$person->id}/archive/visit/{$hiddenRecord['id']}/restore", // another person's
            "/health/people/{$hiddenPerson->id}/archive/visit/{$hiddenRecord['id']}/restore", // hidden person
            '/health/people/'.Str::uuid7()."/archive/visit/{$live['id']}/restore",
            "/health/people/{$person->id}/archive/visit/abc/restore",
            "/health/people/{$person->id}/archive/nonsense/{$live['id']}/restore",
            "/health/people/{$hiddenPerson->id}/restore", // active person
            '/health/people/abc/restore',
        ];
        $shapes = array_map(fn (string $url) => ($r = $this->actingAs($user)->postJson($url))->getStatusCode().'|'.$r->json('message'), $urls);
        expect(array_unique($shapes))->toHaveCount(1)->and($shapes[0])->toStartWith('403|');
        expect(arcRow($hiddenRecord)->archived_at)->not->toBeNull();
    });

    it('leaks nothing of a hidden person archived rows and keeps audit rows content-free', function () {
        [$user, $person] = arcEditor();
        [, $hiddenPerson] = arcEditor(['name' => ARC_CANARY.' Hidden person']);
        foreach (ARC_TYPES as $type) {
            $record = arcRecord($type, $hiddenPerson);
            DB::table($record['table'])->where($record['key'], $record['id'])->update(['archived_at' => now()]);
        }
        $hiddenPerson->forceFill(['archived_at' => now()])->save();
        $mine = arcRecord('visit', $person);
        $this->actingAs($user)->delete($mine['url'])->assertSessionHas('toast.outcome', 'archived');

        $props = arcProps($user, $person);
        expect(json_encode(Arr::except($props, 'archive')))->not->toContain(ARC_CANARY)->and(json_encode($props['archive']))->not->toContain('Hidden person')->and($props['archive']['people'])->toBe([]);
        $this->actingAs($user)->get('/health')->assertRedirect("/health/people/{$person->id}");

        // The audit chain holds ids and enum values only, never a title, a name or a diagnosis.
        $audit = json_encode(DB::table('audit_logs')->get());
        expect($audit)->not->toContain(ARC_CANARY);
        foreach (AuditEntry::whereIn('event', ['medical_archived', 'medical_restored'])->get() as $entry) {
            expect(array_keys((array) $entry->meta))->each->toBeIn(AuditEvent::from($entry->event)->metaKeys());
        }
    });

    it('does not let a restore of one person touch another', function () {
        [$user, $person] = arcEditor();
        [$otherUser, $otherPerson] = arcEditor();
        $record = arcRecord('visit', $otherPerson);
        DB::table('medical_visits')->where('id', $record['id'])->update(['archived_at' => now()]);

        $this->actingAs($user)->postJson("/health/people/{$person->id}/archive/visit/{$record['id']}/restore")->assertForbidden();
        expect(arcRow($record)->archived_at)->not->toBeNull();
        $this->actingAs($otherUser)->postJson("/health/people/{$otherPerson->id}/archive/visit/{$record['id']}/restore")->assertRedirect();
        expect(arcRow($record)->archived_at)->toBeNull();
    });
});

describe('storage and caps', function () {
    it('keeps the files of an archived document through medical:prune-orphans and counts it as stored', function () {
        [$user, $person] = arcEditor();
        $doc = MedicalDocument::factory()->image()->create(['subject_id' => $person->id, 'created_at' => now()->subDays(30)]);
        foreach ([$doc->path, $doc->thumb_path] as $p) {
            Storage::disk('medical')->put($p, MedicalFileFixtures::pdf());
            touch(Storage::disk('medical')->path($p), time() - 86400);
        }
        $this->actingAs($user)->delete("/health/people/{$person->id}/documents/{$doc->id}")->assertSessionHas('toast.outcome', 'archived');

        $this->artisan('medical:prune-orphans')->expectsOutput('Pruned 0 orphan files and 0 directories.')->assertExitCode(0);

        expect(Storage::disk('medical')->exists($doc->path))->toBeTrue()->and(Storage::disk('medical')->exists($doc->thumb_path))->toBeTrue();
        // The circuit breaker still sees the archived row as inventory: an empty-looking table does not appear.
        expect(app(SystemQuery::class)->hasDocuments())->toBeTrue()
            ->and(app(SystemQuery::class)->knownPaths([$doc->path, $doc->thumb_path]))->toHaveCount(2)
            ->and(app(SystemQuery::class)->existingSubjectIds([$person->id]))->toBe([$person->id]);
    });

    it('keeps the directory of an archived person', function () {
        [$user, $person] = arcEditor();
        $person->forceFill(['created_at' => now()->subDays(30)])->save();
        $disk = Storage::disk('medical');
        $disk->makeDirectory($person->id);
        touch($disk->path($person->id), time() - 7200);
        $this->actingAs($user)->delete("/health/people/{$person->id}")->assertSessionHas('toast.outcome', 'archived');

        $this->artisan('medical:prune-orphans')->assertExitCode(0);

        expect($disk->directoryExists($person->id))->toBeTrue();
    });

    it('counts archived documents towards the per-person cap, but not in the shown counts (PROVISIONAL)', function () {
        [$user, $person] = arcEditor();
        $viewer = Viewer::for($user);
        $doc = arcRecord('document', $person);
        $this->actingAs($user)->delete($doc['url'])->assertSessionHas('toast.outcome', 'archived');
        arcRecord('document', $person);

        $documents = app(DocumentQuery::class);
        expect($documents->countForSubject($viewer, $person))->toBe(1)->and($documents->countStoredForSubject($viewer, $person))->toBe(2);
        config(['medical.max_documents_per_subject' => 2]);
        expect(fn () => $documents->create($viewer, $person, ['kind' => 'pdf', 'mime' => 'application/pdf', 'size_bytes' => 1, 'sha256' => str_repeat('a', 64), 'path' => 'x/y.pdf', 'thumb_path' => null, 'width' => null, 'height' => null], 'New', null))
            ->toThrow(DocumentRejected::class);
    });

    it('scopes every archive and restore statement with the membership predicate', function () {
        [$user, $person] = arcEditor();
        $record = arcRecord('visit', $person);
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        $viewer = Viewer::for($user);

        app(ArchiveQuery::class)->remove($viewer, $person, 'visit', $record['id']);
        app(ArchiveQuery::class)->restore($viewer, $person, 'visit', $record['id']);
        app(ArchiveQuery::class)->forSubject($viewer, $person);
        app(SubjectQuery::class)->archived($viewer);

        $medical = array_filter($queries, fn ($s) => preg_match('/medical_(subjects|visits|medications|allergies|vaccinations|documents)/i', $s) === 1);
        expect($medical)->not->toBeEmpty()->and(MedicalPrivacy::unscopedReads(array_values($medical)))->toBe([]);
    });
});
