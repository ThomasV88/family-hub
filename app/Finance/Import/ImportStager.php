<?php

namespace App\Finance\Import;

use App\Finance\Currency;
use App\Finance\Queries\ImportQuery;
use App\Finance\Viewer;
use App\Models\FinanceAccount;
use Illuminate\Support\Facades\DB;

/**
 * Decode, parse, normalise, hash and dedupe an uploaded statement into a staged draft batch. Works on the bytes in
 * memory only: the raw file is never stored, and nothing here logs.
 */
class ImportStager
{
    public function __construct(private readonly ImportQuery $imports) {}

    /**
     * @param  bool  $strictNames  a saved profile must find every named required column in the header row
     * @return string the draft batch id
     *
     * @throws ImportError
     */
    public function stage(Viewer $viewer, FinanceAccount $account, string $bytes, ProfileSpec $spec, bool $strictNames = false): string
    {
        $maxRows = (int) config('finance.import.max_rows');
        $decoded = Decoder::decode($bytes, $spec->encoding);
        $records = CsvReader::rows($decoded->text, $spec->delimiter, $maxRows);

        if ($strictNames) {
            $this->assertNamesFit($records, $spec);
        }

        $rows = RowNormalizer::normalize(
            $records, $spec, Currency::from((string) $account->currency), $account->balance_as_of->format('Y-m-d')
        );
        if ($rows === []) {
            throw new ImportError('empty');
        }
        if (count($rows) > $maxRows) {
            throw new ImportError('too_many_rows');
        }

        $accountId = (string) $account->id;
        $rows = ImportHasher::apply($accountId, $rows);

        $hashes = [];
        foreach ($rows as $row) {
            if ($row->importHash !== null) {
                $hashes[] = $row->importHash;
            }
        }
        $existing = $hashes === [] ? [] : $this->imports->existingHashes($viewer, $accountId, $hashes);

        $staged = [];
        $probableCandidates = [];
        foreach ($rows as $row) {
            $state = $row->state === 'ok' ? 'new' : 'invalid';
            if ($state === 'new' && isset($existing[(string) $row->importHash])) {
                $state = 'duplicate';
            }
            $staged[$row->rowNo] = [
                'row_no' => $row->rowNo,
                'booked_on' => $row->bookedOn,
                'amount_minor' => $row->amountMinor,
                'kind' => $row->kind,
                'description' => $row->description,
                'counterparty' => $row->counterparty,
                'reference' => $row->reference,
                'import_hash' => $row->importHash,
                'state' => $state,
                'error_code' => $row->errorCode,
                'include' => $state === 'new',
                'probable_transaction_id' => null,
            ];
            if ($state === 'new') {
                $probableCandidates[] = $row;
            }
        }

        return DB::transaction(function () use ($viewer, $accountId, $spec, $staged, $probableCandidates) {
            $staged = $this->flagProbable($viewer, $accountId, $staged, $probableCandidates);
            $this->imports->deleteDraftsFor($viewer, $accountId);

            return $this->imports->insertBatchWithRows($viewer, $accountId, $spec->toArray(), array_values($staged));
        });
    }

    /**
     * Greedy matching: smallest date distance first, then file order. A hand-entered transaction is claimed by one row.
     *
     * @param  array<int, array<string, mixed>>  $staged
     * @param  list<NormalizedRow>  $candidates
     * @return array<int, array<string, mixed>>
     */
    private function flagProbable(Viewer $viewer, string $accountId, array $staged, array $candidates): array
    {
        if ($candidates === []) {
            return $staged;
        }
        $window = (int) config('finance.import.probable_window_days');
        $dates = array_map(fn (NormalizedRow $r) => (string) $r->bookedOn, $candidates);
        $from = date('Y-m-d', strtotime(min($dates).' -'.$window.' days'));
        $to = date('Y-m-d', strtotime(max($dates).' +'.$window.' days'));

        $manual = $this->imports->manualCandidates($viewer, $accountId, $from, $to);
        if ($manual === []) {
            return $staged;
        }

        $pairs = [];
        foreach ($candidates as $row) {
            $rowDay = intdiv(strtotime((string) $row->bookedOn.' UTC'), 86400);
            foreach ($manual as $tx) {
                if ((int) $tx->amount_minor !== $row->amountMinor) {
                    continue;
                }
                $distance = abs($rowDay - intdiv(strtotime((string) $tx->booked_on.' UTC'), 86400));
                if ($distance <= $window) {
                    $pairs[] = [$distance, $row->rowNo, (string) $tx->id];
                }
            }
        }
        usort($pairs, fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $claimedRows = [];
        $claimedTx = [];
        foreach ($pairs as [, $rowNo, $txId]) {
            if (isset($claimedRows[$rowNo]) || isset($claimedTx[$txId])) {
                continue;
            }
            $claimedRows[$rowNo] = true;
            $claimedTx[$txId] = true;
            $staged[$rowNo]['state'] = 'probable_duplicate';
            $staged[$rowNo]['include'] = false;
            $staged[$rowNo]['probable_transaction_id'] = $txId;
        }

        return $staged;
    }

    /** @param  list<list<string>>  $records */
    private function assertNamesFit(array $records, ProfileSpec $spec): void
    {
        $headers = array_map('mb_strtolower', RowNormalizer::headers($records, $spec));
        foreach ($spec->columns as $col) {
            if ($col['name'] !== null && $spec->headerRow > 0 && ! in_array(mb_strtolower(trim($col['name'])), $headers, true)) {
                throw new ImportError('profile_mismatch');
            }
        }
    }
}
