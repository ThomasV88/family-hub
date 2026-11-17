<?php

namespace App\Recipes\Import;

use App\Medical\Ai\FailureCode;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Persistence of recipe imports. The member-facing methods (activeFor, draftsFor, statusFor) are scoped by user_id; the
 * rest is unscoped on purpose and used only by the two jobs and the maintenance commands, after run-time re-verification.
 * Every finish call acts only on a row that is still in the expected state, so a late result never overwrites a cancel
 * or a reaper. Nothing here logs, and source text never leaves except to the job that claimed the row.
 */
class RecipeImportQuery
{
    private const MODEL_MAX = 60;

    /**
     * Moves a queued import (and its call row) to running. Null when the row is gone or not queued: a redelivered job
     * finds nothing to do.
     *
     * @return array{user_id: int, source_text: string, source_kind: string}|null
     */
    public function claim(string $id): ?array
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        return DB::transaction(function () use ($id) {
            $now = Carbon::now();
            $rows = DB::select(
                "update recipe_imports set status = 'running', started_at = ?, updated_at = ? where id = ? and status = 'queued' and cancel_requested_at is null and source_text is not null returning user_id, source_text, source_kind",
                [$now, $now, $id],
            );
            if ($rows === []) {
                return null;
            }
            DB::table('ai_calls')->where('extraction_id', $id)->where('kind', 'recipe')->where('status', 'queued')
                ->update(['status' => 'running', 'started_at' => $now]);

            return [
                'user_id' => (int) $rows[0]->user_id,
                'source_text' => (string) $rows[0]->source_text,
                'source_kind' => (string) ($rows[0]->source_kind ?? 'text'),
            ];
        });
    }

    /** 'user' when a cancel was requested or the row is gone, otherwise null. */
    public function abortReason(string $id): ?string
    {
        if (! Str::isUuid($id)) {
            return 'user';
        }
        $row = DB::table('recipe_imports')->where('id', $id)->first(['cancel_requested_at']);

        return $row === null || $row->cancel_requested_at !== null ? 'user' : null;
    }

    /** @param  array<string, mixed>  $payload */
    public function finishReady(string $id, array $payload, string $agent, string $model, int $durationMs): bool
    {
        if (! Str::isUuid($id)) {
            return false;
        }
        $model = $this->model($model);
        $now = Carbon::now();

        return DB::transaction(function () use ($id, $payload, $agent, $model, $durationMs, $now) {
            $changed = DB::table('recipe_imports')->where('id', $id)->where('status', 'running')->whereNull('cancel_requested_at')->update([
                'status' => 'ready',
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'agent' => $agent,
                'model' => $model,
                'schema_version' => (int) ($payload['schema_version'] ?? 1),
                'source_text' => null,
                'finished_at' => $now,
                'updated_at' => $now,
            ]);
            if ($changed > 0) {
                $this->closeCall($id, 'succeeded', null, $model, $durationMs, $now);
            }

            return $changed > 0;
        });
    }

    public function finishFailed(string $id, FailureCode $code, string $model, int $durationMs): bool
    {
        $model = $this->model($model);
        $now = Carbon::now();

        return DB::transaction(function () use ($id, $code, $model, $durationMs, $now) {
            $changed = DB::table('recipe_imports')->where('id', $id)->where('status', 'running')->update([
                'status' => 'failed', 'failure_code' => $code->value, 'model' => $model, 'source_text' => null,
                'finished_at' => $now, 'updated_at' => $now,
            ]);
            if ($changed > 0) {
                $this->closeCall($id, 'failed', $code->value, $model, $durationMs, $now);
            }

            return $changed > 0;
        });
    }

    /** @param  'user'|'access'  $reason */
    public function finishCancelled(string $id, string $reason, string $model, int $durationMs): bool
    {
        $model = $this->model($model);
        $now = Carbon::now();

        return DB::transaction(function () use ($id, $reason, $model, $durationMs, $now) {
            $changed = DB::table('recipe_imports')->where('id', $id)->where('status', 'running')->update([
                'status' => 'cancelled', 'cancel_reason' => $reason, 'model' => $model, 'source_text' => null,
                'finished_at' => $now, 'updated_at' => $now,
            ]);
            if ($changed > 0) {
                $this->closeCall($id, 'cancelled', null, $model, $durationMs, $now);
            }

            return $changed > 0;
        });
    }

    /** The fetch step ended without a usable page. Only a still-fetching row is touched. */
    public function failFetching(string $id, string $fetchReason): bool
    {
        if (! Str::isUuid($id)) {
            return false;
        }
        $now = Carbon::now();

        return DB::table('recipe_imports')->where('id', $id)->where('status', 'fetching')->update([
            'status' => 'failed', 'failure_code' => FailureCode::FetchFailed->value, 'fetch_reason' => $fetchReason,
            'source_text' => null, 'finished_at' => $now, 'updated_at' => $now,
        ]) > 0;
    }

    /**
     * Closes an import that was committed as queued but never reached the queue (the dispatch failed). Only a
     * still-queued row is touched.
     */
    public function failQueued(string $id): bool
    {
        if (! Str::isUuid($id)) {
            return false;
        }
        $now = Carbon::now();

        return DB::transaction(function () use ($id, $now) {
            $changed = DB::table('recipe_imports')->where('id', $id)->where('status', 'queued')->update([
                'status' => 'failed', 'failure_code' => FailureCode::WorkerLost->value, 'source_text' => null,
                'finished_at' => $now, 'updated_at' => $now,
            ]);
            if ($changed > 0) {
                $this->closeCall($id, 'failed', FailureCode::WorkerLost->value, 'default', null, $now);
            }

            return $changed > 0;
        });
    }

    /**
     * Starts (or continues) one agent attempt and returns its attempt number. An open call row of the same agent is
     * reused; one of another agent never ran, so it is closed cancelled and a new row is inserted.
     */
    public function beginAttempt(string $id, string $agent): int
    {
        $now = Carbon::now();

        return DB::transaction(function () use ($id, $agent, $now) {
            $import = DB::table('recipe_imports')->where('id', $id)->lockForUpdate()->first(['user_id']);
            if ($import === null) {
                throw new LogicException('import gone');
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
                'kind' => 'recipe',
                'subject_id' => null,
                'document_id' => null,
                'started_by' => $import->user_id,
                'agent' => $agent,
                'attempt' => $attempt,
                'status' => 'running',
                'queued_at' => $now,
                'started_at' => $now,
            ]);

            return $attempt;
        });
    }

    /** Closes only the open call row; the import itself stays running. */
    public function closeAttempt(string $id, string $status, FailureCode $code, string $model, int $durationMs): void
    {
        $this->closeCall($id, $status, $code->value, $this->model($model), $durationMs, Carbon::now());
    }

    /** The import ids a stuck-job reaper looks at: not scoped, ids only. @return list<string> */
    public function idsInStatusOlderThan(string $status, int $minutes): array
    {
        return DB::table('recipe_imports')->where('status', $status)
            ->where('created_at', '<', Carbon::now()->subMinutes($minutes))->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    /** @return list<array<string, mixed>> */
    public function activeFor(User $user): array
    {
        return $this->summaries(
            DB::table('recipe_imports')->where('user_id', $user->id)->whereIn('status', ImportStatus::active())->orderBy('created_at'),
        );
    }

    /**
     * The member's drafts: active, ready and failed imports of the last ready_ttl_hours that were not dismissed.
     *
     * @return list<array<string, mixed>>
     */
    public function draftsFor(User $user): array
    {
        return $this->summaries(
            DB::table('recipe_imports')->where('user_id', $user->id)->whereIn('status', ImportStatus::listed())
                ->whereNull('dismissed_at')
                ->where('created_at', '>=', Carbon::now()->subHours((int) config('recipes.import.ready_ttl_hours')))
                ->orderByDesc('created_at'),
        );
    }

    /** One of the member's own imports with its draft (ready only); null for anyone else's id. @return array<string, mixed>|null */
    public function statusFor(User $user, string $id): ?array
    {
        if (! Str::isUuid($id)) {
            return null;
        }
        $row = DB::table('recipe_imports')->where('id', $id)->where('user_id', $user->id)->first();
        if ($row === null) {
            return null;
        }
        $summary = $this->summary($row);
        $summary['payload'] = $row->status === 'ready' && $row->payload !== null ? json_decode((string) $row->payload, true) : null;
        $summary['textCut'] = (bool) $row->text_cut;
        $summary['sourceKind'] = $row->source_kind;

        return $summary;
    }

    /** @return list<array<string, mixed>> */
    private function summaries(Builder $query): array
    {
        return $query->get(['id', 'url', 'host', 'status', 'failure_code', 'fetch_reason', 'cancel_reason', 'agent', 'model', 'created_at', 'finished_at'])
            ->map(fn ($row) => $this->summary($row))->all();
    }

    /** @return array<string, mixed> */
    private function summary(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'url' => (string) $row->url,
            'host' => (string) $row->host,
            'status' => (string) $row->status,
            'failureCode' => $row->failure_code,
            'fetchReason' => $row->fetch_reason,
            'cancelReason' => $row->cancel_reason,
            'agent' => $row->agent,
            'model' => ($row->model === null || $row->model === 'default') ? null : (string) $row->model,
            'createdAt' => Carbon::parse($row->created_at)->toIso8601String(),
            'finishedAt' => $row->finished_at === null ? null : Carbon::parse($row->finished_at)->toIso8601String(),
        ];
    }

    /** A call-log failure never undoes the import outcome: the savepoint keeps the surrounding transaction usable. */
    private function closeCall(string $importId, string $status, ?string $failureCode, string $model, ?int $durationMs, Carbon $now): void
    {
        try {
            DB::transaction(fn () => DB::table('ai_calls')->where('extraction_id', $importId)->where('kind', 'recipe')->whereIn('status', ['queued', 'running'])->update([
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
