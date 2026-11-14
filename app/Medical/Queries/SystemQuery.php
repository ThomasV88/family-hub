<?php

namespace App\Medical\Queries;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Unscoped by design: runs without a viewer (Phase 8 worker, scheduler, outbox). Returns ids, dates or booleans only,
 * never names or record content. Every method has its own test.
 */
class SystemQuery
{
    /** True only when the subject exists and its consent flag is on; false for a non-uuid (no SQL) or a missing row. */
    public function aiConsentFor(string $subjectId): bool
    {
        if (! Str::isUuid($subjectId)) {
            return false;
        }

        return (bool) DB::table('medical_subjects')->where('id', $subjectId)->value('ai_consent');
    }
}
