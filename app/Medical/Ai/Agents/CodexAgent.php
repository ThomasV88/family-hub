<?php

namespace App\Medical\Ai\Agents;

use App\Medical\Ai\CodexRequest;
use App\Medical\Ai\CodexRunner;
use App\Medical\Ai\ExtractionSchema;
use App\Medical\Ai\ExtractionValidator;
use App\Medical\Ai\FailureCode;
use App\Medical\Ai\LoginState;
use App\Medical\Ai\ModelId;
use App\Medical\Ai\PromptBuilder;
use Closure;

/**
 * OpenAI Codex behind the Agent interface. Success is decided only by the validator on the -o file; the exit code is
 * never trusted (a valid file with exit 1 counts if the validator accepts it).
 */
final class CodexAgent implements Agent
{
    private ?string $model;

    public function __construct(private ?CodexRunner $runner = null, ?string $model = null)
    {
        $model = is_string($model) ? trim($model) : '';
        // An invalid override is ignored (never reaches argv); the configured model, then the CLI default, apply.
        $this->model = $model !== '' && ModelId::isValid($model) ? $model : null;
    }

    public function key(): string
    {
        return 'codex';
    }

    public function modelLabel(): string
    {
        return $this->configuredModel() ?? 'default';
    }

    public function extract(AgentInput $in, ?Closure $shouldAbort = null): AgentOutcome
    {
        $dir = $in->workDir;
        $schemaPath = $dir->put('schema.json', (string) file_get_contents(ExtractionSchema::path()));
        $resultPath = $dir->file('result.json');
        $images = array_map(fn (string $name) => $dir->file($name), $in->images);

        $timeout = null;
        if ($in->deadline !== null) {
            $timeout = min((float) config('ai.timeouts.process'), (float) ($in->deadline - time() - 5));
            if ($timeout < (float) config('ai.timeouts.min_call')) {
                return new AgentOutcome(false, null, null, FailureCode::Timeout, false, 0, $this->modelLabel());
            }
        }

        $result = ($this->runner ?? new CodexRunner)->exec(
            new CodexRequest($dir->path(), $schemaPath, $resultPath, $images, (new PromptBuilder)->build(), $this->configuredModel()),
            $shouldAbort,
            $timeout,
        );
        // With a configured model the recorded name is that one: text the CLI echoes can never choose the label.
        $model = $this->configuredModel() ?? $result->reportedModel ?? $this->modelLabel();

        $fail = fn (FailureCode $code) => new AgentOutcome(false, null, null, $code, false, $result->durationMs, $model);

        if ($result->aborted) {
            return new AgentOutcome(false, null, null, null, true, $result->durationMs, $model);
        }
        if ($result->timedOut) {
            return $fail(FailureCode::Timeout);
        }
        if ($result->idleTimedOut) {
            return $fail(FailureCode::IdleTimeout);
        }
        if ($result->outputCapped) {
            return $fail(FailureCode::OutputTooLarge);
        }

        $outcome = (new ExtractionValidator)->fromFile($resultPath);
        if ($outcome->ok) {
            return new AgentOutcome(true, $outcome->payload, $outcome->entryCount, null, false, $result->durationMs, $model);
        }
        if ($result->quotaSignature && $result->exitCode !== null && $result->exitCode !== 0) {
            return new AgentOutcome(false, null, null, FailureCode::QuotaExhausted, false, $result->durationMs, $model, $result->quotaResetAt, $result->quotaMinutes);
        }
        if ($result->authSignature) {
            return $fail(FailureCode::Auth);
        }
        if (! is_file($resultPath) && $result->exitCode !== null && $result->exitCode !== 0) {
            return $fail(FailureCode::CliError);
        }

        return $fail($outcome->failure ?? FailureCode::InvalidOutput);
    }

    public function loginStatus(): LoginState
    {
        return ($this->runner ?? new CodexRunner)->loginStatus();
    }

    private function configuredModel(): ?string
    {
        if ($this->model !== null) {
            return $this->model;
        }
        $m = config('ai.codex.model');

        $m = is_string($m) ? trim($m) : '';

        // An invalid value (leading dash, an equals sign, spaces) never reaches the CLI argv; ai:preflight reports it.
        return $m !== '' && ModelId::isValid($m) ? $m : null;
    }
}
