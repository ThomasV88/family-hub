<?php

namespace App\Medical\Queries;

use App\Access\Module;
use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Medical\Ai\ExtractionSchema;
use App\Medical\Ai\FailureCode;
use App\Medical\AiConsent;
use App\Medical\Documents\Text\TextNormalizer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * Unscoped by design: runs in the ai-worker without a viewer, after run-time re-verification. Lifecycle columns only;
 * never returns names. Every finish call acts only on a row that is still running, so a reaper, a cancel or a
 * redelivery can never be overwritten by a late result.
 */
class AiJobQuery
{
    private const MODEL_MAX = 60;

    /**
     * Moves a queued row to running (and its call row). Null when the row is gone or not queued: a redelivered job
     * finds nothing to do.
     *
     * @return array{subject_id: string, document_id: string, started_by: int, bulk_run_id: ?string, mode: string, fresh_read: bool, adjustments: ?array}|null
     */
    public function claim(string $id): ?array
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        return DB::transaction(function () use ($id) {
            $now = Carbon::now();
            $rows = DB::select(
                "update medical_ai_extractions set status = 'running', started_at = ?, updated_at = ? where id = ? and status = 'queued' returning subject_id, document_id, started_by, bulk_run_id, mode, fresh_read, adjustments",
                [$now, $now, $id],
            );
            if ($rows === []) {
                return null;
            }
            DB::table('ai_calls')->where('extraction_id', $id)->where('status', 'queued')
                ->update(['status' => 'running', 'started_at' => $now]);

            return [
                'subject_id' => (string) $rows[0]->subject_id,
                'document_id' => (string) $rows[0]->document_id,
                'started_by' => (int) $rows[0]->started_by,
                'bulk_run_id' => $rows[0]->bulk_run_id === null ? null : (string) $rows[0]->bulk_run_id,
                'mode' => (string) $rows[0]->mode,
                'fresh_read' => (bool) $rows[0]->fresh_read,
                'adjustments' => $rows[0]->adjustments === null ? null : json_decode((string) $rows[0]->adjustments, true),
            ];
        });
    }

    /** A two-step run that starts while ai.ocr.enabled is off becomes an ordinary one-call run (only while it is running). */
    public function downgradeToEntries(string $id): void
    {
        if (Str::isUuid($id)) {
            DB::table('medical_ai_extractions')->where('id', $id)->where('status', 'running')->update(['mode' => 'entries', 'updated_at' => Carbon::now()]);
        }
    }

    /**
     * The text already stored by this very run (resume after a quota park): only a text row whose extraction_id is the
     * run counts, so a text of an earlier run is never mistaken for progress. Pages split on U+000C.
     *
     * @return array{pages: list<string>, pagesRead: int, pagesTotal: int, complete: bool, language: ?string}|null
     */
    public function savedText(string $id): ?array
    {
        if (! Str::isUuid($id)) {
            return null;
        }
        $row = DB::table('medical_document_texts as t')
            ->join('medical_ai_extractions as e', 'e.document_id', '=', 't.document_id')
            ->where('e.id', $id)->where('t.extraction_id', $id)
            ->first(['t.body', 't.pages_read', 't.pages_total', 't.complete', 't.language']);
        if ($row === null) {
            return null;
        }
        $pagesRead = (int) $row->pages_read;

        return [
            'pages' => $pagesRead === 0 ? [] : array_slice(explode(TextNormalizer::PAGE_SEPARATOR, (string) $row->body), 0, $pagesRead),
            'pagesRead' => $pagesRead,
            'pagesTotal' => (int) $row->pages_total,
            'complete' => (bool) $row->complete,
            'language' => $row->language === null ? null : (string) $row->language,
        ];
    }

    /**
     * The stored text of the run's document, whichever run wrote it (job side only; savedText answers only for this run's
     * own text). Pages are the stored pages in order; language, agent and model are carried so the text can be re-saved
     * unchanged under this run.
     *
     * @return array{pages: list<string>, pagesRead: int, pagesTotal: int, complete: bool, textVersion: int, extractionId: ?string, language: ?string, agent: ?string, model: ?string}|null
     */
    public function storedText(string $id): ?array
    {
        if (! Str::isUuid($id)) {
            return null;
        }
        $row = DB::table('medical_document_texts as t')
            ->join('medical_ai_extractions as e', 'e.document_id', '=', 't.document_id')
            ->where('e.id', $id)
            ->first(['t.body', 't.pages_read', 't.pages_total', 't.complete', 't.text_version', 't.extraction_id', 't.language', 't.agent', 't.model']);
        if ($row === null) {
            return null;
        }
        $pagesRead = (int) $row->pages_read;

        return [
            'pages' => $pagesRead === 0 ? [] : array_slice(explode(TextNormalizer::PAGE_SEPARATOR, (string) $row->body), 0, $pagesRead),
            'pagesRead' => $pagesRead,
            'pagesTotal' => (int) $row->pages_total,
            'complete' => (bool) $row->complete,
            'textVersion' => (int) $row->text_version,
            'extractionId' => $row->extraction_id === null ? null : (string) $row->extraction_id,
            'language' => $row->language === null ? null : (string) $row->language,
            'agent' => $row->agent === null ? null : (string) $row->agent,
            'model' => $row->model === null ? null : (string) $row->model,
        ];
    }

    /** Closes the open call row of the run as succeeded (one transcription batch done); the extraction stays running. */
    public function closeStep(string $id, string $model, int $durationMs): void
    {
        $this->closeCall($id, 'succeeded', null, $this->model($model), $durationMs, Carbon::now());
    }

    /**
     * Ends a text-only run: the transcribed text is stored (a text row carries this run's id), the run becomes
     * text_saved. Same guards as finishReady (still running, no cancel request, consent on under FOR SHARE, person and
     * document not archived), but it never supersedes anything and never touches a ready row or a decision. False when
     * any check fails; nothing is written then and the caller maps it to a cancelled finish.
     */
    public function finishTextSaved(string $id, int $pagesRead, int $pagesTotal, string $agent, string $model, int $durationMs): bool
    {
        if (! Str::isUuid($id)) {
            return false;
        }
        $model = $this->model($model);
        $now = Carbon::now();

        try {
            DB::transaction(function () use ($id, $pagesRead, $pagesTotal, $agent, $model, $durationMs, $now) {
                $row = DB::table('medical_ai_extractions')->where('id', $id)->where('status', 'running')->where('mode', 'text')
                    ->whereNull('cancel_requested_at')->lockForUpdate()->first(['document_id', 'subject_id']);
                if ($row === null) {
                    throw new LogicException('not running');
                }
                $consent = DB::table('medical_subjects')->where('id', $row->subject_id)->whereNull('archived_at')->sharedLock()->value('ai_consent');
                if (! $consent) {
                    throw new LogicException('consent withdrawn');
                }
                if (DB::table('medical_documents')->where('id', $row->document_id)->whereNotNull('archived_at')->exists()) {
                    throw new LogicException('document archived');
                }
                if (! DB::table('medical_document_texts')->where('document_id', $row->document_id)->where('extraction_id', $id)->exists()) {
                    throw new LogicException('no text stored');
                }
                $written = DB::table('medical_ai_extractions')->where('id', $id)->where('status', 'running')->update([
                    'status' => 'text_saved',
                    'agent' => $agent,
                    'model' => $model,
                    'pages_read' => $pagesRead,
                    'pages_total' => $pagesTotal,
                    'finished_at' => $now,
                    'updated_at' => $now,
                ]);
                if ($written !== 1) {
                    throw new LogicException('not running');
                }
                $this->closeCall($id, 'succeeded', null, $model, $durationMs, $now);
            });
        } catch (LogicException) {
            return false;
        }

        return true;
    }

    /** The stored file of the run's document (path and kind), joined on document and person. @return array{path: string, kind: string}|null */
    public function document(string $id): ?array
    {
        if (! Str::isUuid($id)) {
            return null;
        }
        $row = DB::table('medical_ai_extractions as e')
            ->join('medical_documents as d', fn ($j) => $j->on('d.id', '=', 'e.document_id')->on('d.subject_id', '=', 'e.subject_id'))
            ->join('medical_subjects as s', 's.id', '=', 'e.subject_id')
            ->where('e.id', $id)
            ->whereNull('d.archived_at')
            ->whereNull('s.archived_at')
            ->select(['d.path', 'd.kind'])
            ->first();

        return $row === null ? null : ['path' => (string) $row->path, 'kind' => (string) $row->kind];
    }

    /**
     * 'user' after a cancel request, 'consent' when the person's AI consent is off, 'access' when the document or its
     * person has been archived meanwhile (a running job of an archived record is cancelled), otherwise null. Access is
     * rechecked by the job.
     */
    public function abortReason(string $id): ?string
    {
        if (! Str::isUuid($id)) {
            return 'user';
        }
        $row = DB::table('medical_ai_extractions')->where('id', $id)->select(['subject_id', 'document_id', 'cancel_requested_at'])->first();
        if ($row === null) {
            return 'user';
        }
        if ($row->cancel_requested_at !== null) {
            return 'user';
        }
        if (! AiConsent::allows((string) $row->subject_id)) {
            return 'consent';
        }
        $archived = DB::table('medical_documents as d')->join('medical_subjects as s', 's.id', '=', 'd.subject_id')
            ->where('d.id', $row->document_id)
            ->where(fn ($q) => $q->whereNotNull('d.archived_at')->orWhereNotNull('s.archived_at'))
            ->exists();

        return $archived ? 'access' : null;
    }

    /**
     * The previous ready run of the same document becomes superseded in the same transaction, but only once this row is
     * confirmed (under a row lock) to be still running, not cancel-requested and for a person who still consents.
     * False when any of that no longer holds: nothing is written then and the old proposal stays as it is. The caller
     * maps false to a cancelled finish.
     *
     * @param  array<string, mixed>  $payload
     */
    public function finishReady(string $id, array $payload, int $entryCount, ?int $pagesRead, ?int $pagesTotal, string $agent, string $model, int $durationMs, int $promptVersion = ExtractionSchema::PROMPT_VERSION): bool
    {
        if (! Str::isUuid($id)) {
            return false;
        }
        $model = $this->model($model);
        $now = Carbon::now();
        $decided = false;

        try {
            DB::transaction(function () use ($id, $payload, $entryCount, $pagesRead, $pagesTotal, $agent, $model, $durationMs, $now, $promptVersion, &$decided) {
                $row = DB::table('medical_ai_extractions')->where('id', $id)->where('status', 'running')
                    ->whereNull('cancel_requested_at')->lockForUpdate()->first(['document_id', 'subject_id', 'bulk_run_id']);
                if ($row === null) {
                    throw new LogicException('not running');
                }
                // FOR SHARE serializes with a consent change, which takes FOR UPDATE on the subject row.
                $consent = DB::table('medical_subjects')->where('id', $row->subject_id)->whereNull('archived_at')->sharedLock()->value('ai_consent');
                if (! $consent) {
                    throw new LogicException('consent withdrawn');
                }
                if (DB::table('medical_documents')->where('id', $row->document_id)->whereNotNull('archived_at')->exists()) {
                    throw new LogicException('document archived');
                }
                // A bulk re-read never replaces a proposal a human has already accepted entries of.
                if ($row->bulk_run_id !== null && $this->hasAcceptedDecision((string) $row->document_id)) {
                    DB::table('medical_ai_extractions')->where('id', $id)->where('status', 'running')->update([
                        'status' => 'cancelled', 'cancel_reason' => 'decided', 'model' => $model, 'finished_at' => $now, 'updated_at' => $now,
                    ]);
                    $this->closeCall($id, 'cancelled', null, $model, $durationMs, $now);
                    $decided = true;

                    return;
                }
                DB::table('medical_ai_extractions')->where('document_id', $row->document_id)->where('status', 'ready')
                    ->update(['status' => 'superseded', 'updated_at' => $now]);
                $written = DB::table('medical_ai_extractions')->where('id', $id)->where('status', 'running')->update([
                    'status' => 'ready',
                    'agent' => $agent,
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                    'entry_count' => $entryCount,
                    'pages_read' => $pagesRead,
                    'pages_total' => $pagesTotal,
                    'model' => $model,
                    'schema_version' => (int) ($payload['schema_version'] ?? 1),
                    'prompt_version' => $promptVersion,
                    'finished_at' => $now,
                    'updated_at' => $now,
                ]);
                if ($written !== 1) {
                    throw new LogicException('not running');
                }
                $this->closeCall($id, 'succeeded', null, $model, $durationMs, $now);
            });
        } catch (LogicException) {
            return false;
        }

        return ! $decided;
    }

    /** True when the row was closed by the bulk never-supersede-accepted guard. */
    public function finishedAsDecided(string $id): bool
    {
        return Str::isUuid($id)
            && DB::table('medical_ai_extractions')->where('id', $id)->where('status', 'cancelled')->where('cancel_reason', 'decided')->exists();
    }

    private function hasAcceptedDecision(string $documentId): bool
    {
        return DB::table('medical_ai_decisions as x')
            ->join('medical_ai_extractions as e', 'e.id', '=', 'x.extraction_id')
            ->where('e.document_id', $documentId)->where('x.decision', 'accepted')
            ->exists();
    }

    public function finishFailed(string $id, FailureCode $code, string $model, int $durationMs): bool
    {
        $model = $this->model($model);
        $now = Carbon::now();

        return DB::transaction(function () use ($id, $code, $model, $durationMs, $now) {
            $changed = DB::table('medical_ai_extractions')->where('id', $id)->where('status', 'running')->update([
                'status' => 'failed', 'failure_code' => $code->value, 'model' => $model, 'finished_at' => $now, 'updated_at' => $now,
            ]);
            if ($changed > 0) {
                $this->closeCall($id, 'failed', $code->value, $model, $durationMs, $now);
            }

            return $changed > 0;
        });
    }

    /**
     * Closes a run that was committed as queued but never reached the queue (the dispatch failed), so the document is
     * not blocked until the stale-queue reaper. Only a still-queued row is touched.
     */
    public function failQueued(string $id): bool
    {
        if (! Str::isUuid($id)) {
            return false;
        }
        $now = Carbon::now();

        return DB::transaction(function () use ($id, $now) {
            $changed = DB::table('medical_ai_extractions')->where('id', $id)->where('status', 'queued')->update([
                'status' => 'failed', 'failure_code' => FailureCode::WorkerLost->value, 'model' => 'default', 'finished_at' => $now, 'updated_at' => $now,
            ]);
            if ($changed > 0) {
                $this->closeCall($id, 'failed', FailureCode::WorkerLost->value, 'default', null, $now);
            }

            return $changed > 0;
        });
    }

    /** @param  'user'|'consent'|'access'|'decided'  $reason */
    public function finishCancelled(string $id, string $reason, string $model, int $durationMs): bool
    {
        $model = $this->model($model);
        $now = Carbon::now();

        return DB::transaction(function () use ($id, $reason, $model, $durationMs, $now) {
            $changed = DB::table('medical_ai_extractions')->where('id', $id)->where('status', 'running')->update([
                'status' => 'cancelled', 'cancel_reason' => $reason, 'model' => $model, 'finished_at' => $now, 'updated_at' => $now,
            ]);
            if ($changed > 0) {
                $this->closeCall($id, 'cancelled', null, $model, $durationMs, $now);
            }

            return $changed > 0;
        });
    }

    /**
     * Closes rows a dead worker left behind: running rows older than the first bound and queued rows older than the
     * second, as failed(worker_lost); and waiting rows whose first park is older than the wait cap (plus 5 minutes,
     * so the job's own check wins) as failed(quota_timeout).
     */
    public function reapStuck(int $runningOlderThanSeconds, int $queuedOlderThanHours): int
    {
        $now = Carbon::now();
        $runningCutoff = $now->copy()->subSeconds($runningOlderThanSeconds);
        $queuedCutoff = $now->copy()->subHours($queuedOlderThanHours);
        $waitingCutoff = $now->copy()->subHours((int) config('ai.quota.wait_cap_hours', 24))->subMinutes(5);

        [$count, $timedOut] = DB::transaction(function () use ($now, $runningCutoff, $queuedCutoff, $waitingCutoff) {
            $rows = DB::select(
                "update medical_ai_extractions set status = 'failed', failure_code = ?, model = 'default', finished_at = ?, updated_at = ? "
                ."where (status = 'running' and started_at < ?) or (status = 'queued' and queued_at < ?) returning id, started_at",
                [FailureCode::WorkerLost->value, $now, $now, $runningCutoff, $queuedCutoff],
            );
            foreach ($rows as $row) {
                $duration = $row->started_at === null ? null : max(0, (int) round(($now->getTimestamp() - Carbon::parse($row->started_at)->getTimestamp()) * 1000));
                $this->closeCall((string) $row->id, 'failed', FailureCode::WorkerLost->value, 'default', $duration, $now);
            }
            // available_at must go in the same statement (CHECK: set exactly while waiting); waiting_since stays as the anchor.
            $waiting = DB::select(
                "update medical_ai_extractions set status = 'failed', failure_code = ?, model = 'default', available_at = NULL, finished_at = ?, updated_at = ? "
                ."where status = 'waiting' and waiting_since < ? returning id, subject_id, document_id, started_by, bulk_run_id",
                [FailureCode::QuotaTimeout->value, $now, $now, $waitingCutoff],
            );
            foreach ($waiting as $row) {
                $this->closeCall((string) $row->id, 'failed', FailureCode::QuotaTimeout->value, 'default', null, $now);
            }

            return [count($rows) + count($waiting), $waiting];
        });

        foreach ($timedOut as $row) {
            try {
                app(AuditLogger::class)->record(
                    AuditEvent::MedicalAiRunFinished,
                    User::query()->find((int) $row->started_by),
                    AiRunAudit::meta((string) $row->subject_id, (string) $row->document_id, $row->bulk_run_id === null ? null : (string) $row->bulk_run_id, 'failed'),
                    null,
                    Module::Health,
                );
            } catch (Throwable) {
            }
        }

        return $count;
    }

    /**
     * Starts (or continues) one agent attempt and returns its attempt number. An open call row of the same agent is
     * reused; one of another agent never ran, so it is closed cancelled and a new row is inserted.
     */
    public function beginAttempt(string $id, string $agent, ?string $bulkRunId): int
    {
        $now = Carbon::now();

        return DB::transaction(function () use ($id, $agent, $bulkRunId, $now) {
            $ext = DB::table('medical_ai_extractions')->where('id', $id)->lockForUpdate()->first(['subject_id', 'document_id', 'started_by']);
            if ($ext === null) {
                throw new LogicException('extraction gone');
            }
            $open = DB::table('ai_calls')->where('extraction_id', $id)->whereIn('status', ['queued', 'running'])
                ->orderByDesc('attempt')->lockForUpdate()->first(['id', 'agent', 'attempt', 'started_at']);
            if ($open !== null && $open->agent === $agent) {
                DB::table('ai_calls')->where('id', $open->id)->update(
                    $open->started_at === null ? ['status' => 'running', 'started_at' => $now] : ['status' => 'running'],
                );

                return (int) $open->attempt;
            }
            if ($open !== null) {
                DB::table('ai_calls')->where('id', $open->id)->update(['status' => 'cancelled', 'model' => 'default', 'finished_at' => $now]);
            }
            $attempt = ((int) DB::table('ai_calls')->where('extraction_id', $id)->max('attempt')) + 1;
            DB::table('ai_calls')->insert([
                'extraction_id' => $id,
                'subject_id' => $ext->subject_id,
                'document_id' => $ext->document_id,
                'started_by' => $ext->started_by,
                'agent' => $agent,
                'attempt' => $attempt,
                'status' => 'running',
                'queued_at' => $now,
                'started_at' => $now,
                'bulk_run_id' => $bulkRunId,
            ]);

            return $attempt;
        });
    }

    /** Closes only the open call row; the extraction itself stays running. */
    public function closeAttempt(string $id, string $status, FailureCode $code, string $model, int $durationMs): void
    {
        $this->closeCall($id, $status, $code->value, $this->model($model), $durationMs, Carbon::now());
    }

    /**
     * Parks a still-running, not cancel-requested run until the given time. waiting_since is written by the first
     * park only. An open call row (an attempt that never began) is closed cancelled with it.
     */
    public function parkWaiting(string $id, CarbonImmutable $availableAt): bool
    {
        if (! Str::isUuid($id)) {
            return false;
        }
        $now = Carbon::now();
        $at = Carbon::instance($availableAt)->setTimezone((string) config('app.timezone'));

        return DB::transaction(function () use ($id, $at, $now) {
            $changed = DB::update(
                "update medical_ai_extractions set status = 'waiting', available_at = ?, waiting_since = COALESCE(waiting_since, ?), updated_at = ? "
                ."where id = ? and status = 'running' and cancel_requested_at is null",
                [$at, $now, $now, $id],
            );
            if ($changed > 0) {
                $this->closeCall($id, 'cancelled', null, 'default', null, $now);
            }

            return $changed > 0;
        });
    }

    public function waitingSince(string $id): ?CarbonImmutable
    {
        if (! Str::isUuid($id)) {
            return null;
        }
        $value = DB::table('medical_ai_extractions')->where('id', $id)->value('waiting_since');

        return $value === null ? null : CarbonImmutable::parse($value);
    }

    /**
     * Puts due waiting rows back in the queue (queued_at reset so the stale-queue reaper measures from now).
     *
     * @return list<array{id: string, bulk_run_id: ?string}>
     */
    public function resumeDue(int $limit = 20): array
    {
        $now = Carbon::now();
        $rows = DB::select(
            "update medical_ai_extractions set status = 'queued', available_at = NULL, queued_at = ?, updated_at = ? "
            ."where id in (select id from medical_ai_extractions where status = 'waiting' and available_at <= ? "
            .'order by available_at limit ? for update skip locked) returning id, bulk_run_id',
            [$now, $now, $now, max(1, $limit)],
        );

        return array_map(fn ($r) => ['id' => (string) $r->id, 'bulk_run_id' => $r->bulk_run_id === null ? null : (string) $r->bulk_run_id], $rows);
    }

    /** A resumed row whose dispatch failed goes back to waiting for a minute. */
    public function rewait(string $id): void
    {
        if (! Str::isUuid($id)) {
            return;
        }
        $now = Carbon::now();
        DB::update(
            "update medical_ai_extractions set status = 'waiting', available_at = ?, updated_at = ? where id = ? and status = 'queued'",
            [$now->copy()->addMinute(), $now, $id],
        );
    }

    /** Fails closed: a bulk run that cannot be found counts as cancelled. */
    public function bulkRunCancelled(string $bulkRunId): bool
    {
        if (! Str::isUuid($bulkRunId)) {
            return true;
        }

        $status = DB::table('ai_bulk_runs')->where('id', $bulkRunId)->value('status');

        return ! in_array($status, ['active', 'paused'], true);
    }

    /**
     * The providers a run may send documents to. Bulk rows use the set frozen on the run at Start (empty when the run is
     * gone: fail closed); interactive rows use the starter's live acknowledgment.
     *
     * @return list<string>
     */
    public function allowedProviders(?int $userId, ?string $bulkRunId): array
    {
        if ($bulkRunId === null) {
            return $this->ackedProviders($userId);
        }
        $providers = Str::isUuid($bulkRunId) ? DB::table('ai_bulk_runs')->where('id', $bulkRunId)->value('providers') : null;

        return $providers === null ? [] : array_values(array_filter(array_map('trim', explode(',', (string) $providers)), fn ($p) => $p !== ''));
    }

    /** @return list<string> provider keys the user acknowledged in the notice; empty when there is no row or no user */
    public function ackedProviders(?int $userId): array
    {
        if ($userId === null) {
            return [];
        }
        $providers = DB::table('ai_notice_acks')->where('user_id', $userId)->value('providers');
        if ($providers === null) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $providers)), fn ($p) => $p !== ''));
    }

    /**
     * The call log is a side record: a failure here (for example a trigger refusing a row that is already closed) must
     * never undo the extraction outcome. The savepoint keeps the surrounding transaction usable.
     */
    private function closeCall(string $extractionId, string $status, ?string $failureCode, string $model, ?int $durationMs, Carbon $now): void
    {
        try {
            DB::transaction(fn () => DB::table('ai_calls')->where('extraction_id', $extractionId)->whereIn('status', ['queued', 'running'])->update([
                'status' => $status,
                'failure_code' => $failureCode,
                'model' => $model,
                'duration_ms' => $durationMs,
                'finished_at' => $now,
            ]));
        } catch (QueryException) {
        }
    }

    private function model(string $model): string
    {
        $model = mb_substr(trim($model), 0, self::MODEL_MAX);

        return $model === '' ? 'default' : $model;
    }
}
