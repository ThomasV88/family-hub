<?php

namespace App\Medical\Ai\Agents;

use App\Medical\Ai\CodexRequest;
use App\Medical\Ai\CodexRunner;
use App\Medical\Ai\ExtractionSchema;
use App\Medical\Ai\ExtractionValidator;
use App\Medical\Ai\FailureCode;
use App\Medical\Ai\LoginState;
use App\Medical\Ai\PromptBuilder;
use Closure;

/**
 * OpenAI Codex behind the Agent interface. Success is decided only by the validator on the -o file; the exit code is
 * never trusted (a valid file with exit 1 counts if the validator accepts it).
 */
final class CodexAgent implements Agent
{
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

        $result = (new CodexRunner)->exec(
            new CodexRequest($dir->path(), $schemaPath, $resultPath, $images, (new PromptBuilder)->build(), $this->configuredModel()),
            $shouldAbort,
        );
        $model = $result->reportedModel ?? $this->modelLabel();

        $fail = fn (FailureCode $code, bool $aborted = false) => new AgentOutcome(false, null, null, $code, $aborted, $result->durationMs, $model);

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
        return (new CodexRunner)->loginStatus();
    }

    private function configuredModel(): ?string
    {
        $m = config('ai.codex.model');

        return is_string($m) && trim($m) !== '' ? trim($m) : null;
    }
}
