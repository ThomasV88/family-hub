<?php

namespace App\Medical\Ai;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Medical\Ai\Agents\Agent;
use App\Medical\Ai\Agents\AgentInput;
use App\Medical\Ai\Agents\AgentOutcome;
use App\Medical\Ai\Agents\AgentRegistry;
use App\Medical\Ai\Transcribe\TranscribeTask;
use App\Medical\Ai\Work\WorkDir;
use App\Medical\AiConsent;
use App\Medical\Documents\HeicConverter;
use App\Medical\Documents\MedicalDocumentStore;
use App\Medical\Documents\PdfRasterizer;
use App\Medical\Documents\Text\SaveOutcome;
use App\Medical\Queries\AiJobQuery;
use App\Medical\Queries\AiRunAudit;
use App\Medical\Queries\DocumentTextStore;
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
     * @param  array{subject_id: string, document_id: string, started_by: int, bulk_run_id: ?string, mode: string}  $claim
     * @return 'succeeded'|'failed'|'cancelled'|'waiting'|null null when a finish call no longer applied (row closed elsewhere)
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
        } elseif ($document['kind'] === 'heic') {
            // The photo is decoded into a metadata-free JPEG in this work dir only: nothing is stored, the original on the
            // medical disk stays as it is, and the CLI only ever sees doc.jpg (never the HEIC).
            $copied = $store->copyTo($document['path'], $workDir->file('doc.heic'));
            $converted = $copied && (new HeicConverter)->toJpeg($workDir->file('doc.heic'), $workDir->file('doc.jpg'));
            @unlink($workDir->file('doc.heic'));
            if (! $converted) {
                @unlink($workDir->file('doc.jpg'));

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
        $deadline = time() + (int) config('ai.timeouts.job') - 30;
        $mode = $claim['mode'] ?? 'entries';

        $textPages = null;
        $textTotal = null;
        if ($mode !== 'entries') {
            // Step 1: the text of the pages, stored after every validated batch.
            $step = $this->transcribe($query, $registry, $health, $claim, $workDir, $images, $pagesTotal, $mode, $deadline);
            if ($step['end']) {
                return $step['status'];
            }
            if ($mode === 'text') {
                $text = $query->savedText($id);
                if ($text !== null && $query->finishTextSaved($id, $text['pagesRead'], $text['pagesTotal'], $step['agent'] ?? $registry->primary()->key(), $step['model'] ?? $label, $step['durationMs'])) {
                    $this->safely(fn () => $health->recordSuccess($step['agent'] ?? $registry->primary()->key()));

                    return 'succeeded';
                }

                return $query->finishCancelled($id, $query->abortReason($id) ?? 'user', $step['model'] ?? $label, $step['durationMs']) ? 'cancelled' : null;
            }
            $text = $query->savedText($id);
            $textPages = $text['pagesRead'] ?? null;
            $textTotal = $text['pagesTotal'] ?? null;
        }

        // The image-to-entries call: the whole run in mode entries, step 2 in mode full (until the entries are read from the text).
        $r = $this->attempt($query, $registry, $health, $claim, $workDir, $images, $images, null, $deadline);
        if ($r['kind'] === 'end') {
            return $r['status'];
        }
        if ($r['kind'] === 'failed') {
            return $query->finishFailed($id, $r['code'], $r['model'], $r['durationMs']) ? 'failed' : null;
        }

        $agent = $r['agent'];
        $outcome = $r['outcome'];
        $done = $query->finishReady($id, $outcome->payload, (int) $outcome->entryCount, $textPages ?? $pagesRead, $textTotal ?? $pagesTotal, $agent->key(), $outcome->model, $outcome->durationMs);
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

    /**
     * Step 1: transcribes the page images in batches, stores the text read so far after every validated batch and stops
     * early (partial text) when the time budget or a late failure says so. Pages this run already stored (a resume after
     * a quota park) are skipped. 'end' is true when the run reached a terminal state or parked ('status' says which).
     *
     * @param  array{subject_id: string, document_id: string, started_by: int, bulk_run_id: ?string, mode?: string}  $claim
     * @param  list<string>  $images
     * @return array{end: bool, status: ?string, agent: ?string, model: ?string, durationMs: int}
     */
    private function transcribe(AiJobQuery $query, AgentRegistry $registry, AgentHealth $health, array $claim, WorkDir $workDir, array $images, int $pagesTotal, string $mode, int $deadline): array
    {
        $id = $this->extractionId;
        $last = ['end' => false, 'status' => null, 'agent' => null, 'model' => null, 'durationMs' => 0];

        $text = $query->savedText($id);
        $pages = $text['pages'] ?? [];
        $language = $text['language'] ?? null;
        $target = min($pagesTotal, (int) config('ai.ocr.max_pages'), count($images));
        $perCall = max(1, (int) config('ai.ocr.pages_per_call'));
        $perPage = (float) config('ai.ocr.seconds_per_page');
        $reserve = $mode === 'full' ? (int) config('ai.ocr.extract_reserve_seconds') : 0;

        $offset = count($pages);
        while ($offset < $target) {
            $count = min($perCall, $target - $offset);
            // With text already stored the next batch must fit the budget (and, in mode full, leave room for step 2).
            if ($offset > 0 && $deadline - time() < $count * $perPage + $reserve) {
                break;
            }

            $r = $this->attempt($query, $registry, $health, $claim, $workDir, $images, array_slice($images, $offset, $count), new TranscribeTask($count), $deadline);
            if ($r['kind'] === 'end') {
                return ['end' => true, 'status' => $r['status']] + $last;
            }
            if ($r['kind'] === 'failed') {
                if ($offset === 0) {
                    return ['end' => true, 'status' => $query->finishFailed($id, $r['code'], $r['model'], $r['durationMs']) ? 'failed' : null] + $last;
                }
                // Text is already stored: keep it, record the failed call and stop with a partial text.
                $query->closeAttempt($id, 'failed', $r['code'], $r['model'], $r['durationMs']);
                break;
            }

            $agent = $r['agent'];
            $outcome = $r['outcome'];
            $batchLanguage = $outcome->payload['language'] ?? null;
            if ($batchLanguage !== null) {
                $language = ($language === null || $language === $batchLanguage) ? $batchLanguage : 'mixed';
            }
            $pages = array_merge($pages, $outcome->payload['pages']);

            // No catch here on purpose: a database error message carries the bound text. The job's outer catch is content-free.
            $saved = app(DocumentTextStore::class)->save(
                $claim['document_id'], $id, $claim['started_by'], $agent->key(), $outcome->model, $language, $pages, $pagesTotal, count($pages) === $pagesTotal,
            );
            if ($saved !== SaveOutcome::Saved) {
                $why = $saved === SaveOutcome::ConsentOff ? 'consent' : 'access';

                return ['end' => true, 'status' => $query->finishCancelled($id, $why, $outcome->model, $outcome->durationMs) ? 'cancelled' : null] + $last;
            }
            $query->closeStep($id, $outcome->model, $outcome->durationMs);
            $this->safely(fn () => $health->recordSuccess($agent->key()));

            $last = ['end' => false, 'status' => null, 'agent' => $agent->key(), 'model' => $outcome->model, 'durationMs' => $outcome->durationMs];
            $offset += $count;
        }

        return $last;
    }

    /**
     * One agent call with everything around it: provider filter, rechecks before and after, the abort loop, quota and
     * auth fallback to the next agent and parking when none is left. $keep names the files that stay in the work dir
     * (all page images), $send the images of this call. Never decides a success: the caller persists the result.
     *
     * @param  array{subject_id: string, document_id: string, started_by: int, bulk_run_id: ?string}  $claim
     * @param  list<string>  $keep
     * @param  list<string>  $send
     * @return array{kind: 'ok', agent: Agent, outcome: AgentOutcome}|array{kind: 'failed', code: FailureCode, model: string, durationMs: int}|array{kind: 'end', status: ?string}
     */
    private function attempt(AiJobQuery $query, AgentRegistry $registry, AgentHealth $health, array $claim, WorkDir $workDir, array $keep, array $send, ?AiTask $task, int $deadline): array
    {
        $id = $this->extractionId;
        $label = $this->modelLabel();
        $bulk = $claim['bulk_run_id'] !== null;
        $tried = [];
        $lastCode = null;

        while (true) {
            // Re-read before every attempt: an interactive acknowledgment (or a settings change) made meanwhile counts.
            // A bulk row never reads the live acknowledgment: it uses the set frozen on its run at Start.
            $allowed = $query->allowedProviders($claim['started_by'], $claim['bulk_run_id']);
            $agent = $registry->nextCandidate($tried, $bulk, $allowed);
            if ($agent === null) {
                break;
            }

            // Consent, access and cancel are rechecked before each agent is started.
            $why = $this->recheck($query, $claim);
            if ($why !== null) {
                return ['kind' => 'end', 'status' => $query->finishCancelled($id, $why, $agent->modelLabel(), 0) ? 'cancelled' : null];
            }
            if ($deadline - time() < (int) config('ai.timeouts.min_call')) {
                return ['kind' => 'failed', 'code' => FailureCode::Timeout, 'model' => $agent->modelLabel(), 'durationMs' => 0];
            }

            $query->beginAttempt($id, $agent->key(), $claim['bulk_run_id']);
            $this->clearAttemptFiles($workDir, $keep);
            $lastAccessCheck = microtime(true);
            $this->abortWhy = null;
            $outcome = $agent->extract(new AgentInput($workDir, $send, $deadline, $task), function () use ($query, $claim, $id, &$lastAccessCheck): bool {
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
                return ['kind' => 'end', 'status' => $query->finishCancelled($id, $why, $outcome->model, $outcome->durationMs) ? 'cancelled' : null];
            }
            if ($outcome->aborted) {
                return ['kind' => 'end', 'status' => $query->finishCancelled($id, $this->abortWhy ?? 'user', $outcome->model, $outcome->durationMs) ? 'cancelled' : null];
            }

            if ($outcome->ok && $outcome->payload !== null) {
                return ['kind' => 'ok', 'agent' => $agent, 'outcome' => $outcome];
            }

            $code = $outcome->failure ?? FailureCode::NoOutput;
            if ($code === FailureCode::QuotaExhausted) {
                $this->safely(fn () => $health->recordQuotaHit($agent->key(), $outcome->quotaResetAt, $outcome->quotaMinutes));
            } elseif ($code === FailureCode::Auth) {
                $this->safely(fn () => $health->recordAuthFailure($agent->key()));
            } else {
                // Every other failure is terminal: no loop. The caller decides how the run ends.
                return ['kind' => 'failed', 'code' => $code, 'model' => $outcome->model, 'durationMs' => $outcome->durationMs];
            }
            $query->closeAttempt($id, 'failed', $code, $outcome->model, $outcome->durationMs);
            $tried[] = $agent->key();
            $lastCode = $code;
        }

        return ['kind' => 'end', 'status' => $this->noCandidate($query, $registry, $claim, $bulk, $allowed, $lastCode, $label)];
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

    /**
     * Whatever an earlier attempt left in the work dir is gone before the next agent starts: result and schema files, the
     * previous agent's per-run home (with its login copy) and its temp dir contents. Only the page images stay.
     *
     * @param  list<string>  $images
     */
    private function clearAttemptFiles(WorkDir $workDir, array $images): void
    {
        $base = $workDir->path();
        foreach (glob($base.'/*') ?: [] as $path) {
            if (basename($path) === 'tmp' && is_dir($path) && ! is_link($path)) {
                foreach (scandir($path) ?: [] as $entry) {
                    if ($entry !== '.' && $entry !== '..') {
                        WorkDir::removeTree($path.'/'.$entry);
                    }
                }

                continue;
            }
            if (in_array(basename($path), $images, true)) {
                continue;
            }
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                // A per-agent home such as copilot-home, with whatever the CLI wrote into it.
                WorkDir::removeTree($path);
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
                AiRunAudit::meta($claim['subject_id'], $claim['document_id'], $claim['bulk_run_id'], $status),
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
