<?php

use App\Medical\Ai\Agents\AgentInput;
use App\Medical\Ai\Agents\CodexAgent;
use App\Medical\Ai\Agents\CopilotAgent;
use App\Medical\Ai\AiTask;
use App\Medical\Ai\ExtractionOutcome;
use App\Medical\Ai\FailureCode;
use App\Medical\Ai\Work\WorkDir;
use App\Recipes\Ai\RecipeTask;
use Illuminate\Support\Str;
use Tests\Support\FakeCodex;
use Tests\Support\FakeCopilot;
use Tests\TestCase;

uses(TestCase::class);

/** A stub task: accepts a reply only if it is {"ok":true}. */
final class SeamStubTask implements AiTask
{
    public array $inlineCalls = [];

    public function __construct(public string $schema) {}

    public function schemaPath(): string
    {
        return $this->schema;
    }

    public function prompt(): string
    {
        return 'STUB-PROMPT';
    }

    public function inlinePrompt(?array $issueCodes): string
    {
        $this->inlineCalls[] = $issueCodes;

        return 'STUB-INLINE '.implode(',', $issueCodes ?? []);
    }

    public function validateFile(string $path): ExtractionOutcome
    {
        if (! is_file($path) || filesize($path) === 0) {
            return ExtractionOutcome::failed(FailureCode::NoOutput);
        }

        return $this->inspect((string) file_get_contents($path))['outcome'];
    }

    public function inspect(string $json): array
    {
        $d = json_decode($json, true);
        if (is_array($d) && ($d['ok'] ?? null) === true) {
            return ['outcome' => ExtractionOutcome::success($d, 1), 'issues' => []];
        }

        return ['outcome' => ExtractionOutcome::failed(FailureCode::InvalidOutput), 'issues' => ['root:bad_value']];
    }
}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/ai-seam-'.strtolower((string) Str::ulid());
    $this->scripts = [];
    config([
        'ai.work_dir' => $this->root, 'ai.codex.home' => $this->root.'-codex', 'ai.codex.model' => null,
        'ai.copilot.home' => $this->root.'-home', 'ai.copilot.cache' => $this->root.'-cache', 'ai.copilot.model' => null,
        'ai.timeouts.process' => 30, 'ai.timeouts.idle' => 30, 'ai.timeouts.min_call' => 30, 'ai.copilot.repair_min_seconds' => 45,
    ]);
    $this->dir = WorkDir::create();
    $this->schema = $this->root.'-schema.json';
    file_put_contents($this->schema, '{"stub":true}');
    $this->scripts[] = $this->schema;
    $this->counter = $this->root.'-count';
    $this->scripts[] = $this->counter;
});

afterEach(function () {
    foreach ($this->scripts as $s) {
        @unlink($s);
    }
    foreach ([1, 2] as $n) {
        @unlink($this->counter.'.p'.$n);
    }
    WorkDir::removeTree($this->root);
});

it('runs a stub task through CodexAgent with its schema and prompt and no image argument', function () {
    $rec = $this->root.'-rec.txt';
    $this->scripts[] = $rec;
    $script = FakeCodex::recording($rec, '{"ok":true}');
    $this->scripts[] = $script;
    config(['ai.codex.binary' => $script]);

    $out = (new CodexAgent)->extract(new AgentInput($this->dir, [], null, new SeamStubTask($this->schema)));
    $log = (string) file_get_contents($rec);

    expect($out->ok)->toBeTrue()
        ->and($out->entryCount)->toBe(1)
        ->and(substr($log, strpos($log, "---STDIN---\n") + 12))->toBe('STUB-PROMPT')
        ->and(explode("\n", explode("\n---ENV---", $log)[0]))->not->toContain('-i')
        ->and(file_get_contents($this->dir->file('schema.json')))->toBe('{"stub":true}');
});

it('decides CodexAgent success only through the task', function () {
    $a = FakeCodex::exitZeroNoFile();
    $b = FakeCodex::invalidJson();
    $this->scripts[] = $a;
    $this->scripts[] = $b;
    foreach ([$a, $b] as $script) {
        config(['ai.codex.binary' => $script]);
        $out = (new CodexAgent)->extract(new AgentInput($this->dir, [], null, new SeamStubTask($this->schema)));
        expect($out->ok)->toBeFalse();
    }
});

it('runs a stub task through CopilotAgent with at most one repair call carrying issue codes only', function () {
    $script = FakeCopilot::sequence(['{"nope":1}', '{"ok":true}'], $this->counter);
    $this->scripts[] = $script;
    config(['ai.copilot.binary' => $script]);
    $task = new SeamStubTask($this->schema);

    $out = (new CopilotAgent)->extract(new AgentInput($this->dir, [], null, $task));

    expect($out->ok)->toBeTrue()
        ->and($task->inlineCalls)->toBe([null, ['root:bad_value']])
        ->and(file_get_contents($this->counter))->toBe('2')
        ->and(file_get_contents($this->counter.'.p2'))->toBe('STUB-INLINE root:bad_value');
});

it('gives up after the single repair call', function () {
    $script = FakeCopilot::sequence(['{"nope":1}'], $this->counter);
    $this->scripts[] = $script;
    config(['ai.copilot.binary' => $script]);

    $out = (new CopilotAgent)->extract(new AgentInput($this->dir, [], null, new SeamStubTask($this->schema)));

    expect($out->ok)->toBeFalse()->and($out->failure)->toBe(FailureCode::InvalidOutput)->and(file_get_contents($this->counter))->toBe('2');
});

it('runs the recipe task through both agents; a no-recipe reply is final without a repair call', function () {
    $recipe = json_encode(['schema_version' => 1, 'is_recipe' => true, 'title' => 'Soup', 'description' => null, 'servings' => 4, 'prep_minutes' => null, 'cook_minutes' => null,
        'categories' => [], 'ingredients' => [['name' => 'Water', 'quantity' => 1, 'unit' => 'l', 'note' => null, 'original' => '1 l water']], 'steps' => ['Boil.']]);
    $none = json_encode(['schema_version' => 1, 'is_recipe' => false, 'title' => null, 'description' => null, 'servings' => null, 'prep_minutes' => null, 'cook_minutes' => null,
        'categories' => [], 'ingredients' => [], 'steps' => []]);

    $codex = FakeCodex::succeeding($recipe);
    $copilot = FakeCopilot::sequence([$none], $this->counter);
    $this->scripts[] = $codex;
    $this->scripts[] = $copilot;

    config(['ai.codex.binary' => $codex]);
    $a = (new CodexAgent)->extract(new AgentInput($this->dir, [], null, new RecipeTask('Soup page')));
    config(['ai.copilot.binary' => $copilot]);
    $b = (new CopilotAgent)->extract(new AgentInput($this->dir, [], null, new RecipeTask('Not a recipe')));

    expect($a->ok)->toBeTrue()->and($a->entryCount)->toBe(1)->and($a->payload['title'])->toBe('Soup')
        ->and($b->ok)->toBeFalse()->and($b->failure)->toBe(FailureCode::NoRecipe)->and(file_get_contents($this->counter))->toBe('1');
});
