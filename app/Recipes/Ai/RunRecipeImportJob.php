<?php

namespace App\Recipes\Ai;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Medical\Ai\AgentHealth;
use App\Medical\Ai\Agents\AgentInput;
use App\Medical\Ai\Agents\AgentRegistry;
use App\Medical\Ai\FailureCode;
use App\Medical\Ai\Preflight;
use App\Medical\Ai\Work\WorkDir;
use App\Models\User;
use App\Recipes\Import\RecipeImportQuery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Turns one queued recipe import (reduced page text already stored by FetchRecipePage) into a validated draft or a
 * content-free failure code, in the ai-worker. The payload carries the import id only and the agents never see an
 * address, only the text between nonce markers. Unlike a medical run it never waits for quota: with no agent left it
 * fails at once and the member tries again later. Nothing read from the page, the prompt or the CLI is logged or put
 * into an exception.
 */
class RunRecipeImportJob implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $maxExceptions = 1;

    public $timeout = 660;

    public $failOnTimeout = true;

    /** Seconds between the access re-checks inside the abort loop. */
    private const ACCESS_RECHECK_SECONDS = 10;

    private ?string $abortWhy = null;

    public function __construct(public string $importId)
    {
        // Always the Postgres jobs table served only by the ai-worker, whatever the default connection is.
        $this->onConnection('database')->onQueue((string) config('ai.queue'));
        $this->timeout = (int) config('ai.timeouts.job');
    }

    public function handle(): void
    {
        $query = app(RecipeImportQuery::class);
        $claim = $query->claim($this->importId);
        if ($claim === null) {
            return;
        }

        $workDir = null;
        try {
            $this->run($query, $claim, $workDir);
        } catch (Throwable) {
            // Content-free on purpose: the original message may carry paths or page fragments.
            $this->safely(fn () => $query->finishFailed($this->importId, FailureCode::WorkerLost, $this->modelLabel(), 0));

            throw new RuntimeException('Recipe import job failed.');
        } finally {
            $workDir?->cleanup();
            $this->safely(fn () => WorkDir::sweepStale());
        }
    }

    /** Last resort of the queue (timeout, killed worker): closes the row if it is still running. */
    public function failed(Throwable $e): void
    {
        $this->safely(fn () => app(RecipeImportQuery::class)->finishFailed($this->importId, FailureCode::WorkerLost, $this->modelLabel(), 0));
    }

    /** @param  array{user_id: int, source_text: string, source_kind: string}  $claim */
    private function run(RecipeImportQuery $query, array $claim, ?WorkDir &$workDir): void
    {
        $id = $this->importId;
        $label = $this->modelLabel();

        $why = $this->recheck($query, $claim['user_id']);
        if ($why !== null) {
            $query->finishCancelled($id, $why, $label, 0);

            return;
        }

        if (! (new Preflight)->envKeysClean()) {
            $query->finishFailed($id, FailureCode::Preflight, $label, 0);

            return;
        }

        $workDir = WorkDir::create();
        $registry = app(AgentRegistry::class);
        $health = new AgentHealth;
        $tried = [];
        $lastCode = null;
        $deadline = time() + (int) config('ai.timeouts.job') - 30;

        while (true) {
            // Re-read before every attempt: an acknowledgment (or a settings change) made meanwhile counts.
            $allowed = $this->allowedProviders($claim['user_id']);
            $agent = $registry->nextCandidate($tried, false, $allowed);
            if ($agent === null) {
                break;
            }

            $why = $this->recheck($query, $claim['user_id']);
            if ($why !== null) {
                $query->finishCancelled($id, $why, $agent->modelLabel(), 0);

                return;
            }
            if ($deadline - time() < (int) config('ai.timeouts.min_call')) {
                $query->finishFailed($id, FailureCode::Timeout, $agent->modelLabel(), 0);

                return;
            }

            $query->beginAttempt($id, $agent->key());
            $this->clearAttemptFiles($workDir);
            $lastAccessCheck = microtime(true);
            $this->abortWhy = null;
            $task = new RecipeTask($claim['source_text'], 'url');
            $outcome = $agent->extract(new AgentInput($workDir, [], $deadline, $task), function () use ($query, $claim, $id, &$lastAccessCheck): bool {
                $why = $query->abortReason($id);
                if ($why === null && microtime(true) - $lastAccessCheck >= self::ACCESS_RECHECK_SECONDS) {
                    $lastAccessCheck = microtime(true);
                    $why = $this->accessProblem($claim['user_id']);
                }
                $this->abortWhy = $why;

                return $why !== null;
            });

            // The world may have changed while the CLI ran: a late result is discarded, never persisted.
            $why = $this->recheck($query, $claim['user_id']);
            if ($why !== null) {
                $query->finishCancelled($id, $why, $outcome->model, $outcome->durationMs);

                return;
            }
            if ($outcome->aborted) {
                $query->finishCancelled($id, $this->abortWhy ?? 'user', $outcome->model, $outcome->durationMs);

                return;
            }

            if ($outcome->ok && $outcome->payload !== null) {
                if ($query->finishReady($id, $outcome->payload, $agent->key(), $outcome->model, $outcome->durationMs)) {
                    $this->safely(fn () => $health->recordSuccess($agent->key()));

                    return;
                }
                // A cancel landed after the last recheck: finishReady is a no-op on a closed row.
                $query->finishCancelled($id, $query->abortReason($id) ?? 'user', $outcome->model, $outcome->durationMs);

                return;
            }

            $code = $outcome->failure ?? FailureCode::NoOutput;
            if ($code === FailureCode::QuotaExhausted) {
                $this->safely(fn () => $health->recordQuotaHit($agent->key(), $outcome->quotaResetAt, $outcome->quotaMinutes));
            } elseif ($code === FailureCode::Auth) {
                $this->safely(fn () => $health->recordAuthFailure($agent->key()));
            } else {
                // no_recipe and every other failure is terminal: no loop, no second agent.
                $query->finishFailed($id, $code, $outcome->model, $outcome->durationMs);

                return;
            }
            $query->closeAttempt($id, 'failed', $code, $outcome->model, $outcome->durationMs);
            $tried[] = $agent->key();
            $lastCode = $code;
        }

        $this->noCandidate($query, $registry, $this->allowedProviders($claim['user_id']), $lastCode, $label);
    }

    /**
     * No agent can start now. Never parked: the answer is immediate (a recipe is something a member is waiting for).
     *
     * @param  list<string>  $allowed
     */
    private function noCandidate(RecipeImportQuery $query, AgentRegistry $registry, array $allowed, ?FailureCode $lastCode, string $label): void
    {
        $id = $this->importId;
        $enabled = $registry->providers();

        if ($enabled !== [] && array_diff($enabled, $allowed) !== []) {
            // The page text is never sent to a provider the member has not acknowledged for recipes.
            $query->finishFailed($id, FailureCode::NoticeRequired, $label, 0);

            return;
        }
        if ($lastCode === FailureCode::QuotaExhausted || $registry->earliestResume(false, $allowed) !== null) {
            $query->finishFailed($id, FailureCode::QuotaExhausted, $label, 0);

            return;
        }
        $query->finishFailed($id, $lastCode === FailureCode::Auth ? FailureCode::Auth : FailureCode::NoAgent, $label, 0);
    }

    /** @return list<string> provider keys the member acknowledged in the recipe notice */
    private function allowedProviders(int $userId): array
    {
        return app(RecipeImportQuery::class)->ackedRecipeProviders($userId);
    }

    /**
     * Whatever an earlier attempt left in the work dir is gone before the next agent starts: result and schema files,
     * the previous agent's per-run home (with its login copy) and its temp dir contents.
     */
    private function clearAttemptFiles(WorkDir $workDir): void
    {
        foreach (glob($workDir->path().'/*') ?: [] as $path) {
            if (basename($path) === 'tmp' && is_dir($path) && ! is_link($path)) {
                foreach (scandir($path) ?: [] as $entry) {
                    if ($entry !== '.' && $entry !== '..') {
                        WorkDir::removeTree($path.'/'.$entry);
                    }
                }

                continue;
            }
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                WorkDir::removeTree($path);
            }
        }
    }

    private function recheck(RecipeImportQuery $query, int $userId): ?string
    {
        return $this->accessProblem($userId) ?? $query->abortReason($this->importId);
    }

    /** 'access' when the starter is gone, cannot sign in or lost Recipes edit; fail closed on any error. */
    private function accessProblem(int $userId): ?string
    {
        try {
            $user = User::query()->find($userId);
            if ($user === null || ! $user->canSignIn()) {
                return 'access';
            }
            $gate = app(AccessGate::class);
            // Long-lived process: never trust a cached grant.
            $gate->forget($user);

            return $gate->allows($user, Module::Recipes, AccessLevel::Edit) ? null : 'access';
        } catch (Throwable) {
            return 'access';
        }
    }

    private function modelLabel(): string
    {
        try {
            return app(AgentRegistry::class)->primary()->modelLabel();
        } catch (Throwable) {
            return 'default';
        }
    }

    private function safely(callable $work): void
    {
        try {
            $work();
        } catch (Throwable) {
        }
    }
}
