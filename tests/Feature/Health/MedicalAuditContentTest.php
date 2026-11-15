<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Jobs\SendOutboxItem;
use App\Models\AuditEntry;
use App\Models\MedicalSubject;
use App\Models\MedicalVaccination;
use App\Models\OutboxItem;
use App\Models\User;
use App\Notifications\NotificationPreferences;
use App\Notifications\Outbox\OutboxHandlers;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFixtures;
use Tests\Support\MedicalFileFixtures;
use Tests\Support\MedicalFixtures;

// Canary scan (T-07-98): every Phase 7 flow runs with recognisable names, diagnoses, titles, file names and phone
// numbers; afterwards no audit_logs column, log record or outbox column may contain any of them. The expected medical
// events are asserted present so the scan cannot pass on an empty flow.

const MAC_PERSON = 'ZxCanaryAudit Personname';
const MAC_CONTACT = 'ZxCanaryAudit Contactname';
const MAC_PHONE = '+421 999 888 777';
const MAC_DIAGNOSIS = 'ZxCanaryAudit diagnosis';
const MAC_DOCTOR = 'ZxCanaryAudit doctor';
const MAC_NOTE = 'ZxCanaryAudit note';
const MAC_ALLERGY = 'ZxCanaryAudit allergen';
const MAC_MEDICATION = 'ZxCanaryAudit medicine';
const MAC_VACCINE = 'ZxCanaryAudit vaccine';
const MAC_FILE = 'ZxCanaryAudit scanfile';
const MAC_RENAMED = 'ZxCanaryAudit renamed title';
const MAC_PDF = 'ZxCanaryAudit report';

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    Storage::fake('medical');
    Carbon::setTestNow(Carbon::parse('2027-03-01 08:30', 'Europe/Bratislava'));
    config(['mail.default' => 'array']);
});

afterEach(fn () => Carbon::setTestNow());

function macNeedles(): array
{
    return [
        'ZxCanaryAudit', MAC_PERSON, MAC_CONTACT, MAC_PHONE, '999 888 777', MAC_DIAGNOSIS, MAC_DOCTOR, MAC_NOTE, MAC_ALLERGY,
        MAC_MEDICATION, MAC_VACCINE, MAC_FILE, MAC_RENAMED, MAC_PDF, 'Renamedperson',
    ];
}

it('keeps names, diagnoses, titles, file names and phone numbers out of audit rows, logs and the outbox across every flow', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e->message.' '.json_encode($e->context, JSON_PARTIAL_OUTPUT_ON_ERROR).' '.
            (($e->context['exception'] ?? null) instanceof Throwable ? $e->context['exception']->getMessage() : '');
    });

    $admin = User::factory()->admin()->withGrant(Module::Health, AccessLevel::Edit)->create(['email' => 'boss@example.test', 'name' => 'Boss']);
    $parent = User::factory()->create(['email' => 'anna@example.test', 'name' => 'Anna', 'locale' => 'en']);
    $linked = User::factory()->create(['email' => 'kid@example.test', 'name' => 'Kid']);
    foreach ([$parent, $linked] as $u) {
        MedicalFixtures::grantHealth($u);
    }
    $actAs = function (User $user) {
        app('auth')->forgetGuards();
        $this->flushSession();

        return $this->actingAs($user);
    };

    // --- person with contacts, edit --------------------------------------------------------------------------
    $actAs($parent)->post('/health/people', ['name' => MAC_PERSON, 'notes' => MAC_NOTE, 'contacts' => [['name' => MAC_CONTACT, 'relation' => 'Mum', 'phone' => MAC_PHONE]]])->assertRedirect();
    $person = MedicalSubject::query()->sole();
    $id = $person->getKey();
    $actAs($parent)->patch("/health/people/{$id}", ['name' => MAC_PERSON.' Renamedperson', 'notes' => MAC_NOTE, 'contacts' => [['name' => MAC_CONTACT, 'phone' => MAC_PHONE]]])->assertRedirect();

    // --- visits, allergies, medications, vaccinations -----------------------------------------------------------
    $actAs($parent)->post("/health/people/{$id}/visits", ['visited_on' => '2026-01-10', 'doctor' => MAC_DOCTOR, 'specialty_key' => 'cardiology', 'diagnosis' => MAC_DIAGNOSIS, 'notes' => MAC_NOTE])->assertRedirect();
    $visit = DB::table('medical_visits')->value('id');
    $actAs($parent)->patch("/health/people/{$id}/visits/{$visit}", ['visited_on' => '2026-01-11', 'doctor' => MAC_DOCTOR, 'specialty_key' => 'other', 'specialty_other' => MAC_NOTE, 'diagnosis' => MAC_DIAGNOSIS])->assertRedirect();

    $actAs($parent)->post("/health/people/{$id}/allergies", ['substance' => MAC_ALLERGY, 'reaction' => MAC_NOTE, 'severity' => 'severe'])->assertRedirect();
    $allergy = DB::table('medical_allergies')->value('id');
    $actAs($parent)->patch("/health/people/{$id}/allergies/{$allergy}", ['substance' => MAC_ALLERGY, 'reaction' => null, 'severity' => 'mild'])->assertRedirect();

    $actAs($parent)->post("/health/people/{$id}/medications", ['name' => MAC_MEDICATION, 'dose' => '5 mg', 'schedule' => MAC_NOTE, 'started_on' => '2026-01-10'])->assertRedirect();
    $medication = DB::table('medical_medications')->value('id');
    $actAs($parent)->patch("/health/people/{$id}/medications/{$medication}", ['name' => MAC_MEDICATION, 'dose' => null, 'started_on' => '2026-01-10', 'ended_on' => '2026-02-01'])->assertRedirect();

    $actAs($parent)->post("/health/people/{$id}/vaccinations", ['vaccine' => MAC_VACCINE, 'given_on' => '2026-01-10', 'next_due_on' => '2027-03-15', 'notes' => MAC_NOTE])->assertRedirect();
    $vaccination = MedicalVaccination::query()->sole();
    $actAs($parent)->patch("/health/people/{$id}/vaccinations/{$vaccination->public_id}", ['vaccine' => MAC_VACCINE, 'given_on' => '2026-01-10', 'next_due_on' => '2027-03-15', 'notes' => null])->assertRedirect();

    // --- reminders: enqueue while a due date is open, send the mail ---------------------------------------------
    Artisan::call('medical:enqueue-reminders', ['--force' => true]);
    $outbox = OutboxItem::query()->get();
    expect($outbox)->not->toBeEmpty();
    foreach ($outbox as $row) {
        $row->forceFill(['status' => 'queued'])->save();
        (new SendOutboxItem($row->id))->handle(app(OutboxHandlers::class), app(NotificationPreferences::class));
    }

    $actAs($parent)->post("/health/people/{$id}/vaccinations/{$vaccination->public_id}/given", ['given_on' => '2027-03-01'])->assertRedirect();

    // --- uploads (canary title, file name), rename, delete ------------------------------------------------------
    $jpeg = $actAs($parent)->post("/health/people/{$id}/documents", ['file' => UploadedFile::fake()->createWithContent(MAC_FILE.'.jpg', ImageFixtures::jpeg())], ['Accept' => 'application/json'])->assertCreated();
    $pdf = $actAs($parent)->post("/health/people/{$id}/documents", ['file' => UploadedFile::fake()->createWithContent(MAC_PDF.'.pdf', MedicalFileFixtures::pdf(MAC_NOTE))], ['Accept' => 'application/json'])->assertCreated();
    $docId = $jpeg->json('document.id');
    $pdfId = $pdf->json('document.id');
    $actAs($parent)->patchJson("/health/people/{$id}/documents/{$docId}", ['title' => MAC_RENAMED])->assertRedirect();
    $actAs($parent)->get("/health/people/{$id}/documents/{$docId}/preview/thumb");
    $actAs($parent)->get("/health/people/{$id}/documents/{$pdfId}/file");
    $actAs($parent)->deleteJson("/health/people/{$id}/documents/{$pdfId}")->assertRedirect();

    // --- consent, access grant, link, admin self-access ---------------------------------------------------------
    $actAs($parent)->patch("/health/people/{$id}/ai-consent", ['value' => true])->assertRedirect();
    $actAs($parent)->patch("/health/people/{$id}/ai-consent", ['value' => false])->assertRedirect();
    $actAs($admin)->put("/settings/health-people/{$id}/members", ['levels' => [$parent->id => 'edit', $linked->id => 'view']])->assertRedirect();
    $actAs($admin)->put("/settings/health-people/{$id}/link", ['user_id' => $linked->id, 'self_access_enabled' => true])->assertRedirect();
    $actAs($admin)->put("/settings/health-people/{$id}/link", ['user_id' => $linked->id, 'self_access_enabled' => false])->assertRedirect();
    $actAs($admin)->put("/settings/health-people/{$id}/members", ['levels' => [$parent->id => 'edit', $linked->id => 'view', $admin->id => 'view']])->assertRedirect();

    // --- exports (print, json, md) and the emergency card, then a refusal with canary input -----------------------
    $actAs($parent)->get("/health/people/{$id}/export/print?scope=all&include_unverified=1")->assertOk();
    $actAs($parent)->get("/health/people/{$id}/export/download?scope=all&format=json")->assertOk();
    $actAs($parent)->get("/health/people/{$id}/export/download?scope=all&format=md")->assertOk();
    $actAs($parent)->get("/health/people/{$id}/emergency-card")->assertOk();
    $actAs($parent)->post("/health/people/{$id}/visits", ['visited_on' => 'not a date '.MAC_DIAGNOSIS, 'specialty_key' => 'zz '.MAC_NOTE])->assertSessionHasErrors();

    // --- destructive: visit, allergy, medication, vaccination, document, person -----------------------------------
    $actAs($parent)->delete("/health/people/{$id}/visits/{$visit}")->assertRedirect();
    $actAs($parent)->delete("/health/people/{$id}/allergies/{$allergy}")->assertRedirect();
    $actAs($parent)->delete("/health/people/{$id}/medications/{$medication}")->assertRedirect();
    $actAs($parent)->delete("/health/people/{$id}/vaccinations/{$vaccination->public_id}")->assertRedirect();
    $actAs($parent)->delete("/health/people/{$id}")->assertRedirect('/health');

    // --- the scan ---------------------------------------------------------------------------------------------------
    $events = AuditEntry::query()->pluck('event')->map(fn ($e) => $e instanceof BackedEnum ? $e->value : (string) $e)->unique()->all();
    expect($events)->toContain(
        'medical_subject_created', 'medical_subject_deleted', 'medical_access_changed', 'medical_admin_self_added', 'medical_exported', 'medical_consent_changed',
    );
    expect(AuditEntry::query()->where('event', 'medical_exported')->count())->toBeGreaterThanOrEqual(4);

    $audit = json_encode(DB::table('audit_logs')->get());
    $outboxDump = json_encode(DB::table('notification_outbox')->get());
    expect($audit)->not->toBeFalse()->and(strlen($outboxDump))->toBeGreaterThan(10);
    foreach (macNeedles() as $needle) {
        expect(str_contains($audit, $needle))->toBeFalse("'$needle' in audit_logs");
        expect(str_contains($outboxDump, $needle))->toBeFalse("'$needle' in notification_outbox");
        foreach ($logged as $record) {
            expect(str_contains($record, $needle))->toBeFalse("'$needle' in a log record: ".substr($record, 0, 200));
        }
    }
    // Per column, so a future column cannot hide behind a JSON blob (the outbox keys carry ids only).
    foreach (DB::table('notification_outbox')->get() as $row) {
        foreach (['payload', 'dedupe_key', 'last_error'] as $column) {
            foreach (macNeedles() as $needle) {
                expect(str_contains((string) $row->{$column}, $needle))->toBeFalse("'$needle' in outbox $column");
            }
        }
    }
});
