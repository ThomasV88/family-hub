<?php

use App\Access\AccessLevel;
use App\Medical\Ai\AgentHealth;
use App\Medical\Ai\LoginState;
use App\Medical\Ai\RunExtractionJob;
use App\Medical\Ai\Work\WorkDir;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FakeCodex;
use Tests\Support\ImageFixtures;
use Tests\Support\MedicalFixtures;

uses(RefreshDatabase::class);

const UB_PRIV_TITLE = 'ZxBatchTitle';
const UB_PRIV_FINDING = 'ZxBatchFinding';
const UB_PRIV_TEXT = 'ZxBatchText';

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    Storage::fake('medical');
    Bus::fake();
    $this->scripts = [];
    $this->output = '';
    $this->root = sys_get_temp_dir().'/ub-priv-'.strtolower((string) Str::ulid());
    config([
        'ai.work_dir' => $this->root, 'ai.codex.home' => $this->root, 'ai.codex.model' => null, 'ai.ocr.enabled' => true,
        'ai.timeouts.abort_check_seconds' => 0.2,
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

/** @return array<string, int> size of every log file now */
function ubPrivLogSizes(): array
{
    $sizes = [];
    foreach (glob(storage_path('logs/*')) ?: [] as $f) {
        $sizes[$f] = (int) filesize($f);
    }

    return $sizes;
}

function ubPrivLogSince(array $before): string
{
    clearstatcache();
    $out = '';
    foreach (glob(storage_path('logs/*')) ?: [] as $f) {
        $out .= (string) substr((string) file_get_contents($f), $before[$f] ?? 0);
    }

    return $out;
}

/** Runs an artisan command and keeps what it printed. */
function ubPrivArtisan(string $command): void
{
    Artisan::call($command);
    test()->output .= Artisan::output();
}

function ubPrivFake(object $test, string $script): void
{
    $test->scripts[] = $script;
    config(['ai.codex.binary' => $script]);
}

function ubPrivReadingFake(object $test): void
{
    $state = sys_get_temp_dir().'/ub-priv-state-'.bin2hex(random_bytes(4));
    $log = sys_get_temp_dir().'/ub-priv-log-'.bin2hex(random_bytes(4));
    $payload = [
        'schema_version' => 1, 'document_date' => null, 'specialty_key' => null,
        'visits' => [['visited_on' => '2026-03-04', 'doctor' => null, 'specialty_key' => 'general_practice', 'specialty_other' => null, 'diagnosis' => UB_PRIV_FINDING, 'notes' => null]],
        'medications' => [], 'allergies' => [], 'vaccinations' => [],
    ];
    array_push($test->scripts, $state, $log);
    ubPrivFake($test, FakeCodex::transcribing($state, $log, UB_PRIV_TEXT.' Page', ['extract' => json_encode($payload)]));
}

/** Starts what the tick would start and runs every queued job inline (the bus is faked), until nothing moves. */
function ubPrivDrain(): void
{
    for ($i = 0; $i < 12; $i++) {
        ubPrivArtisan('ai:upload-batch-tick');
        $queued = DB::table('medical_ai_extractions')->where('status', 'queued')->pluck('id');
        if ($queued->isEmpty()) {
            return;
        }
        foreach ($queued as $id) {
            (new RunExtractionJob((string) $id))->handle();
        }
    }
}

it('leaves no title, file path, recognised text or finding of a whole batch flow in any log, audit row, call row, failed job or command output', function () {
    $user = User::factory()->create();
    MedicalFixtures::grantHealth($user, AccessLevel::Edit);
    $person = MedicalFixtures::subject(['ai_consent' => true], [[$user, AccessLevel::Edit]]);
    DB::table('ai_notice_acks')->insert(['user_id' => $user->id, 'providers' => 'codex', 'acknowledged_at' => now()]);
    app(AgentHealth::class)->recordCheck('codex', LoginState::LoggedIn);
    ubPrivReadingFake($this);
    $logs = ubPrivLogSizes();
    $base = '/health/people/'.$person->id.'/upload-batches';
    $ack = ['acknowledged' => true, 'providers' => ['codex'], 'textNotice' => true];
    $paths = [];
    $keepPaths = function () use (&$paths) {
        foreach (DB::table('medical_documents')->get(['path', 'thumb_path']) as $d) {
            $paths[] = (string) $d->path;
            $paths[] = (string) $d->thumb_path;
        }
    };

    // create (the notice is acknowledged), upload three files
    $batch = $this->actingAs($user)->postJson($base, $ack)->assertCreated()->json('batchId');
    foreach ([1, 2, 3] as $n) {
        $this->actingAs($user)->post('/health/people/'.$person->id.'/documents', [
            'file' => UploadedFile::fake()->createWithContent(UB_PRIV_TITLE.$n.'.jpg', ImageFixtures::jpeg(64 + $n * 8, 48)),
            'upload_batch_id' => $batch,
        ], ['Accept' => 'application/json'])->assertCreated();
    }
    $keepPaths();
    $items = DB::table('medical_upload_batch_items')->where('batch_id', $batch)->orderBy('position')->pluck('id')->all();

    // the fake agent reads all three; tick; choices with an edited value; continue
    ubPrivDrain();
    expect(DB::table('medical_ai_extractions')->where('status', 'ready')->count())->toBe(3);
    $this->actingAs($user)->putJson("$base/$batch/items/{$items[0]}/choices", ['choices' => ['visit-0' => ['decision' => 'approve', 'values' => ['diagnosis' => UB_PRIV_FINDING.' edited']]]])->assertOk();
    $this->actingAs($user)->postJson("$base/$batch/items/{$items[0]}/continue")->assertOk();

    // adjustments and a re-scan
    $this->actingAs($user)->putJson("$base/$batch/items/{$items[1]}/adjustments", ['brightness' => 120, 'contrast' => 140, 'bw' => true])->assertOk();
    $this->actingAs($user)->postJson("$base/$batch/items/{$items[1]}/rescan", $ack)->assertStatus(202);
    ubPrivDrain();

    // the agent hits its usage limit: the next re-scan parks
    ubPrivFake($this, FakeCodex::failing(1, "You've hit your usage limit. Try again later."));
    $this->actingAs($user)->postJson("$base/$batch/items/{$items[2]}/rescan", $ack)->assertStatus(202);
    ubPrivDrain();
    expect(DB::table('medical_ai_extractions')->where('status', 'waiting')->count())->toBe(1);
    $keepPaths();

    // consent off cancels the batch work, then the batch is deleted
    $this->actingAs($user)->patch('/health/people/'.$person->id.'/ai-consent', ['value' => false]);
    ubPrivArtisan('ai:upload-batch-tick');
    $this->actingAs($user)->deleteJson("$base/$batch")->assertOk();
    expect(DB::table('medical_upload_batches')->count())->toBe(0);

    // a second batch expires
    ubPrivReadingFake($this);
    $this->actingAs($user)->patch('/health/people/'.$person->id.'/ai-consent', ['value' => true]);
    $second = $this->actingAs($user)->postJson($base, $ack)->assertCreated()->json('batchId');
    $this->actingAs($user)->post('/health/people/'.$person->id.'/documents', [
        'file' => UploadedFile::fake()->createWithContent(UB_PRIV_TITLE.'9.jpg', ImageFixtures::jpeg(120, 48)),
        'upload_batch_id' => $second,
    ], ['Accept' => 'application/json'])->assertCreated();
    $keepPaths();
    DB::table('medical_upload_batches')->where('id', $second)->update(['expires_at' => now()->subMinute()]);
    ubPrivArtisan('medical:expire-upload-batches');
    expect(DB::table('medical_upload_batches')->count())->toBe(0);

    $sinks = json_encode([
        'log' => ubPrivLogSince($logs),
        'audit' => DB::table('audit_logs')->get(),
        'ai_calls' => DB::table('ai_calls')->get(),
        'failed_jobs' => DB::table('failed_jobs')->get(),
        'jobs' => DB::table('jobs')->get(),
        'output' => $this->output,
    ]) ?: '';
    expect($sinks)->not->toBe('')
        ->and(DB::table('audit_logs')->count())->toBeGreaterThan(5);
    foreach ([UB_PRIV_TITLE, UB_PRIV_FINDING, UB_PRIV_TEXT, 'Page 1'] as $needle) {
        expect($sinks)->not->toContain($needle);
    }
    $paths = array_values(array_unique(array_filter($paths)));
    expect($paths)->not->toBeEmpty();
    foreach ($paths as $path) {
        expect($sinks)->not->toContain($path);
    }
});
