<?php

use App\Medical\Ai\Agents\AgentInput;
use App\Medical\Ai\Agents\CopilotAgent;
use App\Medical\Ai\FailureCode;
use App\Medical\Ai\LoginState;
use App\Medical\Ai\Work\WorkDir;
use Illuminate\Support\Str;
use Tests\Support\FakeCopilot;
use Tests\TestCase;

uses(TestCase::class);

function copilotPayload(): array
{
    return [
        'schema_version' => 1,
        'document_date' => '2026-03-04',
        'specialty_key' => 'cardiology',
        'visits' => [
            ['visited_on' => '2026-03-04', 'doctor' => 'Dr. Novak', 'specialty_key' => 'cardiology', 'specialty_other' => null, 'diagnosis' => 'Hypertension', 'notes' => 'Control in 3 months'],
        ],
        'medications' => [['name' => 'Enalapril', 'dose' => '10 mg', 'schedule' => 'daily', 'started_on' => '2026-03-04', 'ended_on' => null, 'notes' => null]],
        'allergies' => [['substance' => 'Penicillin', 'reaction' => 'Rash', 'severity' => 'moderate']],
        'vaccinations' => [['vaccine' => 'Tetanus', 'given_on' => '2025-01-10', 'next_due_on' => '2035-01-10', 'notes' => null]],
    ];
}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/ai-cpa-'.strtolower((string) Str::ulid());
    $this->scripts = [];
    config([
        'ai.work_dir' => $this->root, 'ai.codex.home' => $this->root.'-codex',
        'ai.copilot.home' => $this->root.'-home', 'ai.copilot.cache' => $this->root.'-cache', 'ai.copilot.model' => null,
        'ai.timeouts.process' => 30, 'ai.timeouts.idle' => 30, 'ai.timeouts.min_call' => 30,
        'ai.copilot.repair_min_seconds' => 45,
    ]);
    $this->dir = WorkDir::create();
    $this->dir->put('page-1.jpg', 'x');
    $this->counter = $this->root.'-count';
    $this->scripts[] = $this->counter;
    $this->use = function (string $script, ?int $deadline = null, ?string $model = null, ?Closure $abort = null) {
        $this->scripts[] = $script;
        config(['ai.copilot.binary' => $script]);

        return (new CopilotAgent(null, $model))->extract(new AgentInput($this->dir, ['page-1.jpg'], $deadline), $abort);
    };
    $this->calls = fn () => is_file($this->counter) ? (int) file_get_contents($this->counter) : 0;
});

afterEach(function () {
    foreach ($this->scripts as $s) {
        @unlink($s);
    }
    foreach (glob($this->counter.'.p*') ?: [] as $f) {
        @unlink($f);
    }
    WorkDir::removeTree($this->root);
});

it('succeeds with a golden reply, plain or fenced', function (bool $fenced) {
    $json = json_encode(copilotPayload());
    $out = ($this->use)($fenced ? FakeCopilot::fenced($json) : FakeCopilot::replying($json));

    expect($out->ok)->toBeTrue()->and($out->payload)->toBe(copilotPayload())->and($out->entryCount)->toBe(4)
        ->and($out->model)->toBe('default')->and($out->failure)->toBeNull()->and($out->aborted)->toBeFalse();
})->with([false, true]);

it('lets a valid reply win over a failing exit code', function () {
    $out = ($this->use)(FakeCopilot::replying(json_encode(copilotPayload()), 1));
    expect($out->ok)->toBeTrue();
});

it('repairs one invalid reply with issue codes only and sums the durations', function () {
    $canary = 'CANARY-'.bin2hex(random_bytes(4));
    $bad = json_encode(['schema_version' => 1, $canary => $canary]);
    $out = ($this->use)(FakeCopilot::sequence([$bad, json_encode(copilotPayload())], $this->counter));

    expect($out->ok)->toBeTrue()->and(($this->calls)())->toBe(2);
    $first = file_get_contents($this->counter.'.p1');
    $second = file_get_contents($this->counter.'.p2');
    expect($first)->not->toContain('Your previous reply was rejected')
        ->and($second)->toContain('Your previous reply was rejected. Problems: ')->toContain('root:')
        ->and($second)->not->toContain($canary)
        ->and(str_starts_with($second, $first))->toBeTrue();
    expect($out->durationMs)->toBeGreaterThan(0);
});

it('repairs a prose reply with a json syntax code', function () {
    $out = ($this->use)(FakeCopilot::sequence(['Sure, here you go', json_encode(copilotPayload())], $this->counter));
    expect($out->ok)->toBeTrue()
        ->and(file_get_contents($this->counter.'.p2'))->toContain('root:json_syntax');
});

it('stops after exactly one repair', function () {
    $out = ($this->use)(FakeCopilot::sequence(['{"a":1}', '{"b":2}', json_encode(copilotPayload())], $this->counter));
    expect($out->ok)->toBeFalse()->and($out->failure)->toBe(FailureCode::InvalidOutput)->and(($this->calls)())->toBe(2);
});

it('skips the repair when too little time is left', function () {
    $out = ($this->use)(FakeCopilot::sequence(['{"a":1}', json_encode(copilotPayload())], $this->counter), time() + 40);
    expect($out->failure)->toBe(FailureCode::InvalidOutput)->and(($this->calls)())->toBe(1);
});

it('times out without spawning when the deadline is too close', function () {
    $out = ($this->use)(FakeCopilot::sequence([json_encode(copilotPayload())], $this->counter), time() + 5);
    expect($out->failure)->toBe(FailureCode::Timeout)->and(($this->calls)())->toBe(0);
});

it('maps a quota message on stderr to quota_exhausted with a reset time and never repairs', function () {
    $out = ($this->use)(FakeCopilot::quota("You've hit your session rate limit. Try again in 3 hours."));
    expect($out->ok)->toBeFalse()->and($out->failure)->toBe(FailureCode::QuotaExhausted)
        ->and($out->quotaResetAt)->not->toBeNull()->and($out->quotaMinutes)->toBe(60);
});

it('maps missing authentication to auth', function () {
    $out = ($this->use)(FakeCopilot::unauthenticated());
    expect($out->failure)->toBe(FailureCode::Auth)->and($out->quotaResetAt)->toBeNull();
});

it('does not treat a limit message on stdout as quota', function () {
    $out = ($this->use)(FakeCopilot::echoQuotaOnStdout());
    expect($out->failure)->toBe(FailureCode::InvalidOutput)->and($out->quotaResetAt)->toBeNull();
});

it('maps timeout and abort without a repair', function () {
    config(['ai.timeouts.process' => 1]);
    $out = ($this->use)(FakeCopilot::hanging());
    expect($out->failure)->toBe(FailureCode::Timeout);

    config(['ai.timeouts.process' => 30, 'ai.timeouts.abort_check_seconds' => 0]);
    $out = ($this->use)(FakeCopilot::hanging(), null, null, fn () => true);
    expect($out->aborted)->toBeTrue()->and($out->failure)->toBeNull()->and($out->ok)->toBeFalse();
});

it('maps an empty failing run to cli_error and an empty clean run to no_output', function () {
    $out = ($this->use)(FakeCopilot::replying('', 1));
    expect($out->failure)->toBe(FailureCode::CliError);

    $out = ($this->use)(FakeCopilot::replying(''));
    expect($out->failure)->toBe(FailureCode::NoOutput);
});

it('never leaks CLI text into an outcome', function () {
    $canary = 'CANARY-'.bin2hex(random_bytes(4));
    $out = ($this->use)(FakeCopilot::echoing($canary));
    expect($out->ok)->toBeFalse()->and(var_export($out, true))->not->toContain($canary);
});

it('records the configured model, else the first valid usage model, else default', function () {
    $json = json_encode(copilotPayload());
    $rec = $this->root.'-rec.txt';
    $this->scripts[] = $rec;
    $out = ($this->use)(FakeCopilot::recording($rec, $json), null, 'claude-haiku-4.5');
    expect($out->model)->toBe('claude-haiku-4.5')
        ->and(file_get_contents($rec))->toContain("\n--model\nclaude-haiku-4.5\n");

    $out = ($this->use)(FakeCopilot::usageFile('{"modelMetrics":{"gpt-5.6-sol":{}}}', $json));
    expect($out->ok)->toBeTrue()->and($out->model)->toBe('gpt-5.6-sol');

    $out = ($this->use)(FakeCopilot::usageFile('{"modelMetrics":{"bad model":{}}}', $json));
    expect($out->model)->toBe('default');

    config(['ai.copilot.model' => 'from-config']);
    expect((new CopilotAgent)->modelLabel())->toBe('from-config')
        ->and((new CopilotAgent(null, '--evil'))->modelLabel())->toBe('from-config')
        ->and((new CopilotAgent(null, 'ok-model'))->modelLabel())->toBe('ok-model');
});

it('delegates the login status and has its own key', function () {
    config(['ai.copilot.home' => FakeCopilot::loginHome($this->root.'-home', true)]);
    $agent = new CopilotAgent;
    expect($agent->key())->toBe('copilot')->and($agent->loginStatus())->toBe(LoginState::LoggedIn);
    WorkDir::removeTree($this->root.'-home');
});

it('reports every CLI spawn, the repair call included, and none when the deadline leaves no time', function () {
    $spawns = 0;
    $script = FakeCopilot::sequence(['Sure, here you go', json_encode(copilotPayload())], $this->counter);
    $this->scripts[] = $script;
    config(['ai.copilot.binary' => $script]);

    $out = (new CopilotAgent(null, null, function () use (&$spawns) {
        $spawns++;
    }))->extract(new AgentInput($this->dir, ['page-1.jpg'], null));
    expect($out->ok)->toBeTrue()->and(($this->calls)())->toBe(2)->and($spawns)->toBe(2);

    $none = 0;
    $out = (new CopilotAgent(null, null, function () use (&$none) {
        $none++;
    }))->extract(new AgentInput($this->dir, ['page-1.jpg'], time() + 5));
    expect($out->ok)->toBeFalse()->and($none)->toBe(0);
});
