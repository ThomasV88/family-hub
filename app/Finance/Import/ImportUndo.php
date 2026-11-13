<?php

namespace App\Finance\Import;

use App\Finance\Queries\ImportQuery;
use App\Finance\Viewer;
use Illuminate\Support\Facades\DB;

/**
 * Undo of a committed batch: transactions nobody edited are deleted (their recurring matches go with them), the rest
 * stay as ordinary transactions, and hand-entered rows the import had reconciled get their freedom back.
 */
class ImportUndo
{
    public function __construct(private readonly ImportQuery $imports) {}

    /** @return array{deleted: int, kept: int}|null null when the batch is not a committed one the viewer can see */
    public function undo(Viewer $viewer, string $batchId): ?array
    {
        return DB::transaction(function () use ($viewer, $batchId): ?array {
            if ($this->imports->lockBatch($viewer, $batchId, 'committed') === null) {
                return null;
            }

            $deleted = $this->imports->deletePristine($viewer, $batchId);
            $kept = $this->imports->detachRemaining($viewer, $batchId);
            $this->imports->revertReconciled($viewer, $batchId);
            $this->imports->markUndone($viewer, $batchId, $kept);

            return ['deleted' => $deleted, 'kept' => $kept];
        });
    }
}
