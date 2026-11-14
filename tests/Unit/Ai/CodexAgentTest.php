<?php

use App\Medical\Ai\Agents\AgentInput;
use App\Medical\Ai\Agents\AgentRegistry;
use App\Medical\Ai\Agents\CodexAgent;
use App\Medical\Ai\ExtractionSchema;
use App\Medical\Ai\FailureCode;
use App\Medical\Ai\PromptBuilder;
use App\Medical\Ai\Work\WorkDir;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Support\FakeCodex;
use Tests\TestCase;

uses(TestCase::class);

function agentPayload(): array
{
    return [
        'schema_version' => 1,
        'document_date' => '2026-03-04',
        'specialty_key' => 'cardiology',
        'visits' => [
            ['visited_on' => '2026-03-04', 'doctor' => 'Dr. Novak', 'specialty_key' => 'cardiology', 'specialty_other' => null, 'diagnosis' => 'Hypertension', 'notes' => 'Control in 3 months'],
            ['visited_on' => null, 'doctor' => null, 'specialty_key' => null, 'specialty_other' => null, 'diagnosis' => null, 'notes' => null],
        ],
        'medications' => [['name' => 'Enalapril', 'dose' => '10 mg', 'schedule' => 'daily', 'started_on' => '2026-03-04', 'ended_on' => null, 'notes' => null]],
        'allergies' => [['substance' => 'Penicillin', 'reaction' => 'Rash', 'severity' => 'moderate']],
        'vaccinations' => [['vaccine' => 'Tetanus', 'given_on' => '2025-01-10', 'next_due_on' => '2035-01-10', 'notes' => null]],
    ];
}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/ai-agent-'.strtolower((string) Str::ulid());
    $this->scripts = [];
    config(['ai.work_dir' => $this->root, 'ai.codex.home' => $this->root, 'ai.codex.model' => null]);
    $this->dir = WorkDir::create();
    $this->dir->put('page-1.jpg', 'x');
    $this->use = function (string $script) {
        $this->scripts[] = $script;
        config(['ai.codex.binary' => $script]);

        return (new CodexAgent)->extract(new AgentInput($this->dir, ['page-1.jpg']));
    };
});

afterEach(function () {
    foreach ($this->scripts as $s) {
        @unlink($s);
    }
    WorkDir::removeTree($this->root);
});

it('succeeds with a golden payload and hands the CLI the schema and prompt', function () {
    $rec = $this->root.'-rec.txt';
    $this->scripts[] = $rec;
    $out = ($this->use)(FakeCodex::recording($rec, json_encode(agentPayload())));

    expect($out->ok)->toBeTrue()
        ->and($out->payload)->toBe(agentPayload())
        ->and($out->entryCount)->toBe(5)
        ->and($out->failure)->toBeNull()
        ->and($out->aborted)->toBeFalse();
    expect(file_get_contents($this->dir->file('schema.json')))->toBe(file_get_contents(ExtractionSchema::path()));
    $log = file_get_contents($rec);
    expect(substr($log, strpos($log, "---STDIN---\n") + 12))->toBe((new PromptBuilder)->build());
});

it('maps each failure to a content-free code', function (string $kind, FailureCode $expected) {
    config(['ai.timeouts.process' => $kind === 'timeout' ? 1 : 30, 'ai.timeouts.idle' => 30]);
    $script = match ($kind) {
        'invalid' => FakeCodex::invalidJson(),
        'empty' => FakeCodex::emptyOutput(),
        'nofile' => FakeCodex::exitZeroNoFile(),
        'cli' => FakeCodex::failing(1, 'boom'),
        'auth' => FakeCodex::failing(1, '401 Unauthorized'),
        'timeout' => FakeCodex::hanging(),
        'flood' => FakeCodex::flooding('stdout'),
    };
    $out = ($this->use)($script);

    expect($out->ok)->toBeFalse()->and($out->failure)->toBe($expected)->and($out->payload)->toBeNull();
})->with([
    ['invalid', FailureCode::InvalidOutput],
    ['empty', FailureCode::NoOutput],
    ['nofile', FailureCode::NoOutput],
    ['cli', FailureCode::CliError],
    ['auth', FailureCode::Auth],
    ['timeout', FailureCode::Timeout],
    ['flood', FailureCode::OutputTooLarge],
]);

it('maps a silent CLI to idle_timeout', function () {
    config(['ai.timeouts.process' => 30, 'ai.timeouts.idle' => 1]);
    expect(($this->use)(FakeCodex::silent())->failure)->toBe(FailureCode::IdleTimeout);
});

it('returns aborted with no failure code when asked to stop', function () {
    config(['ai.timeouts.abort_check_seconds' => 0]);
    $script = FakeCodex::hanging();
    $this->scripts[] = $script;
    config(['ai.codex.binary' => $script]);
    $out = (new CodexAgent)->extract(new AgentInput($this->dir, ['page-1.jpg']), fn () => true);

    expect($out->aborted)->toBeTrue()->and($out->failure)->toBeNull()->and($out->ok)->toBeFalse();
});

it('never leaks CLI text into a failed outcome', function () {
    $canary = 'CANARY-'.bin2hex(random_bytes(4));
    $out = ($this->use)(FakeCodex::echoing($canary));

    expect($out->ok)->toBeFalse()
        ->and(var_export($out, true))->not->toContain($canary);
});

it('reports the model the CLI reported, else the label', function () {
    $out = ($this->use)(FakeCodex::emittingModel('gpt-test-1'));
    expect($out->model)->toBe('gpt-test-1');

    $out = ($this->use)(FakeCodex::exitZeroNoFile());
    expect($out->model)->toBe('default');

    config(['ai.codex.model' => 'gpt-conf']);
    expect((new CodexAgent)->modelLabel())->toBe('gpt-conf');
});

it('registers exactly the codex agent', function () {
    $registry = app(AgentRegistry::class);

    expect($registry->agents())->toHaveCount(1)
        ->and($registry->primary()->key())->toBe('codex')
        ->and($registry->find('codex'))->toBeInstanceOf(CodexAgent::class)
        ->and($registry->find('nope'))->toBeNull()
        ->and($registry->primary()->modelLabel())->toBe('default')
        ->and(app(AgentRegistry::class))->toBe($registry);
});

it('records the configured model, never text the CLI echoed, and drops an invalid configured value', function () {
    config(['ai.codex.model' => 'gpt-conf']);
    $out = ($this->use)(FakeCodex::emittingModel('gpt-injected'));
    expect($out->model)->toBe('gpt-conf');

    foreach (['--sandbox', '-m', 'a b', 'x=y', str_repeat('a', 61), '-evil'] as $bad) {
        config(['ai.codex.model' => $bad]);
        expect((new CodexAgent)->modelLabel())->toBe('default');
    }
});

it('maps a usage limit to quota_exhausted with the reset time', function () {
    CarbonImmutable::setTestNow('2026-11-14 10:00:00');
    $out = ($this->use)(FakeCodex::quota("You've hit your usage limit. Try again in 3 hours."));

    expect($out->ok)->toBeFalse()
        ->and($out->failure)->toBe(FailureCode::QuotaExhausted)
        ->and($out->quotaMinutes)->toBe(60)
        ->and($out->quotaResetAt?->toIso8601String())->toBe(CarbonImmutable::parse('2026-11-14 13:01:00', 'UTC')->toIso8601String());
    CarbonImmutable::setTestNow();
});

it('lets a valid result win over quota text and exit code', function () {
    $out = ($this->use)(FakeCodex::quotaWithValidResult("You've hit your usage limit.", json_encode(agentPayload())));

    expect($out->ok)->toBeTrue()->and($out->payload)->toBe(agentPayload());
});

it('does not treat quota words in the answer as quota', function () {
    $out = ($this->use)(FakeCodex::quotaInAnswer("You've hit your usage limit."));

    expect($out->failure)->toBe(FailureCode::InvalidOutput);
});

it('takes the admin model override and ignores an invalid one', function () {
    $rec = $this->root.'-rec.txt';
    $this->scripts[] = $rec;
    $script = FakeCodex::recording($rec, json_encode(agentPayload()));
    $this->scripts[] = $script;
    config(['ai.codex.binary' => $script, 'ai.codex.model' => 'gpt-conf']);

    $out = (new CodexAgent(null, 'gpt-test-2'))->extract(new AgentInput($this->dir, ['page-1.jpg']));
    expect($out->model)->toBe('gpt-test-2')->and(file_get_contents($rec))->toContain("\n-m\ngpt-test-2\n");

    config(['ai.codex.model' => null]);
    $out = (new CodexAgent(null, '-x'))->extract(new AgentInput($this->dir, ['page-1.jpg']));
    expect($out->model)->toBe('default')->and(file_get_contents($rec))->not->toContain("\n-m\n");
});

it('returns a timeout without spawning when the deadline leaves too little', function () {
    $rec = $this->root.'-rec2.txt';
    $this->scripts[] = $rec;
    $script = FakeCodex::recording($rec, json_encode(agentPayload()));
    $this->scripts[] = $script;
    config(['ai.codex.binary' => $script]);

    $out = (new CodexAgent)->extract(new AgentInput($this->dir, ['page-1.jpg'], time() + 20));

    expect($out->failure)->toBe(FailureCode::Timeout)->and(is_file($rec))->toBeFalse();
});

it('fits the process timeout inside the remaining deadline', function () {
    config(['ai.timeouts.process' => 300, 'ai.timeouts.idle' => 300, 'ai.timeouts.min_call' => 1]);
    $script = FakeCodex::hanging();
    $this->scripts[] = $script;
    config(['ai.codex.binary' => $script]);

    $t = microtime(true);
    $out = (new CodexAgent)->extract(new AgentInput($this->dir, ['page-1.jpg'], time() + 8));

    expect($out->failure)->toBe(FailureCode::Timeout)->and(microtime(true) - $t)->toBeLessThan(6.0);
});
