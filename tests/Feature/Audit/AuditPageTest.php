<?php

use App\Access\Preset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function seedAudit(array $row): void
{
    DB::table('audit_logs')->insert(array_merge(['occurred_at' => now()], $row));
}

it('renders the page for the admin with entries newest first and the page shape', function () {
    $admin = User::factory()->admin()->create();
    seedAudit(['event' => 'login_success', 'actor_id' => $admin->id, 'actor_label' => 'A', 'occurred_at' => now()->subDay(), 'ip' => '1.2.3.4']);
    seedAudit(['event' => 'permission_changed', 'actor_id' => $admin->id, 'module' => 'finance', 'target_label' => 'B', 'meta' => json_encode(['from' => 'none', 'to' => 'view', 'secret' => 'x'])]);

    $this->actingAs($admin)->get('/settings/audit')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Settings/Audit')
        ->has('entries', 2)
        ->where('entries.0.event', 'permission_changed')
        ->where('entries.0.meta', ['from' => 'none', 'to' => 'view'])
        ->where('entries.1.ip', '1.2.3.4')
        ->has('entries.0', fn (Assert $e) => $e->hasAll(['id', 'occurredAt', 'actorLabel', 'event', 'group', 'module', 'targetLabel', 'meta', 'ip']))
        ->has('members', 1)
        ->where('filters', ['member' => null, 'event' => null, 'from' => null, 'to' => null])
        ->has('sections', 6)
        ->where('error', null));
});

it('forbids everyone but the admin', function () {
    foreach ([Preset::Parent, Preset::Child, Preset::Grandparent, Preset::Guest] as $preset) {
        $this->actingAs(User::factory()->withPreset($preset)->create())->get('/settings/audit')->assertForbidden();
    }
    $this->actingAs(User::factory()->create())->get('/settings/audit')->assertForbidden();
});

it('redirects guests to the login', function () {
    $this->get('/settings/audit')->assertRedirect('/login');
});

it('paginates 25 per page', function () {
    $admin = User::factory()->admin()->create();
    for ($i = 0; $i < 30; $i++) {
        seedAudit(['event' => 'login_success']);
    }

    $this->actingAs($admin)->get('/settings/audit')->assertInertia(fn (Assert $p) => $p->has('entries', 25)->where('pagination.lastPage', 2));
    $this->actingAs($admin)->get('/settings/audit?page=2')->assertInertia(fn (Assert $p) => $p->has('entries', 5)->where('pagination.page', 2));
});

it('filters by member as actor or target', function () {
    $admin = User::factory()->admin()->create();
    $anna = User::factory()->create();
    seedAudit(['event' => 'login_success', 'actor_id' => $anna->id]);
    seedAudit(['event' => 'permission_changed', 'actor_id' => $admin->id, 'target_user_id' => $anna->id]);
    seedAudit(['event' => 'login_success', 'actor_id' => $admin->id]);

    $this->actingAs($admin)->get('/settings/audit?member='.$anna->id)->assertInertia(fn (Assert $p) => $p->has('entries', 2)->where('filters.member', $anna->id));
});

it('filters finance including denied finance access but not denied health', function () {
    $admin = User::factory()->admin()->create();
    seedAudit(['event' => 'finance_read', 'module' => 'finance']);
    seedAudit(['event' => 'finance_write', 'module' => 'finance']);
    seedAudit(['event' => 'access_denied', 'module' => 'finance']);
    seedAudit(['event' => 'access_denied', 'module' => 'health']);
    seedAudit(['event' => 'health_read', 'module' => 'health']);

    $this->actingAs($admin)->get('/settings/audit?event=finance')->assertInertia(fn (Assert $p) => $p
        ->has('entries', 3)->where('entries.0.group', 'finance'));
    $this->actingAs($admin)->get('/settings/audit?event=health')->assertInertia(fn (Assert $p) => $p->has('entries', 2));
});

it('groups failed sign-ins with lockouts', function () {
    $admin = User::factory()->admin()->create();
    seedAudit(['event' => 'login_failed']);
    seedAudit(['event' => 'lockout']);
    seedAudit(['event' => 'login_success']);

    $this->actingAs($admin)->get('/settings/audit?event=login_failed')->assertInertia(fn (Assert $p) => $p->has('entries', 2));
});

it('filters by inclusive local day range', function () {
    $admin = User::factory()->admin()->create();
    $tz = config('app.timezone');
    seedAudit(['event' => 'login_success', 'occurred_at' => now($tz)->setDate(2026, 5, 10)->setTime(0, 0, 1)]);
    seedAudit(['event' => 'login_success', 'occurred_at' => now($tz)->setDate(2026, 5, 12)->setTime(23, 59, 0)]);
    seedAudit(['event' => 'login_success', 'occurred_at' => now($tz)->setDate(2026, 5, 13)->setTime(0, 1, 0)]);
    seedAudit(['event' => 'login_success', 'occurred_at' => now($tz)->setDate(2026, 5, 9)->setTime(23, 59, 0)]);

    $this->actingAs($admin)->get('/settings/audit?from=2026-05-10&to=2026-05-12')->assertInertia(fn (Assert $p) => $p->has('entries', 2));
});

it('ignores invalid filter values and reports a reversed range, never a 500', function () {
    $admin = User::factory()->admin()->create();
    seedAudit(['event' => 'login_success']);

    $this->actingAs($admin)->get('/settings/audit?member=abc&event=nope&from=garbage&to=2026-99-99')
        ->assertOk()->assertInertia(fn (Assert $p) => $p->has('entries', 1)->where('filters', ['member' => null, 'event' => null, 'from' => null, 'to' => null]));

    $this->actingAs($admin)->get('/settings/audit?member=99999&page=-3')->assertOk();

    $this->actingAs($admin)->get('/settings/audit?from=2026-05-12&to=2026-05-10')
        ->assertOk()->assertInertia(fn (Assert $p) => $p->where('error', 'audit.error.range')->has('entries', 1));

    $this->actingAs($admin)->get("/settings/audit?member=1'%20OR%201=1--&event[]=x")->assertOk();
});
