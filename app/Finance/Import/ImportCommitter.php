<?php

namespace App\Finance\Import;

use App\Finance\Queries\ImportQuery;
use App\Finance\Recurring\RecurringMatchService;
use App\Finance\Viewer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns the ticked rows of a draft batch into transactions in one database transaction. A second commit of the same
 * batch (double click, second tab) finds it no longer a draft and changes nothing; the unique import hash backs this up.
 */
class ImportCommitter
{
    public function __construct(
        private readonly ImportQuery $imports,
        private readonly RecurringMatchService $matching,
    ) {}

    /**
     * @param  list<string>  $include  staged row ids ticked by the member
     * @param  array<string, string>  $categories  staged row id => category id, already checked against the row's kind
     * @return bool false when the batch was no longer a draft (nothing was changed)
     */
    public function commit(Viewer $viewer, string $batchId, array $include, array $categories): bool
    {
        return DB::transaction(function () use ($viewer, $batchId, $include, $categories): bool {
            $batch = $this->imports->lockBatch($viewer, $batchId, 'draft');
            if ($batch === null) {
                return false;
            }

            $ticked = array_flip($include);
            $picked = [];
            $reconcile = [];
            foreach ($this->imports->stagedRows($viewer, $batchId) as $row) {
                $id = (string) $row->id;
                if (isset($ticked[$id])) {
                    if ($row->state === 'invalid' || $row->import_hash === null) {
                        continue;
                    }
                    $hash = (string) $row->import_hash;
                    $picked[] = [
                        'tx_id' => (string) Str::uuid7(),
                        'row_id' => $id,
                        'import_hash' => $row->state === 'duplicate' ? hash('sha256', $hash.'|'.$batchId) : $hash,
                        'category_id' => $categories[$id] ?? null,
                    ];
                } elseif ($row->state === 'probable_duplicate' && $row->probable_transaction_id !== null && $row->import_hash !== null) {
                    $reconcile[] = [(string) $row->probable_transaction_id, (string) $row->import_hash];
                }
            }

            $inserted = $this->imports->commitRows($viewer, $batchId, $picked);
            foreach ($reconcile as [$transactionId, $hash]) {
                $this->imports->reconcile($viewer, (string) $batch->account_id, $batchId, $transactionId, $hash);
            }

            $matched = $inserted === [] ? 0 : $this->matching->matchTransactions($viewer, $inserted);
            $this->imports->markCommitted($viewer, $batchId, count($inserted), $matched);
            $this->imports->deleteStagedRows($viewer, $batchId);

            return true;
        });
    }
}
