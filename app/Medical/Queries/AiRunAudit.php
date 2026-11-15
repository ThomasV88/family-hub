<?php

namespace App\Medical\Queries;

/**
 * Audit meta for AI run events. A bulk row carries no person or document id: the admin audit page lists these rows, and an
 * admin who started a bulk run may not be a member of the people it reads. Run id and counts live on AiBulkRunChanged.
 */
final class AiRunAudit
{
    /** @return array<string, scalar> */
    public static function meta(string $subjectId, string $documentId, ?string $bulkRunId, ?string $status = null): array
    {
        $meta = $bulkRunId === null ? ['subject' => $subjectId, 'document' => $documentId] : ['bulk' => true];
        if ($status !== null) {
            $meta['status'] = $status;
        }

        return $meta;
    }
}
