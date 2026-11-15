<?php

use App\Access\AccessLevel;
use App\Console\Commands\AiReapStuck;
use App\Medical\Ai\RunExtractionJob;
use App\Medical\Ai\Work\WorkDir;
use App\Models\AiAgentSetting;
use App\Models\AiCall;
use App\Models\MedicalAiExtraction;
use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FakeCodex;
use Tests\Support\FakeCopilot;
use Tests\Support\MedicalFixtures;
use Tests\Support\MedicalPrivacy;

function lkPayload(string $doctor): array
{
    return [
        'schema_version' => 1, 'document_date' => null, 'specialty_key' => null,
        'visits' => [['visited_on' => null, 'doctor' => $doctor, 'specialty_key' => null, 'specialty_other' => null, 'diagnosis' => null, 'notes' => null]],
        'medications' => [], 'allergies' => [], 'vaccinations' => [],
    ];
}

/** @return array{user: User, subject: MedicalSubject, doc: MedicalDocument, run: MedicalAiExtraction} */
function lkSetup(): array
{
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user, AccessLevel::Edit);
    DB::table('ai_notice_acks')->insert(['user_id' => $user->id, 'providers' => 'codex']);
    $subject = MedicalFixtures::subject(['ai_consent' => true, 'name' => 'Person '.MedicalPrivacy::CANARY], [[$user, AccessLevel::Edit]]);
    $doc = MedicalDocument::factory()->image()->forSubject($subject)->create(['title' => 'Title '.MedicalPrivacy::CANARY]);
    Storage::disk('medical')->put($doc->path, 'jpeg-bytes '.MedicalPrivacy::CANARY);
    $run = lkQueue($doc, $user);

    return compact('user', 'subject', 'doc', 'run');
}

function lkQueue(MedicalDocument $doc, User $user): MedicalAiExtraction
{
    $run = MedicalAiExtraction::factory()->forDocument($doc)->create(['started_by' => $user->id]);
    AiCall::factory()->create(['extraction_id' => $run->id, 'subject_id' => $doc->subject_id, 'document_id' => $doc->id, 'started_by' => $user->id]);

    return $run;
}

/** @return array<string, int> */
function lkLogSizes(): array
{
    $sizes = [];
    foreach (glob(storage_path('logs/*')) ?: [] as $f) {
        $sizes[$f] = (int) filesize($f);
    }

    return $sizes;
}

/** Everything written to the log files since $before. */
function lkLogSince(array $before): string
{
    clearstatcache();
    $out = '';
    foreach (glob(storage_path('logs/*')) ?: [] as $f) {
        $out .= (string) substr((string) file_get_contents($f), $before[$f] ?? 0);
    }

    return $out;
}

/** Every sink except the proposal table, as one string. */
function lkSinks(): string
{
    return json_encode([
        'failed_jobs' => DB::table('failed_jobs')->get(),
        'jobs' => DB::table('jobs')->get(),
        'audit' => DB::table('audit_logs')->get(),
        'ai_calls' => DB::table('ai_calls')->get(),
    ]) ?: '';
}

beforeEach(function () {
    Storage::fake('medical');
    $this->scripts = [];
    $this->root = sys_get_temp_dir().'/lk-work-'.strtolower((string) Str::ulid());
    config([
        'ai.work_dir' => $this->root, 'ai.codex.home' => $this->root, 'ai.codex.model' => null,
        'ai.copilot.home' => $this->root.'-copilot', 'ai.copilot.cache' => $this->root.'-cache', 'ai.copilot.model' => null,
    ]);
});

afterEach(function () {
    foreach ($this->scripts as $f) {
        @unlink($f);
    }
    foreach ([$this->root, $this->root.'-copilot', $this->root.'-cache'] as $dir) {
        if (is_dir($dir)) {
            WorkDir::removeTree($dir);
        }
    }
});

it('reaps running rows past the job timeout and queued rows past the stale hours, nothing else', function () {
    $s = lkSetup();
    $make = function (string $status, array $times) use ($s) {
        $doc = MedicalDocument::factory()->image()->forSubject($s['subject'])->create();
        $run = MedicalAiExtraction::factory()->forDocument($doc)->create(['started_by' => $s['user']->id, 'status' => $status] + $times);
        AiCall::factory()->create(['extraction_id' => $run->id, 'subject_id' => $doc->subject_id, 'document_id' => $doc->id,
            'started_by' => $s['user']->id, 'status' => $status, 'queued_at' => $times['queued_at'] ?? now(), 'started_at' => $times['started_at'] ?? null]);

        return $run;
    };
    $oldRunning = $make('running', ['queued_at' => now()->subMinutes(15), 'started_at' => now()->subMinutes(14)]);
    $freshRunning = $make('running', ['queued_at' => now()->subMinutes(2), 'started_at' => now()->subMinute()]);
    $oldQueued = $make('queued', ['queued_at' => now()->subHours(7)]);
    $freshQueued = $make('queued', ['queued_at' => now()->subHour()]);

    expect(Artisan::call(AiReapStuck::class))->toBe(0);
    expect(trim(Artisan::output()))->toBe('2');

    $state = fn (MedicalAiExtraction $r) => DB::table('medical_ai_extractions')->where('id', $r->id)->first();
    $call = fn (MedicalAiExtraction $r) => DB::table('ai_calls')->where('extraction_id', $r->id)->first();
    foreach ([$oldRunning, $oldQueued] as $r) {
        expect($state($r)->status)->toBe('failed')->and($state($r)->failure_code)->toBe('worker_lost')->and($state($r)->model)->toBe('default')
            ->and($call($r)->status)->toBe('failed')->and($call($r)->failure_code)->toBe('worker_lost');
    }
    expect($state($freshRunning)->status)->toBe('running')->and($call($freshRunning)->status)->toBe('running')
        ->and($state($freshQueued)->status)->toBe('queued')->and($call($freshQueued)->status)->toBe('queued');
});

it('keeps the canary out of every side channel when a run fails with CLI output full of it', function () {
    $s = lkSetup();
    $this->scripts[] = $script = FakeCodex::echoing(MedicalPrivacy::CANARY);
    config(['ai.codex.binary' => $script]);
    $logs = lkLogSizes();

    (new RunExtractionJob($s['run']->id))->handle();

    $row = DB::table('medical_ai_extractions')->where('id', $s['run']->id)->first();
    expect($row->status)->toBe('failed')->and($row->payload)->toBeNull()
        ->and(json_encode($row))->not->toContain(MedicalPrivacy::CANARY);
    foreach (MedicalPrivacy::CANARY_NEEDLES as $needle) {
        expect(lkSinks())->not->toContain($needle)
            ->and(lkLogSince($logs))->not->toContain($needle);
    }
    expect(DB::table('audit_logs')->where('event', 'medical_ai_run_finished')->count())->toBe(1);
});

it('records a content-free worker_lost when the job throws, through the real queue worker', function () {
    $s = lkSetup();
    // The work dir cannot be created: the job throws right after the claim.
    config(['ai.work_dir' => '/proc/lk-no-such-dir/work']);
    $logs = lkLogSizes();

    RunExtractionJob::dispatch($s['run']->id);
    $queued = (string) DB::table('jobs')->value('payload');
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'ai', '--once' => true, '--tries' => 1]);

    $row = DB::table('medical_ai_extractions')->where('id', $s['run']->id)->first();
    expect($row->status)->toBe('failed')->and($row->failure_code)->toBe('worker_lost')
        ->and(DB::table('ai_calls')->where('extraction_id', $s['run']->id)->value('status'))->toBe('failed');

    $failed = (string) DB::table('failed_jobs')->value('exception');
    expect($failed)->toContain('Extraction job failed.');
    foreach ([...MedicalPrivacy::CANARY_NEEDLES, 'Scan', $s['doc']->path, $s['doc']->title] as $needle) {
        expect($failed)->not->toContain($needle)
            ->and(lkLogSince($logs))->not->toContain($needle);
    }
    expect($queued)->toContain($s['run']->id);
});

it('keeps proposal content in the proposal table only', function () {
    $s = lkSetup();
    $doctor = 'Dr '.MedicalPrivacy::CANARY;
    $this->scripts[] = $script = FakeCodex::succeeding(json_encode(lkPayload($doctor)));
    config(['ai.codex.binary' => $script]);
    $logs = lkLogSizes();

    (new RunExtractionJob($s['run']->id))->handle();

    $row = DB::table('medical_ai_extractions')->where('id', $s['run']->id)->first();
    expect($row->status)->toBe('ready')->and($row->payload)->toContain(MedicalPrivacy::CANARY);
    foreach (MedicalPrivacy::CANARY_NEEDLES as $needle) {
        expect(lkSinks())->not->toContain($needle)
            ->and(lkLogSince($logs))->not->toContain($needle);
    }
});

it('serialises only the extraction id into the jobs table', function () {
    $s = lkSetup();

    RunExtractionJob::dispatch($s['run']->id);

    $row = DB::table('jobs')->first();
    $payload = (string) $row->payload;
    expect($row->queue)->toBe('ai')
        ->and($payload)->toContain($s['run']->id);
    foreach ([MedicalPrivacy::CANARY, $s['subject']->name, $s['doc']->title, $s['doc']->path, $s['doc']->id, $s['subject']->id, 'jpeg-bytes'] as $needle) {
        expect($payload)->not->toContain($needle);
    }
});

/** A fake codex that prints the canary on both streams together with a usage-limit message, exit 1, no result. */
function lkQuotaCanary(): string
{
    $c = escapeshellarg(MedicalPrivacy::CANARY);
    $path = sys_get_temp_dir().'/lk-quota-'.bin2hex(random_bytes(6));
    file_put_contents($path, "#!/bin/sh\ncat >/dev/null; printf %s ".$c."; printf '%s hit your usage limit\\n' ".$c." >&2; exit 1\n");
    chmod($path, 0700);

    return $path;
}

function lkBothAgents(array $s): void
{
    AiAgentSetting::query()->toBase()->where('agent', 'copilot')->update(['enabled' => true]);
    DB::table('ai_notice_acks')->where('user_id', $s['user']->id)->update(['providers' => 'codex,copilot']);
}

it('keeps the canary out of every sink across a quota fallback and a Copilot repair call', function () {
    $s = lkSetup();
    lkBothAgents($s);
    $counter = sys_get_temp_dir().'/lk-count-'.bin2hex(random_bytes(4));
    $this->scripts[] = $codex = lkQuotaCanary();
    $this->scripts[] = $counter;
    $this->scripts[] = $copilot = FakeCopilot::sequence(['Here: '.MedicalPrivacy::CANARY.' {broken', json_encode(lkPayload('Dr Clean'))], $counter);
    config(['ai.codex.binary' => $codex, 'ai.copilot.binary' => $copilot]);
    $logs = lkLogSizes();

    (new RunExtractionJob($s['run']->id))->handle();

    $row = DB::table('medical_ai_extractions')->where('id', $s['run']->id)->first();
    expect($row->status)->toBe('ready')->and($row->agent)->toBe('copilot')
        ->and(DB::table('ai_calls')->where('extraction_id', $s['run']->id)->count())->toBe(2);
    $own = json_encode(DB::table('medical_ai_extractions')->where('id', $s['run']->id)->get(['status', 'failure_code', 'agent', 'model', 'cancel_reason'])).lkSinks();
    $repair = (string) @file_get_contents($counter.'.p2');
    @unlink($counter.'.p1');
    @unlink($counter.'.p2');
    expect($repair)->not->toBe('');
    foreach (MedicalPrivacy::CANARY_NEEDLES as $needle) {
        expect($own)->not->toContain($needle)
            ->and($repair)->not->toContain($needle)
            ->and(lkLogSince($logs))->not->toContain($needle);
    }
});

it('leaves no canary behind when a run is parked waiting', function () {
    $s = lkSetup();
    $this->scripts[] = $codex = lkQuotaCanary();
    config(['ai.codex.binary' => $codex]);
    $logs = lkLogSizes();

    (new RunExtractionJob($s['run']->id))->handle();

    $row = DB::table('medical_ai_extractions')->where('id', $s['run']->id)->first();
    expect($row->status)->toBe('waiting')->and(glob($this->root.'/*'))->toBe([])
        ->and(json_encode($row))->not->toContain(MedicalPrivacy::CANARY);
    foreach (MedicalPrivacy::CANARY_NEEDLES as $needle) {
        expect(lkSinks())->not->toContain($needle)
            ->and(lkLogSince($logs))->not->toContain($needle);
    }
});

it('leaves no canary in the work dir or the Copilot home when the CLI writes its session log', function () {
    $s = lkSetup();
    lkBothAgents($s);
    AiAgentSetting::query()->toBase()->where('agent', 'codex')->update(['enabled' => false]);
    $this->scripts[] = $copilot = FakeCopilot::writesSession(MedicalPrivacy::CANARY);
    config(['ai.copilot.binary' => $copilot]);

    (new RunExtractionJob($s['run']->id))->handle();

    $found = [];
    foreach ([$this->root, $this->root.'-copilot'] as $dir) {
        if (! is_dir($dir)) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && str_contains((string) file_get_contents($file->getPathname()), MedicalPrivacy::CANARY)) {
                $found[] = $file->getPathname();
            }
        }
    }
    expect($found)->toBe([])->and(lkSinks())->not->toContain(MedicalPrivacy::CANARY);
});

it('puts only the extraction id into the jobs table when a waiting run is resumed', function () {
    $s = lkSetup();
    DB::table('medical_ai_extractions')->where('id', $s['run']->id)->update(['status' => 'waiting', 'available_at' => now()->subMinute(), 'waiting_since' => now()]);
    DB::table('ai_calls')->where('extraction_id', $s['run']->id)->update(['status' => 'cancelled', 'finished_at' => now()]);

    Artisan::call('ai:resume-waiting');

    $row = DB::table('jobs')->first();
    $payload = (string) $row->payload;
    expect($row->queue)->toBe('ai')->and($payload)->toContain($s['run']->id);
    foreach ([MedicalPrivacy::CANARY, $s['subject']->name, $s['doc']->title, $s['doc']->path, $s['doc']->id, $s['subject']->id, 'jpeg-bytes'] as $needle) {
        expect($payload)->not->toContain($needle);
    }
});
