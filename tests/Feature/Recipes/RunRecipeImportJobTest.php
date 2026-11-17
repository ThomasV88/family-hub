<?php

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Medical\Ai\AgentHealth;
use App\Medical\Ai\Work\WorkDir;
use App\Models\AiAgentSetting;
use App\Models\AiAgentStatus;
use App\Models\RecipeImport;
use App\Models\User;
use App\Recipes\Ai\RunRecipeImportJob;
use App\Recipes\Import\RecipeImportQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeCodex;
use Tests\Support\FakeCopilot;
use Tests\Support\RecipeImportFixtures as F;

const RJ_CANARY = 'PAGECANARY-flour-and-eggs';

/**
 * A member with an acknowledged recipe notice and one queued import with its queued call row.
 *
 * @return array{user: User, import: RecipeImport}
 */
function rjSetup(?string $ack = 'codex', array $over = []): array
{
    $user = F::member($ack);
    $import = RecipeImport::factory()->forUrl(F::PAGE_URL)->queued('Pancakes. '.RJ_CANARY.' Mix and fry.', 'json_ld')->create(array_merge(['user_id' => $user->id], $over));
    DB::table('ai_calls')->insert([
        'extraction_id' => $import->id, 'kind' => 'recipe', 'subject_id' => null, 'document_id' => null, 'started_by' => $user->id,
        'agent' => 'codex', 'attempt' => 1, 'status' => 'queued', 'queued_at' => now(),
    ]);

    return compact('user', 'import');
}

/** A fake codex shell script with a custom body ($out holds the -o path). */
function rjScript(string $body): string
{
    $path = sys_get_temp_dir().'/rj-fake-'.bin2hex(random_bytes(6));
    file_put_contents($path, "#!/bin/sh\nout=; prev=; for a in \"\$@\"; do [ \"\$prev\" = \"-o\" ] && out=\"\$a\"; prev=\"\$a\"; done; ".$body."\n");
    chmod($path, 0700);

    return $path;
}

function rjRun(RecipeImport $import): void
{
    (new RunRecipeImportJob($import->id))->handle();
}

function rjBin(string $agent, string $script): string
{
    $GLOBALS['rj_scripts'][] = $script;
    config(["ai.{$agent}.binary" => $script]);

    return $script;
}

function rjRow(RecipeImport $import): object
{
    return DB::table('recipe_imports')->where('id', $import->id)->first();
}

/** @return list<object> */
function rjCalls(RecipeImport $import): array
{
    return DB::table('ai_calls')->where('extraction_id', $import->id)->orderBy('attempt')->get()->all();
}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/rj-work-'.strtolower((string) Str::ulid());
    $GLOBALS['rj_scripts'] = [];
    config([
        'ai.work_dir' => $this->root, 'ai.codex.home' => $this->root.'-codex', 'ai.codex.model' => null,
        'ai.timeouts.abort_check_seconds' => 0.2,
        'ai.copilot.home' => $this->root.'-copilot', 'ai.copilot.cache' => $this->root.'-cache', 'ai.copilot.model' => null,
    ]);
    F::enableCodex();
});

afterEach(function () {
    foreach ($GLOBALS['rj_scripts'] as $f) {
        @unlink($f);
    }
    $GLOBALS['rj_scripts'] = [];
    if (is_dir($this->root)) {
        WorkDir::removeTree($this->root);
    }
});

it('turns a valid reply into a ready draft and clears the page text', function () {
    $s = rjSetup();
    rjBin('codex', FakeCodex::succeeding(F::recipeReply(['title' => '<b>Pancakes</b>', 'steps' => ['Mix <i>well</i>.', 'Fry.']])));

    rjRun($s['import']);

    $row = rjRow($s['import']);
    $payload = json_decode($row->payload, true);
    expect($row->status)->toBe('ready')->and($row->source_text)->toBeNull()->and($row->agent)->toBe('codex')
        ->and($row->model)->toBe('default')->and($row->finished_at)->not->toBeNull()->and($row->failure_code)->toBeNull()
        ->and($payload['title'])->toBe('Pancakes')->and($payload['steps'][0])->toBe('Mix well.')
        ->and($payload['ingredients'][0]['name'])->toBe('Flour');
    $calls = rjCalls($s['import']);
    expect($calls)->toHaveCount(1)->and($calls[0]->status)->toBe('succeeded')->and($calls[0]->kind)->toBe('recipe')
        ->and($calls[0]->agent)->toBe('codex')->and($calls[0]->model)->toBe('default')->and($calls[0]->duration_ms)->not->toBeNull()
        ->and($calls[0]->subject_id)->toBeNull();
    expect(AiAgentStatus::find('codex')->last_success_at)->not->toBeNull()
        ->and(glob($this->root.'/*'))->toBe([])
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('sends the page text between nonce markers, with no image and no url', function () {
    $s = rjSetup();
    $record = sys_get_temp_dir().'/rj-rec-'.bin2hex(random_bytes(4));
    $GLOBALS['rj_scripts'][] = $record;
    rjBin('codex', FakeCodex::recording($record, F::recipeReply()));

    rjRun($s['import']);

    $log = (string) file_get_contents($record);
    [$args] = explode('---ENV---', $log, 2);
    preg_match('/<<<PAGE ([0-9a-f]{16})>>>/', $log, $m);
    expect(rjRow($s['import'])->status)->toBe('ready')
        ->and($m)->not->toBeEmpty()
        ->and($log)->toContain('<<<END '.$m[1].'>>>')
        ->and($log)->toContain(RJ_CANARY)
        ->and(preg_match('/^(-i|--image|--image=.*)$/m', $args))->toBe(0)
        ->and($log)->not->toContain('recipes.example');
});

it('fails with no_recipe, invalid_output or a missing file and never reaches ready', function (string $kind, string $code) {
    $s = rjSetup();
    rjBin('codex', match ($kind) {
        'not a recipe' => FakeCodex::succeeding(F::recipeReply(['is_recipe' => false, 'title' => null, 'ingredients' => [], 'steps' => []])),
        'invalid json' => FakeCodex::invalidJson(),
        'exit 0 without file' => FakeCodex::exitZeroNoFile(),
    });

    rjRun($s['import']);

    $row = rjRow($s['import']);
    expect($row->status)->toBe('failed')->and($row->failure_code)->toBe($code)->and($row->payload)->toBeNull()->and($row->source_text)->toBeNull()
        ->and(rjCalls($s['import'])[0]->status)->toBe('failed')->and(rjCalls($s['import'])[0]->failure_code)->toBe($code);
})->with([
    ['not a recipe', 'no_recipe'],
    ['invalid json', 'invalid_output'],
    ['exit 0 without file', 'no_output'],
]);

it('falls back to Copilot on a Codex quota hit and cools Codex down', function () {
    $s = rjSetup('codex,copilot');
    F::enableCopilot();
    rjBin('codex', FakeCodex::quota('You have hit your usage limit. Try again later.'));
    rjBin('copilot', FakeCopilot::fenced(F::recipeReply()));

    rjRun($s['import']);

    $row = rjRow($s['import']);
    expect($row->status)->toBe('ready')->and($row->agent)->toBe('copilot')
        ->and((new AgentHealth)->coolingDown('codex'))->toBeTrue()
        ->and(array_map(fn ($c) => [$c->agent, $c->status, $c->failure_code], rjCalls($s['import'])))
        ->toBe([['codex', 'failed', 'quota_exhausted'], ['copilot', 'succeeded', null]]);
});

it('fails at once when every agent is cooling down and never parks', function () {
    $s = rjSetup();
    (new AgentHealth)->recordQuotaHit('codex', CarbonImmutable::now()->addHours(2), null);
    $record = sys_get_temp_dir().'/rj-rec-'.bin2hex(random_bytes(4));
    $GLOBALS['rj_scripts'][] = $record;
    rjBin('codex', FakeCodex::recording($record, F::recipeReply()));

    rjRun($s['import']);

    $row = rjRow($s['import']);
    expect($row->status)->toBe('failed')->and($row->failure_code)->toBe('quota_exhausted')
        ->and(file_exists($record))->toBeFalse()
        ->and(DB::table('recipe_imports')->where('status', 'waiting')->exists())->toBeFalse();
});

it('fails with quota_exhausted when the only agent hits its limit during the run', function () {
    $s = rjSetup();
    rjBin('codex', FakeCodex::quota('You have hit your usage limit. Try again later.'));

    rjRun($s['import']);

    expect(rjRow($s['import']))->status->toBe('failed')->failure_code->toBe('quota_exhausted')
        ->and(rjRow($s['import'])->finished_at)->not->toBeNull();
});

it('fails with no_agent when no agent is enabled', function () {
    $s = rjSetup();
    AiAgentSetting::query()->toBase()->where('agent', 'codex')->update(['enabled' => false]);

    rjRun($s['import']);

    expect(rjRow($s['import']))->status->toBe('failed')->failure_code->toBe('no_agent');
});

it('refuses to send the page to a provider the recipe notice does not cover', function (?string $recipeAck, ?string $medicalAck) {
    $s = rjSetup($recipeAck);
    if ($medicalAck !== null) {
        DB::table('ai_notice_acks')->insert(['user_id' => $s['user']->id, 'providers' => $medicalAck]);
    }
    $record = sys_get_temp_dir().'/rj-rec-'.bin2hex(random_bytes(4));
    $GLOBALS['rj_scripts'][] = $record;
    rjBin('codex', FakeCodex::recording($record, F::recipeReply()));

    rjRun($s['import']);

    expect(rjRow($s['import']))->status->toBe('failed')->failure_code->toBe('notice_required')
        ->and(file_exists($record))->toBeFalse();
})->with([
    'no acknowledgment' => [null, null],
    'medical acknowledgment only' => [null, 'codex'],
    'recipe notice for another provider' => ['copilot', null],
]);

it('stops at the first provider the recipe notice does not cover after a quota hit', function () {
    $s = rjSetup('codex');
    F::enableCopilot();
    $record = sys_get_temp_dir().'/rj-rec-'.bin2hex(random_bytes(4));
    $GLOBALS['rj_scripts'][] = $record;
    rjBin('codex', FakeCodex::quota('You have hit your usage limit. Try again later.'));
    rjBin('copilot', FakeCopilot::recording($record, F::recipeReply()));

    rjRun($s['import']);

    expect(rjRow($s['import']))->status->toBe('failed')->failure_code->toBe('notice_required')
        ->and(file_exists($record))->toBeFalse()
        ->and(array_map(fn ($c) => $c->agent, rjCalls($s['import'])))->toBe(['codex']);
});

it('cancels before spawning when the member lost Recipes edit, cannot sign in or is gone', function (string $case) {
    $s = rjSetup();
    $record = sys_get_temp_dir().'/rj-rec-'.bin2hex(random_bytes(4));
    $GLOBALS['rj_scripts'][] = $record;
    rjBin('codex', FakeCodex::recording($record, F::recipeReply()));

    match ($case) {
        'module' => $s['user']->modulePermissions()->delete(),
        'view only' => $s['user']->modulePermissions()->update(['level' => AccessLevel::View]),
        'deleted' => $s['user']->delete(),
    };

    rjRun($s['import']);

    $row = rjRow($s['import']);
    expect($row->status)->toBe('cancelled')->and($row->cancel_reason)->toBe('access')->and($row->source_text)->toBeNull()
        ->and($row->payload)->toBeNull()->and(file_exists($record))->toBeFalse()
        ->and(rjCalls($s['import'])[0]->status)->toBe('cancelled');
})->with(['module', 'view only', 'deleted']);

it('kills a hanging CLI when a cancel is requested while it runs', function () {
    $s = rjSetup();
    $flag = sys_get_temp_dir().'/rj-flag-'.bin2hex(random_bytes(4));
    $GLOBALS['rj_scripts'][] = $flag;
    app()->instance(RecipeImportQuery::class, new class($flag) extends RecipeImportQuery
    {
        public function __construct(private string $flag) {}

        public function abortReason(string $id): ?string
        {
            return file_exists($this->flag) ? 'user' : parent::abortReason($id);
        }
    });
    rjBin('codex', rjScript('cat >/dev/null; touch '.escapeshellarg($flag).'; while :; do sleep 1; done'));

    $start = microtime(true);
    rjRun($s['import']);

    $row = rjRow($s['import']);
    expect(microtime(true) - $start)->toBeLessThan(20)
        ->and($row->status)->toBe('cancelled')->and($row->cancel_reason)->toBe('user')->and($row->source_text)->toBeNull()
        ->and(glob($this->root.'/*'))->toBe([]);
});

it('discards a result that arrives after the member lost Recipes edit', function () {
    $s = rjSetup();
    $flag = sys_get_temp_dir().'/rj-flag-'.bin2hex(random_bytes(4));
    $GLOBALS['rj_scripts'][] = $flag;
    app()->instance(AccessGate::class, new class($flag) extends AccessGate
    {
        public function __construct(private string $flag) {}

        public function allows(?User $user, Module $module, AccessLevel $required): bool
        {
            return ! file_exists($this->flag) && parent::allows($user, $module, $required);
        }
    });
    rjBin('codex', rjScript('cat >/dev/null; touch '.escapeshellarg($flag).'; printf %s '.escapeshellarg(F::recipeReply()).' > "$out"'));

    rjRun($s['import']);

    $row = rjRow($s['import']);
    expect($row->status)->toBe('cancelled')->and($row->cancel_reason)->toBe('access')->and($row->payload)->toBeNull();
});

it('claims once: a redelivered job spawns nothing', function () {
    $s = rjSetup();
    $record = sys_get_temp_dir().'/rj-rec-'.bin2hex(random_bytes(4));
    $GLOBALS['rj_scripts'][] = $record;
    rjBin('codex', FakeCodex::recording($record, F::recipeReply()));

    rjRun($s['import']);
    unlink($record);
    rjRun($s['import']);

    expect(file_exists($record))->toBeFalse()->and(rjRow($s['import'])->status)->toBe('ready');
});

it('closes a running row as worker_lost when the queue gives up, and leaves other rows alone', function () {
    $running = RecipeImport::factory()->running()->create();
    DB::table('ai_calls')->insert([
        'extraction_id' => $running->id, 'kind' => 'recipe', 'started_by' => 1, 'agent' => 'codex', 'attempt' => 1, 'status' => 'running', 'queued_at' => now(), 'started_at' => now(),
    ]);
    (new RunRecipeImportJob($running->id))->failed(new RuntimeException('secret '.RJ_CANARY));

    $row = rjRow($running);
    expect($row->status)->toBe('failed')->and($row->failure_code)->toBe('worker_lost')->and($row->source_text)->toBeNull()
        ->and(rjCalls($running)[0]->failure_code)->toBe('worker_lost');

    $ready = RecipeImport::factory()->ready()->create();
    (new RunRecipeImportJob($ready->id))->failed(new RuntimeException('x'));
    expect(rjRow($ready)->status)->toBe('ready');
});

it('turns an unexpected error into worker_lost without leaking its message', function () {
    $s = rjSetup();
    rjBin('codex', FakeCodex::succeeding(F::recipeReply()));
    app()->instance(RecipeImportQuery::class, new class extends RecipeImportQuery
    {
        public function beginAttempt(string $id, string $agent): int
        {
            throw new RuntimeException('boom '.RJ_CANARY);
        }
    });

    try {
        rjRun($s['import']);
        $thrown = null;
    } catch (RuntimeException $e) {
        $thrown = $e->getMessage();
    }

    expect($thrown)->toBe('Recipe import job failed.')->and($thrown)->not->toContain(RJ_CANARY)
        ->and(rjRow($s['import']))->status->toBe('failed')->failure_code->toBe('worker_lost');
});

it('puts only the import id on the ai queue', function () {
    $job = new RunRecipeImportJob('0c7a3f5e-0000-4000-8000-000000000000');

    $serialized = serialize($job);
    expect($job->connection)->toBe('database')->and($job->queue)->toBe(config('ai.queue'))
        ->and($job->timeout)->toBe((int) config('ai.timeouts.job'))
        ->and($serialized)->toContain('0c7a3f5e-0000-4000-8000-000000000000')
        ->and($serialized)->not->toContain('http')
        ->and(array_keys(get_object_vars($job)))->toContain('importId');
});
