<?php

namespace App\Medical;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Keeps medical content out of laravel.log. A QueryException on a medical_* table carries the full SQL with bound
 * values (diagnosis, notes, titles), and the Postgres driver message adds "Failing row contains (...)". Such an
 * exception is logged here without bindings and without the DETAIL line, and the default report is skipped.
 */
class ScrubbedQueryReport
{
    /** True when the exception was handled here (the caller then stops the default report). */
    public static function handle(QueryException $e): bool
    {
        if (! str_contains($e->getSql(), 'medical_') && ! str_contains($e->getMessage(), 'medical_')) {
            return false;
        }

        $driverMessage = $e->getPrevious()?->getMessage() ?? '';
        // First line only: the DETAIL / CONTEXT lines quote the row.
        $firstLine = trim(strtok($driverMessage, "\n") ?: 'database error');

        Log::error('Database error on a medical table (bindings and row detail withheld): '.mb_substr($firstLine, 0, 300), [
            'sqlstate' => (string) $e->getCode(),
            'exception' => $e::class,
        ]);

        return true;
    }
}
