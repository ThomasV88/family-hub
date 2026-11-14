<?php

namespace App\Medical\Ai;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Medical\Ai\Agents\AgentInput;
use App\Medical\Ai\Agents\AgentRegistry;
use App\Medical\Ai\Work\WorkDir;
use App\Medical\AiConsent;
use App\Medical\Documents\MedicalDocumentStore;
use App\Medical\Documents\PdfRasterizer;
use App\Medical\Queries\AiJobQuery;
use App\Medical\Queries\SystemQuery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Turns one queued extraction into a ready proposal or a content-free failure. The payload carries the extraction id
 * only. Everything that matters is re-verified when the job runs (starter, Health Edit, membership, consent, cancel),
 * and nothing that is read from the document, the prompt or the CLI output is ever logged or put into an exception.
 */
class RunExtractionJob implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $maxExceptions = 1;

    public $timeout = 660;

    public $failOnTimeout = true;

    /** Seconds between the access re-checks inside the abort loop. */
    private const ACCESS_RECHECK_SECONDS = 10;

    private ?string $abortWhy = null;

    public function __construct(public string $extractionId)
    {
        // Always the Postgres jobs table served only by the ai-worker, whatever the default connection is.
        $this->onConnection('database')->onQueue((string) config('ai.queue'));
        $this->timeout = (int) config('ai.timeouts.job');
    }

    public function handle(): void
    {
        $query = app(AiJobQuery::class);
        $claim = $query->claim($this->extractionId);
        if ($claim === null) {
            return;
        }

        $workDir = null;
        $status = null;
        try {
            $status = $this->run($query, $claim, $workDir);
        } catch (Throwable) {
            // Content-free on purpose: the original message may carry paths or document fragments.
            $this->safely(fn () => $query->finishFailed($this->extractionId, FailureCode::WorkerLost, $this->modelLabel(), 0));
            $this->audit($claim, 'failed');

            throw new RuntimeException('Extraction job failed.');
        } finally {
            $workDir?->cleanup();
            $this->safely(fn () => WorkDir::sweepStale());
        }

        // A parked run is not finished: no audit row until it ends.
        if ($status !== null && $status !== 'waiting') {
            $this->audit($claim, $status);
        }
    }

    /** Last resort of the queue (timeout, killed worker): closes the row if it is still running. */
    public function failed(Throwable $e): void
    {
        $this->safely(fn () => app(AiJobQuery::class)->finishFailed($this->extractionId, FailureCode::WorkerLost, $this->modelLabel(), 0));
    }

    /**
     * @param  array{subject_id: string, document_id: string, started_by: int, bulk_run_id: ?string}  $claim
     * @return 'succeeded'|'failed'|'cancelled'|'waiting'|null null when a finish call no longer applied (row closed elsewhere)
     */
    private function run(AiJobQuery $query, array $claim, ?WorkDir &$workDir): ?string
    {
        $id = $this->extractionId;
        $label = $this->modelLabel();
        $bulk = $claim['bulk_run_id'] !== null;

        $why = $this->recheck($query, $claim);
        if ($why !== null) {
            return $query->finishCancelled($id, $why, $label, 0) ? 'cancelled' : null;
        }

        if (! (new Preflight)->envKeysClean()) {
            return $query->finishFailed($id, FailureCode::Preflight, $label, 0) ? 'failed' : null;
        }

        $document = $query->document($id);
        if ($document === null) {
            return $query->finishFailed($id, FailureCode::UnreadableDocument, $label, 0) ? 'failed' : null;
        }

        $workDir = WorkDir::create();
        $store = app(MedicalDocumentStore::class);
        $images = [];
        $pagesRead = 1;
        $pagesTotal = 1;

        if ($document['kind'] === 'image') {
            if (! $store->copyTo($document['path'], $workDir->file('doc.jpg'))) {
                return $query->finishFailed($id, FailureCode::UnreadableDocument, $label, 0) ? 'failed' : null;
            }
            $images = ['doc.jpg'];
        } elseif ($document['kind'] === 'pdf') {
            if (! $store->copyTo($document['path'], $workDir->file('doc.pdf'))) {
                return $query->finishFailed($id, FailureCode::UnreadableDocument, $label, 0) ? 'failed' : null;
            }
            $pages = (new PdfRasterizer)->pages($workDir->file('doc.pdf'), $workDir->path(), (int) config('ai.pdf.max_pages'));
            // The original never stays next to the CLI: it only ever sees the page images.
            @unlink($workDir->file('doc.pdf'));
            if ($pages['total'] < 1 || $pages['written'] === []) {
                return $query->finishFailed($id, FailureCode::UnreadableDocument, $label, 0) ? 'failed' : null;
            }
            $images = array_map('basename', $pages['written']);
            $pagesRead = count($images);
            $pagesTotal = $pages['total'];
        } else {
            return $query->finishFailed($id, FailureCode::UnsupportedDocument, $label, 0) ? 'failed' : null;
        }

        $registry = app(AgentRegistry::class);
        $health = new AgentHealth;
        $tried = [];
        $lastCode = null;
        $deadline = time() + (int) config('ai.timeouts.job') - 30;

        while (true) {
            // Re-read before every attempt: an acknowledgment (or a settings change) made meanwhile counts.
            $allowed = $query->ackedProviders($claim['started_by']);
            $agent = $registry->nextCandidate($tried, $bulk, $allowed);
            if ($agent === null) {
                break;
            }

            // Consent, access and cancel are rechecked before each agent is started.
            $why = $this->recheck($query, $claim);
            if ($why !== null) {
                return $query->finishCancelled($id, $why, $agent->modelLabel(), 0) ? 'cancelled' : null;
            }
            if ($deadline - time() < (int) config('ai.timeouts.min_call')) {
                return $query->finishFailed($id, FailureCode::Timeout, $agent->modelLabel(), 0) ? 'failed' : null;
            }

            $query->beginAttempt($id, $agent->key(), $claim['bulk_run_id']);
            $this->clearAttemptFiles($workDir, $images);
            $lastAccessCheck = microtime(true);
            $this->abortWhy = null;
            $outcome = $agent->extract(new AgentInput($workDir, $images, $deadline), function () use ($query, $claim, $id, &$lastAccessCheck): bool {
                $why = $query->abortReason($id);
                if ($why === null && microtime(true) - $lastAccessCheck >= self::ACCESS_RECHECK_SECONDS) {
                    $lastAccessCheck = microtime(true);
                    $why = $this->accessProblem($claim);
                }
                $this->abortWhy = $why;

                return $why !== null;
            });

            // The world may have changed while the CLI ran: a late result is discarded, never persisted.
            $why = $this->recheck($query, $claim);
            if ($why !== null) {
                return $query->finishCancelled($id, $why, $outcome->model, $outcome->durationMs) ? 'cancelled' : null;
            }
            if ($outcome->aborted) {
                return $query->finishCancelled($id, $this->abortWhy ?? 'user', $outcome->model, $outcome->durationMs) ? 'cancelled' : null;
            }

            if ($outcome->ok && $outcome->payload !== null) {
                $done = $query->finishReady($id, $outcome->payload, (int) $outcome->entryCount, $pagesRead, $pagesTotal, $agent->key(), $outcome->model, $outcome->durationMs);
                if ($done) {
                    $this->safely(fn () => $health->recordSuccess($agent->key()));

                    return 'succeeded';
                }
                // Closed by the bulk never-supersede-accepted guard.
                if ($query->finishedAsDecided($id)) {
                    return 'cancelled';
                }

                // A cancel or a consent withdrawal landed after the last recheck: finish as cancelled, supersede nothing.
                // finishCancelled is a no-op (null) when the row was closed elsewhere.
                $why = $query->abortReason($id) ?? 'user';

                return $query->finishCancelled($id, $why, $outcome->model, $outcome->durationMs) ? 'cancelled' : null;
            }

            $code = $outcome->failure ?? FailureCode::NoOutput;
            if ($code === FailureCode::QuotaExhausted) {
                $this->safely(fn () => $health->recordQuotaHit($agent->key(), $outcome->quotaResetAt, $outcome->quotaMinutes));
            } elseif ($code === FailureCode::Auth) {
                $this->safely(fn () => $health->recordAuthFailure($agent->key()));
            } else {
                // Every other failure is terminal: no loop.
                return $query->finishFailed($id, $code, $outcome->model, $outcome->durationMs) ? 'failed' : null;
            }
            $query->closeAttempt($id, 'failed', $code, $outcome->model, $outcome->durationMs);
            $tried[] = $agent->key();
            $lastCode = $code;
        }

        return $this->noCandidate($query, $registry, $claim, $bulk, $allowed, $lastCode, $label);
    }

    /**
     * No agent can start now: refuse (no acknowledged provider), fail (nothing will ever become usable, or the wait
     * would pass the cap) or park the run in the database until the earliest reset.
     *
     * @param  array{subject_id: string, document_id: string, started_by: int, bulk_run_id: ?string}  $claim
     * @param  list<string>  $allowed
     * @return 'failed'|'cancelled'|'waiting'|null
     */
    private function noCandidate(AiJobQuery $query, AgentRegistry $registry, array $claim, bool $bulk, array $allowed, ?FailureCode $lastCode, string $label): ?string
    {
        $id = $this->extractionId;

        $enabled = $registry->providers();
        if ($bulk && ! config('ai.bulk.fallback_agents')) {
            $enabled = array_slice($enabled, 0, 1);
        }
        if ($enabled !== [] && array_intersect($enabled, $allowed) === []) {
            // The document is never sent to a provider the starter has not acknowledged.
            return $query->finishFailed($id, FailureCode::NoticeRequired, $label, 0) ? 'failed' : null;
        }

        $resume = $registry->earliestResume($bulk, $allowed);
        if ($resume === null) {
            return $query->finishFailed($id, $lastCode ?? FailureCode::NoAgent, $label, 0) ? 'failed' : null;
        }

        $since = $query->waitingSince($id) ?? CarbonImmutable::now();
        if ($resume->gt($since->addHours((int) config('ai.quota.wait_cap_hours', 24)))) {
            return $query->finishFailed($id, FailureCode::QuotaExhausted, $label, 0) ? 'failed' : null;
        }

        if ($query->parkWaiting($id, $resume)) {
            return 'waiting';
        }

        // Not parked because a cancel was requested (or the row was closed elsewhere).
        return $query->finishCancelled($id, 'user', $label, 0) ? 'cancelled' : null;
    }

    /** Whatever an earlier attempt left in the work dir (result or schema files) is gone before the next agent starts. @param list<string> $images */
    private function clearAttemptFiles(WorkDir $workDir, array $images): void
    {
        foreach (glob($workDir->path().'/*') ?: [] as $path) {
            if (is_file($path) && ! in_array(basename($path), $images, true)) {
                @unlink($path);
            }
        }
    }

    /** @param  array{subject_id: string, document_id: string, started_by: int, bulk_run_id: ?string}  $claim */
    private function recheck(AiJobQuery $query, array $claim): ?string
    {
        return $this->accessProblem($claim) ?? $query->abortReason($this->extractionId);
    }

    /**
     * 'access' when the starter is gone, lost Health Edit or lost membership on the person, 'consent' when the person's
     * consent is off, otherwise null. Fail closed: any error counts as lost access.
     *
     * Bulk rows (PROVISIONAL owner decision A10, confirmed in 09-20): the starting admin must still exist and be an
     * admin, the bulk run must not be cancelled ('user'), and the person's consent must be on; the Health Edit and
     * membership checks are skipped.
     *
     * @param  array{subject_id: string, document_id: string, started_by: int, bulk_run_id: ?string}  $claim
     */
    private function accessProblem(array $claim): ?string
    {
        try {
            $user = User::query()->find($claim['started_by']);
            if ($user === null) {
                return 'access';
            }
            if ($claim['bulk_run_id'] !== null) {
                if (! $user->is_admin) {
                    return 'access';
                }
                if (app(AiJobQuery::class)->bulkRunCancelled($claim['bulk_run_id'])) {
                    return 'user';
                }

                return AiConsent::allows($claim['subject_id']) ? null : 'consent';
            }
            $gate = app(AccessGate::class);
            // Long-lived process: never trust a cached grant.
            $gate->forget($user);
            if (! $gate->allows($user, Module::Health, AccessLevel::Edit)
                || ! app(SystemQuery::class)->isEditor($claim['subject_id'], $user->id)) {
                return 'access';
            }

            return AiConsent::allows($claim['subject_id']) ? null : 'consent';
        } catch (Throwable) {
            return 'access';
        }
    }

    /** @param  array{subject_id: string, document_id: string, started_by: int}  $claim */
    private function audit(array $claim, string $status): void
    {
        $this->safely(function () use ($claim, $status) {
            $user = User::query()->find($claim['started_by']);
            app(AuditLogger::class)->record(
                AuditEvent::MedicalAiRunFinished,
                $user,
                ['subject' => $claim['subject_id'], 'document' => $claim['document_id'], 'status' => $status],
                null,
                Module::Health,
            );
        });
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
