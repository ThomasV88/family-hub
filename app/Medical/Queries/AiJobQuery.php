<?php

namespace App\Medical\Queries;

use App\Medical\Ai\ExtractionSchema;
use App\Medical\Ai\FailureCode;
use App\Medical\AiConsent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Unscoped by design: runs in the ai-worker without a viewer, after run-time re-verification. Lifecycle columns only;
 * never returns names. Every finish call acts only on a row that is still running, so a reaper, a cancel or a
 * redelivery can never be overwritten by a late result.
 */
final class AiJobQuery
{
    private const MODEL_MAX = 60;

    /**
     * Moves a queued row to running (and its call row). Null when the row is gone or not queued: a redelivered job
     * finds nothing to do.
     *
     * @return array{subject_id: string, document_id: string, started_by: int}|null
     */
    public function claim(string $id): ?array
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        return DB::transaction(function () use ($id) {
            $now = Carbon::now();
            $rows = DB::select(
                "update medical_ai_extractions set status = 'running', started_at = ?, updated_at = ? where id = ? and status = 'queued' returning subject_id, document_id, started_by",
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
            ];
        });
    }

    /** The stored file of the run's document (path and kind), joined on document and person. @return array{path: string, kind: string}|null */
    public function document(string $id): ?array
    {
        if (! Str::isUuid($id)) {
            return null;
        }
        $row = DB::table('medical_ai_extractions as e')
            ->join('medical_documents as d', fn ($j) => $j->on('d.id', '=', 'e.document_id')->on('d.subject_id', '=', 'e.subject_id'))
            ->where('e.id', $id)
            ->select(['d.path', 'd.kind'])
            ->first();

        return $row === null ? null : ['path' => (string) $row->path, 'kind' => (string) $row->kind];
    }

    /** 'user' after a cancel request, 'consent' when the person's AI consent is off, otherwise null. Access is rechecked by the job. */
    public function abortReason(string $id): ?string
    {
        if (! Str::isUuid($id)) {
            return 'user';
        }
        $row = DB::table('medical_ai_extractions')->where('id', $id)->select(['subject_id', 'cancel_requested_at'])->first();
        if ($row === null) {
            return 'user';
        }
        if ($row->cancel_requested_at !== null) {
            return 'user';
        }
        if (! AiConsent::allows((string) $row->subject_id)) {
            return 'consent';
        }

        return null;
    }

    /**
     * The previous ready run of the same document becomes superseded in the same transaction. False when the row was
     * no longer running (nothing is written then).
     *
     * @param  array<string, mixed>  $payload
     */
    public function finishReady(string $id, array $payload, int $entryCount, ?int $pagesRead, ?int $pagesTotal, string $model, int $durationMs): bool
    {
        $model = $this->model($model);
        $now = Carbon::now();

        try {
            DB::transaction(function () use ($id, $payload, $entryCount, $pagesRead, $pagesTotal, $model, $durationMs, $now) {
                $documentId = DB::table('medical_ai_extractions')->where('id', $id)->where('status', 'running')->value('document_id');
                if ($documentId === null) {
                    throw new LogicException('not running');
                }
                DB::table('medical_ai_extractions')->where('document_id', $documentId)->where('status', 'ready')
                    ->update(['status' => 'superseded', 'updated_at' => $now]);
                DB::table('medical_ai_extractions')->where('id', $id)->where('status', 'running')->update([
                    'status' => 'ready',
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                    'entry_count' => $entryCount,
                    'pages_read' => $pagesRead,
                    'pages_total' => $pagesTotal,
                    'model' => $model,
                    'schema_version' => (int) ($payload['schema_version'] ?? 1),
                    'prompt_version' => ExtractionSchema::PROMPT_VERSION,
                    'finished_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->closeCall($id, 'succeeded', null, $model, $durationMs, $now);
            });
        } catch (LogicException) {
            return false;
        }

        return true;
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

    /** @param  'user'|'consent'|'access'  $reason */
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
     * second, as failed(worker_lost).
     */
    public function reapStuck(int $runningOlderThanSeconds, int $queuedOlderThanHours): int
    {
        $now = Carbon::now();
        $runningCutoff = $now->copy()->subSeconds($runningOlderThanSeconds);
        $queuedCutoff = $now->copy()->subHours($queuedOlderThanHours);

        return DB::transaction(function () use ($now, $runningCutoff, $queuedCutoff) {
            $rows = DB::select(
                "update medical_ai_extractions set status = 'failed', failure_code = ?, model = 'default', finished_at = ?, updated_at = ? "
                ."where (status = 'running' and started_at < ?) or (status = 'queued' and queued_at < ?) returning id, started_at",
                [FailureCode::WorkerLost->value, $now, $now, $runningCutoff, $queuedCutoff],
            );
            foreach ($rows as $row) {
                $duration = $row->started_at === null ? null : max(0, (int) round(($now->getTimestamp() - Carbon::parse($row->started_at)->getTimestamp()) * 1000));
                $this->closeCall((string) $row->id, 'failed', FailureCode::WorkerLost->value, 'default', $duration, $now);
            }

            return count($rows);
        });
    }

    private function closeCall(string $extractionId, string $status, ?string $failureCode, string $model, ?int $durationMs, Carbon $now): void
    {
        DB::table('ai_calls')->where('extraction_id', $extractionId)->whereIn('status', ['queued', 'running'])->update([
            'status' => $status,
            'failure_code' => $failureCode,
            'model' => $model,
            'duration_ms' => $durationMs,
            'finished_at' => $now,
        ]);
    }

    private function model(string $model): string
    {
        $model = mb_substr(trim($model), 0, self::MODEL_MAX);

        return $model === '' ? 'default' : $model;
    }
}
