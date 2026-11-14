<?php

use App\Medical\Ai\AgentHealth;
use App\Medical\Ai\CheckLoginJob;
use App\Medical\Ai\LoginState;
use App\Medical\Ai\RunExtractionJob;
use App\Models\AiAgentStatus;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeCodex;

beforeEach(function () {
    $this->scripts = [];
    config(['ai.codex.home' => sys_get_temp_dir()]);
    $this->check = function (int $exit, string $text) {
        $script = FakeCodex::loginStatus($exit, $text);
        $this->scripts[] = $script;
        config(['ai.codex.binary' => $script]);
        app()->call([new CheckLoginJob, 'handle']);
    };
});

afterEach(function () {
    Carbon::setTestNow();
    foreach ($this->scripts as $s) {
        @unlink($s);
    }
});

it('is unknown but runnable before any check', function () {
    $h = new AgentHealth;

    expect($h->display())->toBe('unknown')->and($h->runnable())->toBeTrue();
});

it('records a logged in check', function () {
    ($this->check)(0, 'Logged in using ChatGPT.');
    $h = new AgentHealth;

    expect($h->display())->toBe('logged_in')->and($h->runnable())->toBeTrue();
    $row = AiAgentStatus::find('codex');
    expect($row->login_state)->toBe('logged_in')->and($row->checked_at)->not->toBeNull();
});

it('treats logged out and API-key mode as expired and not runnable', function () {
    ($this->check)(1, 'Not logged in');
    $h = new AgentHealth;
    expect($h->display())->toBe('expired')->and($h->runnable())->toBeFalse();

    ($this->check)(0, 'Logged in using an API key - sk-proj-xxxx');
    expect(AiAgentStatus::find('codex')->login_state)->toBe('wrong_mode')
        ->and($h->display())->toBe('expired')->and($h->runnable())->toBeFalse();
});

it('is not responding when the check is stale or inconclusive', function () {
    ($this->check)(0, 'Logged in using ChatGPT.');
    $h = new AgentHealth;
    Carbon::setTestNow(now()->addMinutes(16));

    expect($h->display())->toBe('not_responding')->and($h->runnable())->toBeFalse();

    Carbon::setTestNow();
    ($this->check)(2, 'garbled');
    expect($h->display())->toBe('not_responding');
});

it('lets a real auth failure override until a later success', function () {
    $h = new AgentHealth;
    Carbon::setTestNow('2026-11-14 10:00:00');
    $h->recordCheck('codex', LoginState::LoggedIn);
    Carbon::setTestNow('2026-11-14 10:01:00');
    $h->recordAuthFailure('codex');

    expect($h->display())->toBe('expired')->and($h->runnable())->toBeFalse();

    Carbon::setTestNow('2026-11-14 10:02:00');
    $h->recordSuccess('codex');
    expect($h->display())->toBe('logged_in');
});

it('clears a stale auth failure once a later check sees the login present', function () {
    $h = new AgentHealth;
    Carbon::setTestNow('2026-11-14 10:00:00');
    $h->recordCheck('codex', LoginState::LoggedIn);
    Carbon::setTestNow('2026-11-14 10:01:00');
    $h->recordAuthFailure('codex');
    Carbon::setTestNow('2026-11-14 10:05:00');
    $h->recordCheck('codex', LoginState::LoggedIn);

    expect($h->display())->toBe('logged_in');
});

it('is a unique one-try job on the ai-control queue and database connection', function () {
    $job = new CheckLoginJob;

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueFor)->toBe(300)
        ->and($job->tries)->toBe(1)
        ->and($job->queue)->toBe('ai-control')
        ->and($job->connection)->toBe('database');

    CheckLoginJob::dispatch();
    CheckLoginJob::dispatch();
    expect(DB::table('jobs')->where('queue', 'ai-control')->count())->toBe(1);
});

it('stores only enums and timestamps in the status row', function () {
    $canary = 'CANARY-'.bin2hex(random_bytes(4));
    ($this->check)(1, 'Not logged in '.$canary);
    (new AgentHealth)->recordAuthFailure('codex');

    $row = (array) DB::table('ai_agent_status')->first();
    expect(json_encode($row))->not->toContain($canary)
        ->and($row['login_state'])->toBeIn(['logged_in', 'logged_out', 'wrong_mode', 'unknown']);
    foreach (['checked_at', 'last_success_at', 'last_auth_failure_at', 'updated_at'] as $c) {
        expect($row[$c] === null || strtotime((string) $row[$c]) !== false)->toBeTrue();
    }
});

it('queues the login check apart from extractions, so a backlog cannot starve it', function () {
    expect(config('ai.control_queue'))->not->toBe(config('ai.queue'))
        ->and((new CheckLoginJob)->queue)->toBe(config('ai.control_queue'))
        ->and((new RunExtractionJob((string) Str::uuid()))->queue)->toBe(config('ai.queue'));

    // The worker command lists the control queue first (checked statically by scripts/check-compose.sh).
    foreach (['compose.yaml', 'compose.server.yaml'] as $file) {
        expect(file_get_contents(base_path($file)))->toContain('--queue=ai-control,ai ');
    }
});
