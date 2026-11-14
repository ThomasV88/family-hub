<?php

use App\Access\AccessLevel;
use App\Audit\AuditLogger;
use App\Models\AuditEntry;
use App\Models\MedicalAllergy;
use App\Models\MedicalContact;
use App\Models\MedicalMedication;
use App\Models\MedicalSubject;
use App\Models\MedicalVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\FailingAuditLogger;
use Tests\Support\MedicalFixtures;
use Tests\Support\MedicalPrivacy;

uses(RefreshDatabase::class);

/** @return array{0: User, 1: MedicalSubject} */
function emergencyMember(AccessLevel $level = AccessLevel::View, array $subject = []): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user, $level);

    return [$user, MedicalFixtures::subject($subject, [[$user, $level]])];
}

function cardUrl(string $id): string
{
    return "/health/people/{$id}/emergency-card";
}

describe('card', function () {
    it('renders exactly the contract props for view and edit members', function () {
        foreach ([AccessLevel::View, AccessLevel::Edit] as $level) {
            [$user, $person] = emergencyMember($level, ['name' => 'Anna Test', 'birth_date' => '2000-05-05', 'blood_type' => 'A+', 'notes' => 'PRIVATE NOTE']);
            MedicalAllergy::factory()->forSubject($person)->severity('mild')->create(['substance' => 'Pollen']);
            MedicalAllergy::factory()->forSubject($person)->severity('severe')->create(['substance' => 'Peanuts']);
            MedicalContact::factory()->forSubject($person)->create(['name' => 'Mum', 'relation' => 'Mother', 'phone' => '+421 900 000 000', 'sort' => 0]);
            MedicalVisit::factory()->forSubject($person)->create(['diagnosis' => 'SECRET DIAGNOSIS', 'notes' => 'SECRET VISIT NOTE']);

            $response = $this->actingAs($user)->get(cardUrl($person->id));
            $response->assertOk()->assertInertia(fn ($page) => $page
                ->component('Health/EmergencyCard')
                ->where('person.id', $person->id)
                ->where('person.name', 'Anna Test')
                ->where('person.birthDate', '2000-05-05')
                ->where('person.bloodType', 'A+')
                ->has('person.ageYears')
                ->where('allergies.0.substance', 'Peanuts')
                ->where('allergies.0.severity', 'severe')
                ->where('allergies.1.substance', 'Pollen')
                ->where('contacts.0', ['name' => 'Mum', 'relation' => 'Mother', 'phone' => '+421 900 000 000'])
                ->where('printedOn', now((string) config('family.timezone'))->toDateString())
                ->has('person', 5)
                ->has('allergies.0', 3));
            $body = $response->getContent();
            expect($body)->not->toContain('PRIVATE NOTE')->and($body)->not->toContain('SECRET DIAGNOSIS')->and($body)->not->toContain('SECRET VISIT NOTE');
            expect($response->headers->get('Cache-Control'))->toContain('no-store');
        }
    });

    it('lists medications taken today: an ended one is absent, one ending today is present', function () {
        [$user, $person] = emergencyMember();
        $today = now((string) config('family.timezone'))->toDateString();
        MedicalMedication::factory()->forSubject($person)->ongoing()->create(['name' => 'Ongoing med', 'dose' => '5 mg', 'schedule' => 'daily']);
        MedicalMedication::factory()->forSubject($person)->ended($today)->create(['name' => 'Ends today']);
        MedicalMedication::factory()->forSubject($person)->ended(now((string) config('family.timezone'))->subDay()->toDateString())->create(['name' => 'Ended yesterday']);

        $this->actingAs($user)->get(cardUrl($person->id))->assertInertia(fn ($page) => $page
            ->has('medications', 2)
            ->where('medications.0.name', 'Ends today')
            ->where('medications.1', ['name' => 'Ongoing med', 'dose' => '5 mg', 'schedule' => 'daily']));
    });

    it('handles empty data', function () {
        [$user, $person] = emergencyMember(AccessLevel::View, ['birth_date' => null, 'blood_type' => null]);

        $this->actingAs($user)->get(cardUrl($person->id))->assertInertia(fn ($page) => $page
            ->where('person.ageYears', null)->where('person.bloodType', null)
            ->where('allergies', [])->where('medications', [])->where('contacts', []));
    });
});

describe('audit', function () {
    it('writes one medical_exported row with scope emergency per open', function () {
        [$user, $person] = emergencyMember();
        $this->actingAs($user)->get(cardUrl($person->id))->assertOk();

        $rows = AuditEntry::where('event', 'medical_exported')->get();
        expect($rows)->toHaveCount(1)
            ->and($rows[0]->meta)->toEqual(['subject' => $person->id, 'scope' => 'emergency', 'format' => 'html']);
    });

    it('does not render the card when the audit write fails', function () {
        [$user, $person] = emergencyMember();
        app()->instance(AuditLogger::class, new FailingAuditLogger);
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($user)->get(cardUrl($person->id));
            $this->fail('the card must not be rendered');
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toBe('audit write failed');
        }
    });
});

describe('access', function () {
    it('answers hidden, unknown and malformed ids with the identical 403 and no audit row', function () {
        [$user] = emergencyMember();
        $hidden = MedicalFixtures::subject();
        $responses = [];
        foreach ([$hidden->id, (string) Str::uuid(), 'abc'] as $id) {
            $response = $this->actingAs($user)->get(cardUrl($id));
            $response->assertForbidden();
            $responses[] = MedicalPrivacy::normalise($response);
        }
        expect(array_unique($responses))->toHaveCount(1)
            ->and(AuditEntry::where('event', 'medical_exported')->count())->toBe(0);
    });

    it('refuses a member without the health module', function () {
        $user = User::factory()->create();
        $person = MedicalFixtures::subject([], [[$user, AccessLevel::View]]);

        $this->actingAs($user)->get(cardUrl($person->id))->assertForbidden();
    });

    it('redirects a guest to login', function () {
        $person = MedicalFixtures::subject();

        $this->get(cardUrl($person->id))->assertRedirect();
    });
});

describe('no public path', function () {
    it('has no signed or temporary url code in the medical and health controller code', function () {
        $files = [];
        foreach ([app_path('Medical'), app_path('Http/Controllers/Health')] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        expect($files)->not->toBeEmpty();
        foreach ($files as $path) {
            expect(file_get_contents($path))->not->toMatch('/signedRoute|temporarySignedRoute|hasValidSignature|URL::signed/');
        }
    });

    it('is registered once, behind auth and view access', function () {
        $route = app('router')->getRoutes()->getByName('health.emergency.show');
        expect($route)->not->toBeNull()
            ->and($route->gatherMiddleware())->toContain('access:module,health,view');
        expect(array_keys(MedicalPrivacy::areas()['params']))->toContain('person');
        $names = array_map(fn ($r) => $r->getName(), MedicalPrivacy::surfaces());
        expect($names)->toContain('health.emergency.show');
    });
});
