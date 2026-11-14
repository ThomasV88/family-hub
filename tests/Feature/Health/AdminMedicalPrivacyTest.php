<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\MedicalFixtures;
use Tests\Support\MedicalPrivacy;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

/**
 * An admin with a Health edit grant who is a member of no subject (the scenario's visible subject A has the two parents).
 *
 * @return array<string, mixed>
 */
function medApScenario(): array
{
    $ctx = MedicalPrivacy::scenario();
    $admin = User::factory()->admin()->withGrant(Module::Health, AccessLevel::Edit)->create(['name' => 'Ada Admin']);
    $ctx['viewer'] = $admin;
    MedicalPrivacy::seedVisible($ctx);
    MedicalPrivacy::seedHidden($ctx);

    return $ctx;
}

it('shows person names only on Settings > Members and nothing medical anywhere else', function () {
    $ctx = medApScenario();
    $captured = MedicalPrivacy::capture($this, $ctx['viewer'], $ctx);

    expect($captured)->not->toBeEmpty();
    $settingsKeys = 0;
    foreach ($captured as $key => $body) {
        $scan = MedicalPrivacy::scannable($body);
        foreach (MedicalPrivacy::CANARY_NEEDLES as $needle) {
            expect(str_contains($scan, $needle) && ! str_contains($key, '/settings/members'))->toBeFalse("canary '$needle' in $key");
        }
        if (str_contains($key, '/settings/members')) {
            $settingsKeys++;

            continue;
        }
        // Not even the visible person: the admin has no membership on any subject.
        expect(str_contains($scan, 'Visible Person'))->toBeFalse("subject name in $key")
            ->and(str_contains($scan, (string) $ctx['hidden']['person']))->toBeFalse("hidden id in $key")
            ->and(str_contains($scan, (string) $ctx['person']->getKey()))->toBeFalse("subject id in $key");
    }
    expect($settingsKeys)->toBeGreaterThan(0);

    $this->actingAs($ctx['viewer'])->get('/settings/members')->assertOk()->assertInertia(function (Assert $page) use ($ctx) {
        $people = collect($page->toArray()['props']['healthPeople']);
        $hidden = $people->firstWhere('id', $ctx['hidden']['person']);
        expect($hidden)->not->toBeNull()
            ->and($hidden['name'])->toContain(MedicalPrivacy::CANARY)
            ->and(array_keys($hidden))->toBe(['id', 'name', 'hasAccount', 'linkedUserId', 'selfAccessEnabled', 'members'])
            ->and(json_encode($hidden))->not->toContain('+421 777 777')->not->toContain('Contact');
        foreach ($hidden['members'] as $level) {
            expect($level)->toBeIn(['view', 'edit']);
        }
    });
});

it('shows the health empty state and answers an identical 403 for a known person id to a non-member admin', function () {
    $ctx = medApScenario();
    $admin = $ctx['viewer'];

    $index = $this->actingAs($admin)->get('/health')->assertOk();
    $index->assertInertia(fn (Assert $page) => $page->where('people', [])->where('person', null));

    $known = $this->actingAs($admin)->get('/health/people/'.$ctx['hidden']['person']);
    $unknown = $this->actingAs($admin)->get('/health/people/'.(string) Str::uuid7());
    $known->assertForbidden();
    expect(MedicalPrivacy::normalise($known))->toBe(MedicalPrivacy::normalise($unknown));
});

it('shows the person in Health after the admin adds themselves, audited as a distinct event', function () {
    $ctx = medApScenario();
    $admin = $ctx['viewer'];
    $person = $ctx['hidden']['person'];

    $this->actingAs($admin)->put("/settings/health-people/{$person}/members", ['levels' => [$admin->id => 'view']])->assertRedirect();

    expect(AuditEntry::where('event', 'medical_admin_self_added')->count())->toBe(1);
    $this->actingAs($admin)->get('/health/people/'.$person)->assertOk();
    $this->actingAs($admin)->get('/health')->assertRedirect('/health/people/'.$person);
});

it('refuses an admin without a Health grant on /health, even when they are a member', function () {
    $admin = User::factory()->admin()->create();
    $person = MedicalFixtures::subject(['name' => 'Anna'], [[$admin, AccessLevel::Edit]]);

    $this->actingAs($admin)->get('/health')->assertForbidden();
    $this->actingAs($admin)->get('/health/people/'.$person->getKey())->assertForbidden();
});

it('refuses every health write to a view-only member and sends no edit rights to the pages', function () {
    Storage::fake('medical');
    $ctx = MedicalPrivacy::scenario();
    $viewOnly = User::factory()->withGrant(Module::Health, AccessLevel::View)->create();
    DB::table('medical_subject_members')->insert(['subject_id' => $ctx['person']->getKey(), 'user_id' => $viewOnly->id, 'level' => AccessLevel::View->value, 'created_at' => now()]);
    $ctx['viewer'] = $viewOnly;
    MedicalPrivacy::seedVisible($ctx);

    $checked = 0;
    foreach (MedicalPrivacy::writes() as $route) {
        $url = route($route->getName(), MedicalPrivacy::params($route, $ctx), false);
        app('auth')->forgetGuards();
        $this->flushSession();
        $status = $this->actingAs($viewOnly)->call(MedicalPrivacy::method($route), $url, MedicalPrivacy::payload($route->getName(), $ctx))->getStatusCode();
        expect($status)->toBe(403, $route->getName().' answered '.$status);
        $checked++;
    }
    expect($checked)->toBeGreaterThanOrEqual(20);

    $version = MedicalPrivacy::inertiaVersion();
    $person = $ctx['person']->getKey();
    $this->actingAs($viewOnly)->get('/health')->assertRedirect("/health/people/{$person}");
    $body = $this->actingAs($viewOnly)->get("/health/people/{$person}", ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->assertOk()->getContent();
    expect($body)->not->toContain('"canEdit":true')->not->toContain('"canCreate":true')->toContain('"canCreate":false');
    expect($this->actingAs($viewOnly)->get("/health/people/{$person}", ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->getContent())->toContain('"canEdit":false');
});
