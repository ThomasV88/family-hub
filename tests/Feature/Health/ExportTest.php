<?php

use App\Access\AccessLevel;
use App\Audit\AuditLogger;
use App\Models\AuditEntry;
use App\Models\MedicalAllergy;
use App\Models\MedicalSubject;
use App\Models\MedicalVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FailingAuditLogger;
use Tests\Support\MedicalFixtures;
use Tests\Support\MedicalPrivacy;

uses(RefreshDatabase::class);

/** @return array{0: User, 1: MedicalSubject} an edit member and a person they can change */
function exportEditor(array $subject = []): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user);

    return [$user, MedicalFixtures::subject($subject, [[$user, AccessLevel::Edit]])];
}

function exportUrl(string $id, string $route = ''): string
{
    return "/health/people/{$id}/export".($route === '' ? '' : "/{$route}");
}

describe('form', function () {
    it('renders for an editor with the used specialties and the unverified flag', function () {
        [$user, $person] = exportEditor();
        MedicalVisit::factory()->forSubject($person)->specialty('cardiology')->create();

        $this->actingAs($user)->get(exportUrl($person->id))->assertInertia(fn ($page) => $page
            ->component('Health/Export')
            ->where('person.id', $person->id)
            ->where('specialtiesWithVisits.0.value', 'cardiology')
            ->where('hasUnverified', false));

        MedicalAllergy::factory()->forSubject($person)->create(['source' => 'ai', 'verified_at' => null]);
        $this->actingAs($user)->get(exportUrl($person->id))->assertInertia(fn ($page) => $page->where('hasUnverified', true));
    });

    it('answers hidden, unknown, malformed and view-only identically with 403 on every export route', function () {
        [$user] = exportEditor();
        $hidden = MedicalFixtures::subject(['name' => 'Hidden Hana']);
        $viewOnly = MedicalFixtures::subject([], [[$user, AccessLevel::View]]);
        foreach (['', 'print', 'download'] as $route) {
            $bodies = [];
            foreach ([$hidden->id, (string) Str::uuid(), 'abc', $viewOnly->id] as $id) {
                $response = $this->actingAs($user)->get(exportUrl($id, $route));
                $response->assertForbidden();
                $bodies[] = Str::of($response->getContent())->replace($id, '')->toString();
                expect($response->getContent())->not->toContain('Hidden Hana');
            }
            expect(array_unique($bodies))->toHaveCount(1);
        }
        expect(AuditEntry::where('event', 'medical_exported')->count())->toBe(0);
    });

    it('does not let a view-level module member export even a visible person', function () {
        $user = User::factory()->create();
        MedicalFixtures::grantHealth($user, AccessLevel::View);
        $person = MedicalFixtures::subject([], [[$user, AccessLevel::Edit]]);

        $this->actingAs($user)->get(exportUrl($person->id, 'download'))->assertForbidden();
        $this->actingAs($user)->get(exportUrl($person->id))->assertForbidden();
    });

    it('gives a hidden id 403 before validation and a visible person 422 for bad options', function () {
        [$user, $person] = exportEditor();
        $hidden = MedicalFixtures::subject();

        $this->actingAs($user)->getJson(exportUrl($hidden->id, 'download').'?format=exe')->assertForbidden();
        $this->actingAs($user)->getJson(exportUrl($person->id, 'download').'?format=exe')->assertStatus(422)->assertJsonValidationErrors('format');
        $this->actingAs($user)->getJson(exportUrl($person->id, 'download').'?scope=specialty')->assertStatus(422)->assertJsonValidationErrors('specialty');
        $this->actingAs($user)->getJson(exportUrl($person->id, 'download').'?scope=specialty&specialty=nope')->assertStatus(422)->assertJsonValidationErrors('specialty');
    });
});

describe('download', function () {
    it('streams JSON with safe headers and no person name in the filename', function () {
        [$user, $person] = exportEditor(['name' => 'Zorka Canaryova']);
        MedicalAllergy::factory()->forSubject($person)->create(['substance' => 'Peanuts']);

        $response = $this->actingAs($user)->get(exportUrl($person->id, 'download').'?format=json&scope=all');
        $response->assertOk();
        expect($response->headers->get('Content-Type'))->toBe('application/json; charset=utf-8')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
            ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename=health-export-all-'.now((string) config('family.timezone'))->toDateString().'.json');
        $json = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        expect($json['allergies'][0]['substance'])->toBe('Peanuts')->and($json['subject']['name'])->toBe('Zorka Canaryova');
    });

    it('streams anonymized Markdown for one specialty', function () {
        [$user, $person] = exportEditor(['name' => 'Zorka Canaryova', 'birth_date' => '2014-03-09']);
        MedicalVisit::factory()->forSubject($person)->specialty('cardiology')->create(['doctor' => 'Dr. Heart']);
        MedicalVisit::factory()->forSubject($person)->specialty('dermatology')->create(['doctor' => 'Dr. Skin']);

        $response = $this->actingAs($user)->get(exportUrl($person->id, 'download').'?format=md&scope=specialty&specialty=cardiology');
        $response->assertOk();
        expect($response->headers->get('Content-Type'))->toBe('text/markdown; charset=utf-8')
            ->and($response->headers->get('Content-Disposition'))->toContain('health-export-cardiology-')->toContain('.md')->not->toContain('Zorka');
        $body = $response->getContent();
        expect($body)->toContain('Dr. Heart')->not->toContain('Dr. Skin')->not->toContain('Zorka')->not->toContain('2014-03-09');
    });

    it('leaves unverified items out unless asked', function () {
        [$user, $person] = exportEditor();
        MedicalAllergy::factory()->forSubject($person)->create(['substance' => 'Latex', 'source' => 'ai', 'verified_at' => null]);

        expect($this->actingAs($user)->get(exportUrl($person->id, 'download').'?format=md')->getContent())->not->toContain('Latex');
        $with = $this->actingAs($user)->get(exportUrl($person->id, 'download').'?format=md&include_unverified=1')->getContent();
        expect($with)->toContain('[UNVERIFIED] Latex')->and(strtok($with, "\n"))->toContain('UNVERIFIED');
    });

    it('writes the audit row first and produces nothing when the audit write fails', function () {
        [$user, $person] = exportEditor();
        MedicalAllergy::factory()->forSubject($person)->create(['substance' => 'Peanuts']);

        $this->actingAs($user)->get(exportUrl($person->id, 'download').'?format=md&scope=all')->assertOk();
        $this->actingAs($user)->get(exportUrl($person->id, 'download').'?format=json&scope=all')->assertOk();
        $rows = AuditEntry::where('event', 'medical_exported')->orderBy('id')->get();
        expect($rows)->toHaveCount(2)
            ->and($rows[0]->meta)->toEqual(['subject' => $person->id, 'scope' => 'person', 'format' => 'md'])
            ->and($rows[1]->meta)->toEqual(['subject' => $person->id, 'scope' => 'person', 'format' => 'json']);

        app()->instance(AuditLogger::class, new FailingAuditLogger);
        $this->withoutExceptionHandling();
        foreach (['download', 'print'] as $route) {
            try {
                $this->actingAs($user)->get(exportUrl($person->id, $route));
                $this->fail('the export must not be produced');
            } catch (RuntimeException $e) {
                expect($e->getMessage())->toBe('audit write failed');
            }
        }
    });

    it('records the specialty scope and the print format', function () {
        [$user, $person] = exportEditor();
        $this->actingAs($user)->get(exportUrl($person->id, 'print').'?scope=specialty&specialty=cardiology')->assertOk();

        expect(AuditEntry::where('event', 'medical_exported')->first()->meta)->toEqual(['subject' => $person->id, 'scope' => 'specialty', 'format' => 'html']);
    });

    it('stores nothing: fake disks stay empty', function () {
        [$user, $person] = exportEditor();
        MedicalVisit::factory()->forSubject($person)->create();
        Storage::fake('medical');
        Storage::fake('local');
        Storage::fake('public');

        $this->actingAs($user)->get(exportUrl($person->id, 'download').'?format=json')->assertOk();
        $this->actingAs($user)->get(exportUrl($person->id, 'download').'?format=md')->assertOk();
        $this->actingAs($user)->get(exportUrl($person->id, 'print'))->assertOk();

        foreach (['medical', 'local', 'public'] as $disk) {
            expect(Storage::disk($disk)->allFiles())->toBe([]);
        }
    });

    it('exports 500 visits in both formats with flat memory', function () {
        [$user, $person] = exportEditor();
        $now = now()->toDateTimeString();
        $rows = [];
        for ($i = 0; $i < 500; $i++) {
            $rows[] = ['id' => (string) Str::uuid(), 'subject_id' => $person->id, 'visited_on' => '2026-01-10', 'doctor' => "Dr. {$i}", 'specialty_key' => 'general_practice', 'source' => 'manual', 'notes' => str_repeat('n', 200), 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('medical_visits')->insert($rows);
        $this->actingAs($user)->get(exportUrl($person->id, 'download').'?format=json')->assertOk();

        gc_collect_cycles();
        $before = memory_get_usage();
        $json = $this->actingAs($user)->get(exportUrl($person->id, 'download').'?format=json');
        $md = $this->actingAs($user)->get(exportUrl($person->id, 'download').'?format=md');
        $growth = memory_get_peak_usage() - $before;

        expect(json_decode($json->getContent(), true)['visits'])->toHaveCount(500)
            ->and(substr_count($md->getContent(), '### '))->toBeGreaterThanOrEqual(500)
            ->and($growth)->toBeLessThan(32 * 1024 * 1024);
    });
});

describe('print', function () {
    it('renders the print page with the person name, labeled items and no clock', function () {
        [$user, $person] = exportEditor(['name' => 'Zorka Canaryova']);
        MedicalAllergy::factory()->forSubject($person)->create(['substance' => 'Latex', 'source' => 'ai', 'verified_at' => null]);

        $this->actingAs($user)->get(exportUrl($person->id, 'print'))->assertInertia(fn ($page) => $page
            ->component('Health/ExportPrint')
            ->where('export.subject.name', 'Zorka Canaryova')
            ->where('export.allergies', [])
            ->missing('export.generated_at'));
        $this->actingAs($user)->get(exportUrl($person->id, 'print').'?include_unverified=1')->assertInertia(fn ($page) => $page
            ->where('export.allergies.0.verification', 'unverified')
            ->where('export.unverified_notice', fn ($v) => is_string($v)));
    });
});

describe('privacy net', function () {
    it('has the three export surfaces in the medical privacy differential', function () {
        $names = collect(MedicalPrivacy::surfaces())->map->getName()->all();

        expect($names)->toContain('health.exports.show', 'health.exports.print', 'health.exports.download');
        expect(array_keys(MedicalPrivacy::areas()['panels']))->toContain('health.exports.print', 'health.exports.download');
    });
});

describe('source', function () {
    it('has no storage or cache calls and no v-html in the export surfaces', function () {
        $php = collect(glob(base_path('app/Medical/Export/*.php')))->push(base_path('app/Http/Controllers/Health/ExportController.php'))
            ->map(fn ($f) => file_get_contents($f))->implode("\n");
        expect($php)->not->toContain('Storage::')->not->toContain('Cache::');
        foreach (['Pages/Health/Export.vue', 'Pages/Health/ExportPrint.vue', 'Layouts/PrintLayout.vue'] as $file) {
            $vue = (string) @file_get_contents(resource_path("js/{$file}"));
            expect($vue)->not->toContain('v-html')->not->toContain('remember');
        }
    });
});
