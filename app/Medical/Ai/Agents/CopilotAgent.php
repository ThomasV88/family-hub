<?php

namespace App\Medical\Ai\Agents;

use App\Medical\Ai\CopilotRequest;
use App\Medical\Ai\CopilotRunner;
use App\Medical\Ai\ExtractionValidator;
use App\Medical\Ai\FailureCode;
use App\Medical\Ai\JsonReply;
use App\Medical\Ai\LoginState;
use App\Medical\Ai\ModelId;
use App\Medical\Ai\ProcessResult;
use App\Medical\Ai\PromptBuilder;
use Closure;
use Throwable;

/**
 * GitHub Copilot behind the Agent interface. The CLI cannot enforce a schema, so a reply counts only when JsonReply and
 * ExtractionValidator accept it (the exit code never decides). One invalid reply gets at most one fresh repair call that
 * carries structural issue codes only: nothing the model produced is ever sent back.
 */
final class CopilotAgent implements Agent
{
    private readonly CopilotRunner $runner;

    private readonly ?string $model;

    public function __construct(?CopilotRunner $runner = null, ?string $model = null)
    {
        $this->runner = $runner ?? new CopilotRunner;
        $model = $model !== null ? trim($model) : '';
        $this->model = $model !== '' && ModelId::isValid($model) ? $model : $this->configuredModel();
    }

    public function key(): string
    {
        return 'copilot';
    }

    public function modelLabel(): string
    {
        return $this->model ?? 'default';
    }

    public function loginStatus(): LoginState
    {
        return $this->runner->loginStatus();
    }

    public function extract(AgentInput $in, ?Closure $shouldAbort = null): AgentOutcome
    {
        $dir = $in->workDir;
        $reply = $dir->file('reply.txt');
        $usage = $dir->file('usage.json');
        $images = array_map(fn (string $name) => $dir->file($name), $in->images);
        $builder = new PromptBuilder;

        $duration = 0;
        $first = $this->call($in, $builder->buildInline(), $images, $usage, $shouldAbort, $duration);
        $step = $first === null ? $this->timeout($duration) : $this->map($first, $reply, $duration);

        if (is_array($step) && ! $first?->aborted && $this->timeLeftForRepair($in)) {
            @unlink($reply);
            $second = $this->call($in, $builder->buildInline($step), $images, $usage, $shouldAbort, $duration);
            $step = $second === null ? $this->timeout($duration) : $this->map($second, $reply, $duration);
            if (is_array($step)) {
                $step = $this->failed(FailureCode::InvalidOutput, $duration);
            }
        } elseif (is_array($step)) {
            $step = $this->failed(FailureCode::InvalidOutput, $duration);
        }

        return $this->withModel($step, $usage);
    }

    /**
     * One spawn. Null when the deadline leaves less than the minimum call time (nothing is spawned then).
     *
     * @param  list<string>  $images
     */
    private function call(AgentInput $in, string $prompt, array $images, string $usage, ?Closure $shouldAbort, int &$duration): ?ProcessResult
    {
        $timeout = (int) config('ai.timeouts.process');
        if ($in->deadline !== null) {
            $left = $in->deadline - time();
            if ($left < (int) config('ai.timeouts.min_call')) {
                return null;
            }
            $timeout = min($timeout, $left);
        }

        $result = $this->runner->exec(
            new CopilotRequest($in->workDir->path(), $prompt, $images, $this->model, $timeout, $usage),
            $shouldAbort,
        );
        $duration += $result->durationMs;

        return $result;
    }

    /**
     * Research Q2 order. Returns the final outcome (model filled in later) or a list of issue codes when the reply was
     * invalid and a repair may follow.
     *
     * @return AgentOutcome|list<string>
     */
    private function map(ProcessResult $r, string $reply, int $duration): AgentOutcome|array
    {
        if ($r->aborted) {
            return new AgentOutcome(false, null, null, null, true, $duration, 'default');
        }
        if ($r->timedOut) {
            return $this->failed(FailureCode::Timeout, $duration);
        }
        if ($r->idleTimedOut) {
            return $this->failed(FailureCode::IdleTimeout, $duration);
        }
        if ($r->outputCapped) {
            return $this->failed(FailureCode::OutputTooLarge, $duration);
        }

        $json = JsonReply::decode($reply);
        $issues = ['root:json_syntax'];
        if ($json !== null) {
            $inspected = (new ExtractionValidator)->inspect($json);
            if ($inspected['outcome']->ok) {
                return new AgentOutcome(true, $inspected['outcome']->payload, $inspected['outcome']->entryCount, null, false, $duration, 'default');
            }
            $issues = array_map(fn (string $c) => str_contains($c, ':') ? $c : 'root:'.$c, $inspected['issues']);
        }

        $failing = $r->exitCode !== null && $r->exitCode !== 0;
        if ($r->quotaSignature && $failing) {
            return new AgentOutcome(false, null, null, FailureCode::QuotaExhausted, false, $duration, 'default', $r->quotaResetAt, $r->quotaMinutes);
        }
        if ($r->authSignature) {
            return $this->failed(FailureCode::Auth, $duration);
        }
        $size = is_file($reply) ? filesize($reply) : 0;
        if (! $size && $failing) {
            return $this->failed(FailureCode::CliError, $duration);
        }
        if (! $size) {
            return $this->failed(FailureCode::NoOutput, $duration);
        }

        return $issues;
    }

    private function timeLeftForRepair(AgentInput $in): bool
    {
        return $in->deadline === null || $in->deadline - time() >= (int) config('ai.copilot.repair_min_seconds');
    }

    private function timeout(int $duration): AgentOutcome
    {
        return $this->failed(FailureCode::Timeout, $duration);
    }

    private function failed(FailureCode $code, int $duration): AgentOutcome
    {
        return new AgentOutcome(false, null, null, $code, false, $duration, 'default');
    }

    /**
     * @param  AgentOutcome|list<string>  $step
     */
    private function withModel(AgentOutcome|array $step, string $usage): AgentOutcome
    {
        if (is_array($step)) {
            $step = $this->failed(FailureCode::InvalidOutput, 0);
        }
        $model = $this->model ?? $this->usageModel($usage) ?? 'default';

        return new AgentOutcome(
            $step->ok, $step->payload, $step->entryCount, $step->failure, $step->aborted, $step->durationMs, $model,
            $step->quotaResetAt, $step->quotaMinutes,
        );
    }

    /** The first modelMetrics key of the usage file that is a valid model identifier. */
    private function usageModel(string $usage): ?string
    {
        if (! is_file($usage) || (int) filesize($usage) > 1_048_576) {
            return null;
        }
        try {
            $data = json_decode((string) file_get_contents($usage), true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        $metrics = is_array($data) ? ($data['modelMetrics'] ?? null) : null;
        foreach (is_array($metrics) ? array_keys($metrics) : [] as $key) {
            if (is_string($key) && ModelId::isValid($key)) {
                return $key;
            }
        }

        return null;
    }

    private function configuredModel(): ?string
    {
        $m = config('ai.copilot.model');
        $m = is_string($m) ? trim($m) : '';

        return $m !== '' && ModelId::isValid($m) ? $m : null;
    }
}
