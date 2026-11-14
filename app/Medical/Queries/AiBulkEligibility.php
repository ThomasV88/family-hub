<?php

namespace App\Medical\Queries;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Counts and candidate selection for bulk re-generation. Selects no names, titles or content.
 *
 * Buckets are exclusive and the first match wins: unsupported file type, consent off, already decided by a human,
 * already active; everything else is eligible.
 */
final class AiBulkEligibility
{
    public const SCOPES = ['no_proposal', 'last_failed', 'all'];

    /** Failures after which the CLI really ran and used quota; they start the per-document cool-down like a ready run. */
    private const QUOTA_FAILURES = ['timeout', 'idle_timeout', 'no_output', 'invalid_output', 'output_too_large', 'cli_error', 'auth'];

    /** @return array{eligible: int, skipped_consent: int, skipped_unsupported: int, skipped_decided: int, skipped_active: int} */
    public function counts(string $scope): array
    {
        $row = DB::selectOne(
            "SELECT count(*) FILTER (WHERE bucket = 'eligible') AS eligible,
                    count(*) FILTER (WHERE bucket = 'consent') AS skipped_consent,
                    count(*) FILTER (WHERE bucket = 'unsupported') AS skipped_unsupported,
                    count(*) FILTER (WHERE bucket = 'decided') AS skipped_decided,
                    count(*) FILTER (WHERE bucket = 'active') AS skipped_active
             FROM (SELECT {$this->bucket()} AS bucket
                   FROM medical_documents d JOIN medical_subjects s ON s.id = d.subject_id
                   WHERE {$this->scopePredicate($scope)}) t"
        );

        return [
            'eligible' => (int) $row->eligible,
            'skipped_consent' => (int) $row->skipped_consent,
            'skipped_unsupported' => (int) $row->skipped_unsupported,
            'skipped_decided' => (int) $row->skipped_decided,
            'skipped_active' => (int) $row->skipped_active,
        ];
    }

    /**
     * The next eligible document that is not yet an item of the run and is outside the per-document cool-down. Ids only.
     *
     * @return array{document_id: string, subject_id: string}|null
     */
    public function nextCandidate(string $scope, string $runId): ?array
    {
        $cooldown = (int) config('ai.limits.document_cooldown_minutes');
        $bindings = [$runId];
        $coolSql = '';
        if ($cooldown > 0) {
            $quota = "'".implode("','", self::QUOTA_FAILURES)."'";
            $coolSql = "AND NOT EXISTS (SELECT 1 FROM medical_ai_extractions c WHERE c.document_id = d.id AND c.finished_at > ?
                          AND (c.status IN ('ready', 'superseded') OR (c.status = 'failed' AND c.failure_code IN ({$quota}))))";
            $bindings[] = Carbon::now()->subMinutes($cooldown);
        }

        $row = DB::selectOne(
            "SELECT t.document_id, t.subject_id
             FROM (SELECT d.id AS document_id, d.subject_id, d.created_at, {$this->bucket()} AS bucket
                   FROM medical_documents d JOIN medical_subjects s ON s.id = d.subject_id
                   WHERE {$this->scopePredicate($scope)}
                     AND NOT EXISTS (SELECT 1 FROM ai_bulk_run_items i WHERE i.bulk_run_id = ? AND i.document_id = d.id)
                     {$coolSql}) t
             WHERE t.bucket = 'eligible'
             ORDER BY t.created_at, t.document_id
             LIMIT 1",
            $bindings
        );

        return $row === null ? null : ['document_id' => (string) $row->document_id, 'subject_id' => (string) $row->subject_id];
    }

    private function bucket(): string
    {
        return "CASE
            WHEN d.kind NOT IN ('pdf', 'image') THEN 'unsupported'
            WHEN NOT s.ai_consent THEN 'consent'
            WHEN EXISTS (SELECT 1 FROM medical_ai_extractions e JOIN medical_ai_decisions x ON x.extraction_id = e.id
                         WHERE e.document_id = d.id AND x.decision = 'accepted') THEN 'decided'
            WHEN EXISTS (SELECT 1 FROM medical_ai_extractions a WHERE a.document_id = d.id AND a.status IN ('queued', 'running', 'waiting')) THEN 'active'
            ELSE 'eligible' END";
    }

    private function scopePredicate(string $scope): string
    {
        return match ($scope) {
            'no_proposal' => "NOT EXISTS (SELECT 1 FROM medical_ai_extractions p WHERE p.document_id = d.id AND p.status IN ('ready', 'superseded'))",
            'last_failed' => "(SELECT l.status FROM medical_ai_extractions l WHERE l.document_id = d.id AND l.status <> 'superseded' ORDER BY l.queued_at DESC, l.id DESC LIMIT 1) = 'failed'",
            'all' => 'TRUE',
            default => throw new InvalidArgumentException('Unknown bulk scope.'),
        };
    }
}
