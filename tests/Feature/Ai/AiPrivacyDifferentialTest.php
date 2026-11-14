<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Medical\Ai\RunExtractionJob;
use App\Medical\Ai\Work\WorkDir;
use App\Models\AiAgentSetting;
use App\Models\AiCall;
use App\Models\MedicalAiExtraction;
use App\Models\MedicalDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeCodex;
use Tests\Support\MedicalFileFixtures;
use Tests\Support\MedicalFixtures;
use Tests\Support\MedicalPrivacy;

/*
 * Phase 8 privacy proof over the AI surfaces (08-21): a viewer without access to a person gets, from the review, status,
 * page-image, run, cancel, accept, reject and undo routes, exactly what a family where that person never existed gives;
 * and proposal content never reaches another member, an admin page, an audit row, the call log, the failed jobs or the logs.
 */
beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    Storage::fake('medical');
    // JSON refusals carry a stack trace with test line numbers in debug mode, which would differ between two identical refusals.
    config(['app.debug' => false]);
    $this->scripts = [];
    $this->root = sys_get_temp_dir().'/aipd-work-'.strtolower((string) Str::ulid());
    config(['ai.work_dir' => $this->root, 'ai.codex.home' => $this->root, 'ai.codex.model' => null]);
});

afterEach(function () {
    Carbon::setTestNow();
    DB::purge('btlock');
    foreach ($this->scripts as $f) {
        @unlink($f);
    }
    if (is_dir($this->root)) {
        WorkDir::removeTree($this->root);
    }
});

/** Route names of every AI surface that takes a person (and mostly a document or extraction) id. */
const AIPD_ROUTES = [
    'health.people.ai.status', 'health.documents.ai.review', 'health.documents.page',
    'health.documents.ai.run', 'health.ai.cancel', 'health.ai.entries.accept', 'health.ai.entries.reject', 'health.ai.entries.undo',
];

/**
 * Sends one AI route with the given route parameters as $viewer and returns the normalised response.
 *
 * @param  array<string, string>  $params
 */
function aipdSend(object $test, User $viewer, Route $route, array $params, array $ctx): string
{
    app('auth')->forgetGuards();
    app('cache')->store()->flush();
    $test->flushSession();
    $url = route($route->getName(), $params, false);
    $response = in_array('GET', $route->methods(), true)
        ? $test->actingAs($viewer)->get($url, ['Accept' => 'application/json'])
        : $test->actingAs($viewer)->call(MedicalPrivacy::method($route), $url, MedicalPrivacy::payload($route->getName(), $ctx), [], [], ['HTTP_ACCEPT' => 'application/json']);

    return MedicalPrivacy::normalise($response);
}

/** @return list<Route> */
function aipdRoutes(): array
{
    $routes = [];
    foreach ([...MedicalPrivacy::surfaces(), ...MedicalPrivacy::writes()] as $route) {
        if (in_array($route->getName(), AIPD_ROUTES, true)) {
            $routes[$route->getName()] = $route;
        }
    }

    return array_values($routes);
}

it('answers every AI route for a hidden person exactly like a person who never existed, a random id and a malformed id', function () {
    $ctx = MedicalPrivacy::scenario();
    MedicalPrivacy::seedVisible($ctx);
    MedicalPrivacy::seedHidden($ctx);
    $viewer = $ctx['viewer'];
    // The hidden person carries Phase 9 state too (zz_ai_agents.php): a Copilot-produced proposal and waiting runs.
    expect(DB::table('medical_ai_extractions')->where('subject_id', $ctx['hidden']['person'])->where('agent', 'copilot')->where('status', 'ready')->count())->toBe(1)
        ->and(DB::table('medical_ai_extractions')->where('subject_id', $ctx['hidden']['person'])->where('status', 'waiting')->count())->toBe(2);
    // The PDF the hidden proposal sits on is a real two-page PDF, so the page route would render for a member.
    $hiddenDocument = MedicalDocument::query()->findOrFail($ctx['hidden']['ai_ready_document']);
    Storage::disk('medical')->put($hiddenDocument->path, MedicalFileFixtures::pdfPages(2));

    $routes = aipdRoutes();
    expect(collect($routes)->map->getName()->sort()->values()->all())->toBe(collect(AIPD_ROUTES)->sort()->values()->all());

    $withB = [];
    $hiddenParams = [];
    foreach ($routes as $route) {
        $params = MedicalPrivacy::hiddenParams($route, $ctx);
        expect($params)->not->toBeNull($route->getName().' has no hidden-record parameter');
        $params['person'] = $ctx['hidden']['person'];
        $params['page'] = 1;
        $hiddenParams[$route->getName()] = array_intersect_key($params, array_flip($route->parameterNames()));
        $withB[$route->getName()] = aipdSend($this, $viewer, $route, $hiddenParams[$route->getName()], $ctx);
    }

    // Variants that never reach a hidden record: a random uuid and a malformed id for every id parameter.
    foreach ($routes as $route) {
        foreach (['random uuid' => fn () => (string) Str::uuid7(), 'malformed id' => fn () => 'abc'] as $label => $make) {
            $params = $hiddenParams[$route->getName()];
            foreach (array_keys($params) as $key) {
                if ($key !== 'page') {
                    $params[$key] = $make();
                }
            }
            expect(aipdSend($this, $viewer, $route, $params, $ctx))->toBe($withB[$route->getName()], $route->getName()." hidden vs $label");
        }
    }

    // The family where B never existed: B and everything under it is deleted (cascade), then the same URLs are asked again.
    DB::table('medical_subjects')->where('id', $ctx['hidden']['person'])->delete();
    expect(DB::table('medical_ai_extractions')->where('id', $ctx['hidden']['extraction'])->exists())->toBeFalse();
    foreach ($routes as $route) {
        expect(aipdSend($this, $viewer, $route, $hiddenParams[$route->getName()], $ctx))
            ->toBe($withB[$route->getName()], $route->getName().' differs between a family with B and one where B never existed');
        expect($withB[$route->getName()])->toStartWith('403', $route->getName().' did not refuse');
    }
});

it('refuses the visible person with a hidden document or extraction id exactly like a random one', function () {
    $ctx = MedicalPrivacy::scenario();
    MedicalPrivacy::seedVisible($ctx);
    MedicalPrivacy::seedHidden($ctx);
    $viewer = $ctx['viewer'];
    $visible = (string) $ctx['person']->getKey();

    foreach (aipdRoutes() as $route) {
        $names = $route->parameterNames();
        if (! in_array('document', $names, true) && ! in_array('extraction', $names, true)) {
            continue;
        }
        $params = MedicalPrivacy::hiddenParams($route, $ctx);
        $params['person'] = $visible;
        $params['page'] = 1;
        $params = array_intersect_key($params, array_flip($names));
        $random = $params;
        foreach (['document', 'extraction'] as $key) {
            if (isset($random[$key])) {
                $random[$key] = (string) Str::uuid7();
            }
        }
        expect(aipdSend($this, $viewer, $route, $params, $ctx))->toBe(aipdSend($this, $viewer, $route, $random, $ctx), $route->getName().' mismatched pair');
    }
});

/** @return array<string, mixed> */
function aipdPayload(string $marker): array
{
    return [
        'schema_version' => 1, 'document_date' => null, 'specialty_key' => null,
        'visits' => [['visited_on' => '2026-01-05', 'doctor' => 'Dr '.$marker, 'specialty_key' => 'general_practice', 'specialty_other' => null, 'diagnosis' => $marker.' diagnosis', 'notes' => null]],
        'medications' => [['name' => $marker.' medication', 'dose' => '5 mg', 'schedule' => null, 'started_on' => null, 'ended_on' => null, 'notes' => null]],
        'allergies' => [], 'vaccinations' => [],
    ];
}

it('keeps proposal and document-title canaries out of other members, every admin page, audit, the call log, failed jobs and logs', function () {
    // A person with a clean name (Settings pages legitimately list person names) whose document title and proposal carry the canary.
    $member = User::factory()->create(['name' => 'Mona Member']);
    $bystander = User::factory()->create(['name' => 'Bea Bystander']);
    $admin = User::factory()->admin()->withGrant(Module::Health, AccessLevel::Edit)->create(['name' => 'Ada Admin']);
    foreach ([$member, $bystander] as $user) {
        MedicalFixtures::grantHealth($user, AccessLevel::Edit);
    }
    $subject = MedicalFixtures::subject(['name' => 'Clean Name', 'ai_consent' => true], [[$member, AccessLevel::Edit]]);
    $document = MedicalDocument::factory()->image()->forSubject($subject)->create(['title' => 'Scan '.MedicalPrivacy::CANARY]);
    Storage::disk('medical')->put($document->path, 'jpeg-bytes');
    DB::table('ai_notice_acks')->insert(['user_id' => $member->id, 'providers' => 'codex']);
    $run = MedicalAiExtraction::factory()->forDocument($document)->create(['started_by' => $member->id]);
    AiCall::factory()->create(['extraction_id' => $run->id, 'subject_id' => $subject->getKey(), 'document_id' => $document->id, 'started_by' => $member->id]);

    $logs = [];
    foreach (glob(storage_path('logs/*')) ?: [] as $f) {
        $logs[$f] = (int) filesize($f);
    }
    $this->scripts[] = $script = FakeCodex::succeeding(json_encode(aipdPayload('Dr '.MedicalPrivacy::CANARY)));
    config(['ai.codex.binary' => $script]);
    (new RunExtractionJob($run->id))->handle();
    expect(DB::table('medical_ai_extractions')->where('id', $run->id)->value('status'))->toBe('ready');

    // The member accepts the visit and the medication: records and audit rows now exist.
    foreach (['visit-0', 'medication-0'] as $entry) {
        $this->actingAs($member)->postJson("/health/people/{$subject->getKey()}/ai/{$run->id}/entries/$entry/accept")->assertOk();
    }
    expect(DB::table('medical_visits')->where('subject_id', $subject->getKey())->count())->toBe(1);

    $assertClean = function (string $text, string $where) {
        $text = MedicalPrivacy::scannable($text);
        foreach ([...MedicalPrivacy::CANARY_NEEDLES, 'jpeg-bytes'] as $needle) {
            expect(str_contains($text, $needle))->toBeFalse("'$needle' found in $where");
        }
    };

    // Other members: the bystander has Health edit but no membership; the admin has no membership either.
    $ctx = ['person' => $subject, 'hidden' => [], 'viewer' => $bystander, 'other' => $member, 'document' => (string) $document->id, 'extraction' => (string) $run->id];
    foreach ([$bystander, $admin] as $viewer) {
        $captured = [];
        foreach (['/health', '/settings/ai-calls', '/settings/audit', '/settings/members', '/'] as $path) {
            app('auth')->forgetGuards();
            $this->flushSession();
            $response = $this->actingAs($viewer)->get($path);
            $captured[$path] = $response->getStatusCode().(string) $response->getContent();
        }
        foreach ($captured as $path => $body) {
            $assertClean($body, "$path as {$viewer->name}");
        }
        foreach (aipdRoutes() as $route) {
            $params = ['person' => (string) $subject->getKey(), 'document' => (string) $document->id, 'extraction' => (string) $run->id, 'entry' => 'visit-0', 'page' => 1];
            $params = array_intersect_key($params, array_flip($route->parameterNames()));
            $assertClean(aipdSend($this, $viewer, $route, $params, $ctx + ['viewer' => $viewer]), $route->getName().' as '.$viewer->name);
        }
    }
    // The admin pages really rendered (not an empty pass).
    $this->actingAs($admin)->get('/settings/ai-calls')->assertOk();
    $this->actingAs($admin)->get('/settings/audit')->assertOk();
    // The admin sees that a run happened and by whom, nothing of its content.
    expect((string) $this->actingAs($admin)->get('/settings/ai-calls')->getContent())->toContain('Clean Name');

    $sinks = json_encode([
        'audit' => DB::table('audit_logs')->get(),
        'ai_calls' => DB::table('ai_calls')->get(),
        'failed_jobs' => DB::table('failed_jobs')->get(),
        'jobs' => DB::table('jobs')->get(),
    ]) ?: '';
    expect(DB::table('audit_logs')->whereIn('event', ['medical_ai_run_finished'])->count())->toBeGreaterThan(0);
    $assertClean($sinks, 'audit, ai_calls, failed_jobs or jobs');

    clearstatcache();
    $logText = '';
    foreach (glob(storage_path('logs/*')) ?: [] as $f) {
        $logText .= (string) substr((string) file_get_contents($f), $logs[$f] ?? 0);
    }
    $assertClean($logText, 'a log file');
});

it('keeps document and proposal canaries and person and member names out of every Phase 9 admin payload and table', function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-03-10 00:30', 'Europe/Bratislava')->utc());
    $admin = User::factory()->admin()->withGrant(Module::Health, AccessLevel::Edit)->create(['name' => 'Ada Admin']);
    $member = User::factory()->create(['name' => 'Mona Member']);
    MedicalFixtures::grantHealth($member, AccessLevel::Edit);
    AiAgentSetting::query()->toBase()->where('agent', 'copilot')->update(['enabled' => true]);
    $this->scripts[] = $script = FakeCodex::succeeding(json_encode(aipdPayload('Dr '.MedicalPrivacy::CANARY)));
    config(['ai.codex.binary' => $script, 'ai.limits.document_cooldown_minutes' => 0]);

    $logs = [];
    foreach (glob(storage_path('logs/*')) ?: [] as $f) {
        $logs[$f] = (int) filesize($f);
    }
    $names = [];
    foreach ([1, 2] as $i) {
        $subject = MedicalFixtures::subject(['name' => 'Clean Person '.$i, 'ai_consent' => true], [[$member, AccessLevel::Edit]]);
        $names[] = 'Clean Person '.$i;
        $document = MedicalDocument::factory()->image()->forSubject($subject)->create(['title' => 'Scan '.MedicalPrivacy::CANARY]);
        Storage::disk('medical')->put($document->path, 'jpeg-bytes');
    }

    $this->actingAs($admin);
    $captured = [];
    $captured['dry'] = (string) $this->postJson('/settings/ai-agents/bulk/dry-run', ['scope' => 'all', 'cap' => 5])->assertOk()->getContent();
    $token = json_decode($captured['dry'], true)['token'];
    $captured['start'] = (string) $this->postJson('/settings/ai-agents/bulk', ['token' => $token, 'acknowledged' => true])->assertCreated()->getContent();
    foreach (['01:05', '01:20', '01:35'] as $at) {
        $this->travelTo(CarbonImmutable::parse("2026-03-10 $at", 'Europe/Bratislava')->utc());
        Artisan::call('ai:bulk-tick');
        $id = DB::table('medical_ai_extractions')->whereNotNull('bulk_run_id')->where('status', 'queued')->value('id');
        if ($id !== null) {
            (new RunExtractionJob((string) $id))->handle();
        }
    }
    expect(DB::table('medical_ai_extractions')->whereNotNull('bulk_run_id')->where('status', 'ready')->count())->toBe(2);

    $captured['agents page'] = (string) $this->get('/settings/ai-agents')->assertOk()->getContent();
    $captured['bulk poll'] = (string) $this->getJson('/settings/ai-agents/bulk')->assertOk()->getContent();
    $captured['ai calls'] = (string) $this->get('/settings/ai-calls')->assertOk()->getContent();
    $captured['audit page'] = (string) $this->get('/settings/audit')->assertOk()->getContent();
    $captured['audit meta'] = (string) json_encode(DB::table('audit_logs')->whereIn('event', ['ai_agent_setting_changed', 'ai_bulk_run_changed', 'medical_ai_run_requested', 'medical_ai_run_finished'])->pluck('meta'));
    $captured['tables'] = (string) json_encode([
        'bulk runs' => DB::table('ai_bulk_runs')->get(), 'bulk items' => DB::table('ai_bulk_run_items')->get(), 'bulk dry runs' => DB::table('ai_bulk_dry_runs')->get(),
        'settings' => DB::table('ai_agent_settings')->get(), 'status' => DB::table('ai_agent_status')->get(),
        'calls' => DB::table('ai_calls')->get(), 'failed' => DB::table('failed_jobs')->get(), 'jobs' => DB::table('jobs')->get(),
    ]);
    expect(DB::table('audit_logs')->where('event', 'ai_bulk_run_changed')->count())->toBeGreaterThanOrEqual(3);

    foreach ($captured as $where => $text) {
        $scan = MedicalPrivacy::scannable($text);
        foreach ([...MedicalPrivacy::CANARY_NEEDLES, 'jpeg-bytes'] as $needle) {
            expect(str_contains($scan, $needle))->toBeFalse("'$needle' found in $where");
        }
    }
    // Counts only: no person or member name in the bulk payloads, the agents page or the bulk tables and call rows.
    foreach (['dry', 'start', 'bulk poll', 'agents page', 'tables'] as $where) {
        foreach ([...$names, 'Mona Member'] as $name) {
            expect(str_contains($captured[$where], $name))->toBeFalse("'$name' found in $where");
        }
    }
    // The call rows name no person; only the filter dropdown lists every person, as Settings > Members does (Phase 8 admin exception).
    $this->get('/settings/ai-calls')->assertInertia(fn (Assert $page) => expect(json_encode($page->toArray()['props']['calls']))->not->toContain('Clean Person'));

    clearstatcache();
    $logText = '';
    foreach (glob(storage_path('logs/*')) ?: [] as $f) {
        $logText .= (string) substr((string) file_get_contents($f), $logs[$f] ?? 0);
    }
    foreach ([...MedicalPrivacy::CANARY_NEEDLES, 'jpeg-bytes', ...$names] as $needle) {
        expect(str_contains(MedicalPrivacy::scannable($logText), $needle))->toBeFalse("'$needle' found in a log file");
    }
});
