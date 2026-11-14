<?php

use App\Access\Preset;
use App\Medical\Ai\AgentHealth;
use App\Medical\Ai\CheckLoginJob;
use App\Medical\Ai\LoginState;
use App\Models\AiCall;
use App\Models\MedicalAiExtraction;
use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\MedicalPrivacy;

uses(RefreshDatabase::class);

function acSubject(string $name = 'Anna'): MedicalSubject
{
    return MedicalSubject::factory()->create(['name' => $name]);
}

function acCall(MedicalSubject $s, User $by, array $attrs = []): void
{
    AiCall::factory()->create(array_merge(['subject_id' => $s->id, 'started_by' => $by->id], $attrs));
}

it('lists calls newest first with names only and the page shape', function () {
    $admin = User::factory()->admin()->create(['name' => 'Alice Admin']);
    $anna = acSubject('Anna');
    acCall($anna, $admin, ['queued_at' => now()->subHour()]);
    acCall($anna, $admin, ['queued_at' => now()->subMinute(), 'status' => 'succeeded', 'model' => 'default', 'duration_ms' => 1500]);

    $this->actingAs($admin)->get('/settings/ai-calls')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Settings/AiCalls', false)
        ->has('sections', 7)
        ->has('calls.rows', 2)
        ->where('calls.total', 2)
        ->where('calls.rows.0.status', 'succeeded')
        ->where('calls.rows.0.model', 'default')
        ->where('calls.rows.0.durationMs', 1500)
        ->where('calls.rows.0.person', 'Anna')
        ->where('calls.rows.0.startedBy', 'Alice Admin')
        ->where('calls.rows.1.status', 'queued')
        ->where('calls.rows.1.model', null)
        ->has('calls.rows.0', fn (Assert $r) => $r->hasAll(['time', 'startedBy', 'person', 'agent', 'model', 'status', 'durationMs']))
        ->where('filters', ['person' => null, 'member' => null, 'status' => null])
        ->has('options.people', 1)
        ->where('login.agent', 'codex'));
});

it('paginates 25 per page', function () {
    $admin = User::factory()->admin()->create();
    $s = acSubject();
    for ($i = 0; $i < 30; $i++) {
        acCall($s, $admin);
    }

    $this->actingAs($admin)->get('/settings/ai-calls')->assertInertia(fn (Assert $p) => $p->has('calls.rows', 25)->where('calls.pages', 2));
    $this->actingAs($admin)->get('/settings/ai-calls?page=2')->assertInertia(fn (Assert $p) => $p->has('calls.rows', 5)->where('calls.page', 2));
});

it('filters by person, member and status and ignores invalid values', function () {
    $admin = User::factory()->admin()->create();
    $bob = User::factory()->create();
    $anna = acSubject('Anna');
    $carl = acSubject('Carl');
    acCall($anna, $admin, ['status' => 'failed']);
    acCall($carl, $bob, ['status' => 'succeeded']);
    acCall($carl, $admin, ['status' => 'failed']);

    $get = fn (string $qs) => $this->actingAs($admin)->get('/settings/ai-calls'.$qs);
    $get('?person='.$anna->id)->assertInertia(fn (Assert $p) => $p->where('calls.total', 1)->where('filters.person', $anna->id));
    $get('?member='.$bob->id)->assertInertia(fn (Assert $p) => $p->where('calls.total', 1)->where('filters.member', $bob->id));
    $get('?status=failed')->assertInertia(fn (Assert $p) => $p->where('calls.total', 2));
    $get('?status=failed&person='.$carl->id)->assertInertia(fn (Assert $p) => $p->where('calls.total', 1));
    $get('?person=nope&member=99999&status=bogus')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('calls.total', 3)->where('filters', ['person' => null, 'member' => null, 'status' => null]));
});

it('shows a removed person and a removed member as null', function () {
    $admin = User::factory()->admin()->create();
    $gone = User::factory()->create();
    $s = acSubject('Doomed');
    acCall($s, $gone);
    DB::table('medical_subjects')->where('id', $s->id)->delete();
    DB::table('users')->where('id', $gone->id)->delete();

    $this->actingAs($admin)->get('/settings/ai-calls')->assertInertia(fn (Assert $p) => $p
        ->where('calls.rows.0.person', null)->where('calls.rows.0.startedBy', null));
});

it('never exposes document titles, payloads or record counts', function () {
    $admin = User::factory()->admin()->create();
    $s = acSubject('Anna');
    $doc = MedicalDocument::factory()->image()->forSubject($s)->create(['title' => 'Doc '.MedicalPrivacy::CANARY]);
    $run = MedicalAiExtraction::factory()->forDocument($doc)->ready(['entries' => [['note' => MedicalPrivacy::CANARY]]], 7)->create(['started_by' => $admin->id]);
    acCall($s, $admin, ['extraction_id' => $run->id, 'document_id' => $doc->id, 'status' => 'succeeded']);

    $body = $this->actingAs($admin)->get('/settings/ai-calls')->assertOk()->getContent();
    expect($body)->not->toContain(MedicalPrivacy::CANARY)->not->toContain('entryCount')->not->toContain('entry_count')
        ->not->toContain((string) $doc->id)->not->toContain('payload');
});

it('forbids everyone but the admin and redirects guests', function () {
    foreach ([Preset::Parent, Preset::Child, Preset::Grandparent, Preset::Guest] as $preset) {
        $u = User::factory()->withPreset($preset)->create();
        $this->actingAs($u)->get('/settings/ai-calls')->assertForbidden();
        $this->actingAs($u)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])->post('/settings/ai-calls/check')->assertForbidden();
    }
    $this->actingAs(User::factory()->create())->get('/settings/ai-calls')->assertForbidden();
    auth()->logout();
    $this->get('/settings/ai-calls')->assertRedirect('/login');
});

it('queues one login check on the ai-control queue for an admin with a recent confirmation', function () {
    $this->useRealSensitiveSession();
    Queue::fake();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->post('/settings/ai-calls/check')->assertRedirect();

    Queue::assertPushed(CheckLoginJob::class, 1);
    Queue::assertPushedOn('ai-control', CheckLoginJob::class);
});

it('refuses the check without a recent confirmation', function () {
    $this->useRealSensitiveSession();
    Queue::fake();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/settings/ai-calls/check')->assertRedirect('/confirm-access');

    Queue::assertNothingPushed();
});

it('reflects the login health states', function () {
    $admin = User::factory()->admin()->create();
    $login = fn () => $this->actingAs($admin)->get('/settings/ai-calls');

    $login()->assertInertia(fn (Assert $p) => $p->where('login.state', 'unknown')->where('login.checkedAt', null));

    $health = app(AgentHealth::class);
    $health->recordCheck('codex', LoginState::LoggedIn);
    $login()->assertInertia(fn (Assert $p) => $p->where('login.state', 'logged_in')->whereType('login.checkedAt', 'string'));

    $health->recordCheck('codex', LoginState::LoggedOut);
    $login()->assertInertia(fn (Assert $p) => $p->where('login.state', 'expired'));

    $health->recordCheck('codex', LoginState::Unknown);
    $login()->assertInertia(fn (Assert $p) => $p->where('login.state', 'not_responding'));
});
