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

    public $timeout = 330;

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

        if ($status !== null) {
            $this->audit($claim, $status);
        }
    }

    /** Last resort of the queue (timeout, killed worker): closes the row if it is still running. */
    public function failed(Throwable $e): void
    {
        $this->safely(fn () => app(AiJobQuery::class)->finishFailed($this->extractionId, FailureCode::WorkerLost, $this->modelLabel(), 0));
    }

    /**
     * @param  array{subject_id: string, document_id: string, started_by: int}  $claim
     * @return 'succeeded'|'failed'|'cancelled'|null null when a finish call no longer applied (row closed elsewhere)
     */
    private function run(AiJobQuery $query, array $claim, ?WorkDir &$workDir): ?string
    {
        $id = $this->extractionId;
        $label = $this->modelLabel();

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

        $agent = app(AgentRegistry::class)->primary();
        $lastAccessCheck = microtime(true);
        $this->abortWhy = null;
        $outcome = $agent->extract(new AgentInput($workDir, $images), function () use ($query, $claim, $id, &$lastAccessCheck): bool {
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

        $health = new AgentHealth;
        if ($outcome->ok && $outcome->payload !== null) {
            $done = $query->finishReady($id, $outcome->payload, (int) $outcome->entryCount, $pagesRead, $pagesTotal, $outcome->model, $outcome->durationMs);
            if ($done) {
                $this->safely(fn () => $health->recordSuccess($agent->key()));

                return 'succeeded';
            }

            // A cancel or a consent withdrawal landed after the last recheck: finish as cancelled, supersede nothing.
            // finishCancelled is a no-op (null) when the row was closed elsewhere.
            $why = $query->abortReason($id) ?? 'user';

            return $query->finishCancelled($id, $why, $outcome->model, $outcome->durationMs) ? 'cancelled' : null;
        }

        $code = $outcome->failure ?? FailureCode::NoOutput;
        if ($code === FailureCode::Auth) {
            $this->safely(fn () => $health->recordAuthFailure($agent->key()));
        }

        return $query->finishFailed($id, $code, $outcome->model, $outcome->durationMs) ? 'failed' : null;
    }

    /** @param  array{subject_id: string, document_id: string, started_by: int}  $claim */
    private function recheck(AiJobQuery $query, array $claim): ?string
    {
        return $this->accessProblem($claim) ?? $query->abortReason($this->extractionId);
    }

    /**
     * 'access' when the starter is gone, lost Health Edit or lost membership on the person, 'consent' when the person's
     * consent is off, otherwise null. Fail closed: any error counts as lost access.
     *
     * @param  array{subject_id: string, document_id: string, started_by: int}  $claim
     */
    private function accessProblem(array $claim): ?string
    {
        try {
            $user = User::query()->find($claim['started_by']);
            if ($user === null) {
                return 'access';
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
