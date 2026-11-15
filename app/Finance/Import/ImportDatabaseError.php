<?php

namespace App\Finance\Import;

use RuntimeException;

/**
 * Replaces a QueryException raised by an import statement. Laravel embeds the bindings (the whole JSON payload of the
 * staged CSV rows) in a QueryException message, so the original is never chained or reported; only the SQLSTATE
 * survives.
 */
final class ImportDatabaseError extends RuntimeException
{
    public function __construct(public readonly ?string $sqlState)
    {
        parent::__construct('Import database statement failed (SQLSTATE '.($sqlState ?? 'unknown').').');
    }
}
