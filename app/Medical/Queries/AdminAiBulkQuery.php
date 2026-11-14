<?php

namespace App\Medical\Queries;

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Admin bulk planning and control. Integers, enums and times only.
 *
 * The only id returned is the run id (the controls need it). No started_by, no document or person ids, no
 * per-person numbers: the admin may not be a member of the people whose documents a run reads.
 */
final class AdminAiBulkQuery
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AiBulkEligibility $eligibility,
        private readonly AiBulkJobQuery $jobs,
        private readonly AiAgentSettingsQuery $agents,
    ) {}

    /** @return array{token: string, checkedAt: string, validMinutes: int, counts: array<string, int>} */
    public function dryRun(User $admin, string $scope, int $cap): array
    {
        if (! in_array($scope, AiBulkEligibility::SCOPES, true)) {
            throw new InvalidArgumentException('Unknown bulk scope.');
        }
        if ($cap < 1 || $cap > (int) config('ai.bulk.max_cap')) {
            throw new InvalidArgumentException('Cap out of range.');
        }

        return DB::transaction(function () use ($admin, $scope, $cap) {
            $counts = $this->eligibility->counts($scope);
            $now = Carbon::now();
            // Audit first: when it cannot be written, no token exists.
            $this->audit->record(AuditEvent::AiBulkRunChanged, $admin, ['action' => 'dry_run', 'scope' => $scope, 'count' => $counts['eligible']]);
            $token = (string) Str::uuid();
            DB::table('ai_bulk_dry_runs')->insert([
                'id' => $token, 'requested_by' => $admin->id, 'scope' => $scope, 'daily_cap' => $cap,
                'eligible' => $counts['eligible'], 'skipped_consent' => $counts['skipped_consent'],
                'skipped_unsupported' => $counts['skipped_unsupported'], 'skipped_decided' => $counts['skipped_decided'],
                'skipped_active' => $counts['skipped_active'], 'created_at' => $now,
            ]);

            return [
                'token' => $token,
                'checkedAt' => $now->toIso8601String(),
                'validMinutes' => (int) config('ai.bulk.dry_run_ttl_minutes'),
                'counts' => $counts,
            ];
        });
    }

    /**
     * $shownProviders is the provider set the confirm dialog named. It is compared, under the lock provider changes take
     * exclusively, with the enabled set; on a difference nothing is consumed or stored and the answer is
     * 'providers_changed' with the current set, so the dialog opens again with what is true now.
     *
     * @param  list<string>|null  $shownProviders
     * @return array{result: string, panel?: array<string, mixed>, providers?: list<string>} result is ok, expired, stale, active or providers_changed
     */
    public function start(User $admin, string $token, bool $acknowledged, ?array $shownProviders = null): array
    {
        if (! $acknowledged) {
            throw new InvalidArgumentException('Start needs the acknowledgment.');
        }
        if (! Str::isUuid($token)) {
            return ['result' => 'expired'];
        }

        $current = [];
        $result = DB::transaction(function () use ($admin, $token, $shownProviders, &$current) {
            $row = DB::table('ai_bulk_dry_runs')->where('id', $token)->lockForUpdate()->first();
            $now = Carbon::now();
            if ($row === null || (int) $row->requested_by !== $admin->id || $row->consumed_at !== null
                || Carbon::parse($row->created_at)->addMinutes((int) config('ai.bulk.dry_run_ttl_minutes'))->lt($now)) {
                return 'expired';
            }
            DB::select("select pg_advisory_xact_lock_shared(hashtext('ai-agent-settings'))");
            $current = $this->agents->enabledKeys();
            $shown = array_values(array_unique(array_map('strval', $shownProviders ?? [])));
            $enabled = $current;
            sort($shown);
            sort($enabled);
            if ($shownProviders === null || $shown !== $enabled) {
                return 'providers_changed';
            }
            $scope = (string) $row->scope;
            if ($this->eligibility->counts($scope)['eligible'] !== (int) $row->eligible) {
                return 'stale';
            }

            $runId = (string) Str::uuid();
            try {
                // The nested transaction is a savepoint: a unique violation must not poison the outer one.
                DB::transaction(fn () => DB::table('ai_bulk_runs')->insert([
                    'id' => $runId, 'started_by' => $admin->id, 'scope' => $scope, 'daily_cap' => (int) $row->daily_cap,
                    'status' => 'active', 'dry_run_id' => $token, 'eligible_total' => (int) $row->eligible,
                    'skipped_count' => 0, 'created_at' => $now, 'updated_at' => $now,
                ]));
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? null) === '23505') {
                    return 'active';
                }
                throw $e;
            }

            DB::table('ai_bulk_dry_runs')->where('id', $token)->update(['consumed_at' => $now]);
            // Exactly the set the dialog named (verified above under the lock). Bulk attempts use only this.
            $providers = $enabled;
            DB::table('ai_notice_acks')->upsert(
                [['user_id' => $admin->id, 'acknowledged_at' => $now, 'providers' => implode(',', $providers)]],
                ['user_id'],
                ['acknowledged_at', 'providers'],
            );
            $this->audit->record(AuditEvent::AiBulkRunChanged, $admin, ['action' => 'started', 'scope' => $scope, 'count' => (int) $row->eligible]);

            return 'ok';
        });

        if ($result === 'providers_changed') {
            return ['result' => $result, 'providers' => array_values($current)];
        }

        return $result === 'ok' || $result === 'active'
            ? ['result' => $result, 'panel' => $this->panel()]
            : ['result' => $result];
    }

    /** @return array{result: string, panel?: array<string, mixed>} result is ok, conflict or missing */
    public function pause(User $admin, string $runId): array
    {
        return $this->flip($admin, $runId, 'active', 'paused', 'paused');
    }

    /** @return array{result: string, panel?: array<string, mixed>} result is ok, conflict or missing */
    public function resume(User $admin, string $runId): array
    {
        return $this->flip($admin, $runId, 'paused', 'active', 'resumed');
    }

    /** @return array{result: string, panel?: array<string, mixed>} result is ok, conflict or missing */
    public function cancel(User $admin, string $runId): array
    {
        if (! Str::isUuid($runId)) {
            return ['result' => 'missing'];
        }
        $result = DB::transaction(function () use ($admin, $runId) {
            $run = DB::table('ai_bulk_runs')->where('id', $runId)->lockForUpdate()->first(['status', 'scope']);
            if ($run === null) {
                return 'missing';
            }
            if (! in_array($run->status, ['active', 'paused'], true)) {
                return 'conflict';
            }
            $now = Carbon::now();
            DB::table('ai_bulk_runs')->where('id', $runId)->update(['status' => 'cancelled', 'finished_at' => $now, 'updated_at' => $now]);
            $stopped = $this->jobs->cancelRunExtractions($runId, $admin->id);
            $this->audit->record(AuditEvent::AiBulkRunChanged, $admin, ['action' => 'cancelled', 'scope' => (string) $run->scope, 'count' => $stopped]);

            return 'ok';
        });

        return $result === 'ok' ? ['result' => 'ok', 'panel' => $this->panel()] : ['result' => $result];
    }

    /**
     * The open run (if any) and the last runs, as integers, enums and times.
     *
     * @return array{active: ?array<string, mixed>, history: list<array<string, mixed>>}
     */
    public function panel(): array
    {
        $now = CarbonImmutable::now();
        $runs = DB::table('ai_bulk_runs')->orderByDesc('created_at')->orderByDesc('id')
            ->limit(max(1, (int) config('ai.bulk.history')))->get();
        $open = DB::table('ai_bulk_runs')->whereIn('status', ['active', 'paused'])->first();
        $ids = $runs->pluck('id')->all();
        if ($open !== null && ! in_array($open->id, $ids, true)) {
            $ids[] = $open->id;
        }
        $tally = $this->tally($ids);

        $history = [];
        foreach ($runs as $run) {
            $t = $tally[$run->id];
            $history[] = [
                'startedAt' => CarbonImmutable::parse($run->created_at)->toIso8601String(),
                'scope' => (string) $run->scope,
                'dailyCap' => (int) $run->daily_cap,
                'wouldRead' => (int) $run->eligible_total,
                'read' => $t['read'],
                'failed' => $t['failed'],
                'skipped' => $t['skipped'],
                'status' => (string) $run->status,
            ];
        }

        return ['active' => $open === null ? null : $this->activeBlock($open, $tally[$open->id], $now), 'history' => $history];
    }

    /** @return array{result: string, panel?: array<string, mixed>} */
    private function flip(User $admin, string $runId, string $from, string $to, string $action): array
    {
        if (! Str::isUuid($runId)) {
            return ['result' => 'missing'];
        }
        $result = DB::transaction(function () use ($admin, $runId, $from, $to, $action) {
            $run = DB::table('ai_bulk_runs')->where('id', $runId)->lockForUpdate()->first(['status', 'scope']);
            if ($run === null) {
                return 'missing';
            }
            if ($run->status !== $from) {
                return 'conflict';
            }
            $handled = (int) DB::table('ai_bulk_run_items')->where('bulk_run_id', $runId)->whereNotNull('outcome')->count();
            $this->audit->record(AuditEvent::AiBulkRunChanged, $admin, ['action' => $action, 'scope' => (string) $run->scope, 'count' => $handled]);
            $now = Carbon::now();
            DB::table('ai_bulk_runs')->where('id', $runId)->update([
                'status' => $to, 'paused_at' => $to === 'paused' ? $now : null, 'updated_at' => $now,
            ]);

            return 'ok';
        });

        return $result === 'ok' ? ['result' => 'ok', 'panel' => $this->panel()] : ['result' => $result];
    }

    /**
     * One grouped query: each item counted once by its outcome; an open item counts as waiting (its extraction waits)
     * or in flight.
     *
     * @param  list<string>  $ids
     * @return array<string, array{read: int, failed: int, skipped: int, cancelled: int, gone: int, waiting: int, inflight: int, items: int, availableAt: ?string}>
     */
    private function tally(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['read' => 0, 'failed' => 0, 'skipped' => 0, 'cancelled' => 0, 'gone' => 0, 'waiting' => 0, 'inflight' => 0, 'items' => 0, 'availableAt' => null];
        }
        if ($ids === []) {
            return $out;
        }
        $rows = DB::table('ai_bulk_run_items as i')
            ->leftJoin('medical_ai_extractions as e', 'e.id', '=', 'i.extraction_id')
            ->whereIn('i.bulk_run_id', $ids)
            ->groupBy('i.bulk_run_id', 'i.outcome', 'e.status')
            ->selectRaw('i.bulk_run_id as run, i.outcome as outcome, e.status as ext, count(*) as n, min(e.available_at) as available')
            ->get();
        foreach ($rows as $r) {
            $t = &$out[$r->run];
            $n = (int) $r->n;
            $t['items'] += $n;
            if ($r->outcome !== null) {
                $t[$r->outcome] += $n;

                continue;
            }
            if ($r->ext === 'waiting') {
                $t['waiting'] += $n;
                $at = $r->available === null ? null : CarbonImmutable::parse($r->available)->toIso8601String();
                if ($at !== null && ($t['availableAt'] === null || $at < $t['availableAt'])) {
                    $t['availableAt'] = $at;
                }
            } else {
                $t['inflight'] += $n;
            }
            unset($t);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $t
     * @return array<string, mixed>
     */
    private function activeBlock(object $run, array $t, CarbonImmutable $now): array
    {
        $tz = (string) config('ai.local_timezone');
        $local = $now->setTimezone($tz);
        $startHour = (int) config('ai.bulk.start_hour');
        $todayUsed = (int) DB::table('ai_bulk_run_items')->where('bulk_run_id', $run->id)
            ->where('enqueued_at', '>=', $local->startOfDay()->utc())->count();
        $cap = (int) $run->daily_cap;
        $beforeStart = $local->hour < $startHour;

        $state = 'running';
        $nextBatch = null;
        if ($run->status === 'paused') {
            $state = 'paused';
        } elseif ($run->blocked_since !== null
            && CarbonImmutable::parse($run->blocked_since)->addHours((int) config('ai.bulk.stopped_after_hours'))->lte($now)) {
            $state = 'stopped';
        } elseif ($t['waiting'] > 0) {
            $state = 'waiting_quota';
        } elseif ($beforeStart || $todayUsed >= $cap) {
            $state = 'next_batch';
            $next = $local->startOfDay()->addHours($startHour);
            $nextBatch = ($beforeStart ? $next : $next->addDay())->utc()->toIso8601String();
        }

        $done = $t['read'] + $t['failed'] + $t['skipped'] + $t['cancelled'] + $t['gone'];

        return [
            'id' => (string) $run->id,
            'status' => (string) $run->status,
            'state' => $state,
            'scope' => (string) $run->scope,
            'dailyCap' => $cap,
            'total' => (int) $run->eligible_total,
            'done' => $done,
            'read' => $t['read'],
            'failed' => $t['failed'],
            'skipped' => $t['skipped'],
            'waiting' => $t['waiting'],
            'left' => max(0, (int) $run->eligible_total - $t['items']),
            'todayUsed' => $todayUsed,
            'nextBatchAfter' => $nextBatch,
            'availableAt' => $t['availableAt'],
        ];
    }
}
