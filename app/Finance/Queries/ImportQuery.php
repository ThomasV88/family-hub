<?php

namespace App\Finance\Queries;

use App\Finance\Viewer;
use App\Finance\Visibility;
use App\Models\FinanceImportProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Scoped reads and writes of the import staging tables. Every statement on account-owned rows carries the membership
 * predicate, and dedupe lookups run only against the one account the viewer selected.
 */
class ImportQuery
{
    private const INSERT_CHUNK = 500;

    /**
     * Import hashes already booked on the account.
     *
     * @param  list<string>  $hashes
     * @return array<string, true>
     */
    public function existingHashes(Viewer $viewer, string $accountId, array $hashes): array
    {
        $found = [];
        foreach (array_chunk(array_values(array_unique($hashes)), 1000) as $chunk) {
            $query = Visibility::apply(DB::table('finance_transactions'), $viewer)
                ->where('account_id', $accountId)
                ->whereIn('import_hash', $chunk);
            foreach ($query->pluck('import_hash') as $hash) {
                $found[(string) $hash] = true;
            }
        }

        return $found;
    }

    /**
     * Hand-entered transactions of the account in a date range that no import has reconciled yet.
     *
     * @return list<object{id: string, booked_on: string, amount_minor: int|string}>
     */
    public function manualCandidates(Viewer $viewer, string $accountId, string $fromDate, string $toDate): array
    {
        return Visibility::apply(DB::table('finance_transactions'), $viewer)
            ->where('account_id', $accountId)
            ->where('source', 'manual')
            ->whereNull('import_hash')
            ->whereNull('reconciled_batch_id')
            ->whereBetween('booked_on', [$fromDate, $toDate])
            ->orderBy('booked_on')
            ->orderBy('id')
            ->select(['id', 'booked_on', 'amount_minor'])
            ->get()
            ->all();
    }

    /** Removes the viewer's earlier drafts for the account (their rows cascade). */
    public function deleteDraftsFor(Viewer $viewer, string $accountId): int
    {
        return Visibility::apply(
            DB::table('finance_import_batches')
                ->where('user_id', $viewer->userId())
                ->where('account_id', $accountId)
                ->where('status', 'draft'),
            $viewer
        )->delete();
    }

    /**
     * Creates a draft batch and its rows in bulk. Both statements are conditional on the viewer being a member of the
     * account, so nothing is written for a hidden account.
     *
     * @param  array<string, mixed>  $profileSnapshot
     * @param  list<array<string, mixed>>  $rows  keys: row_no, booked_on, amount_minor, kind, description, counterparty, reference, import_hash, state, error_code, include, probable_transaction_id
     */
    public function insertBatchWithRows(Viewer $viewer, string $accountId, array $profileSnapshot, array $rows): string
    {
        $batchId = (string) Str::uuid7();
        $now = now();

        $created = DB::affectingStatement(
            'insert into finance_import_batches (id, account_id, user_id, status, profile_snapshot, row_count, added_count, matched_count, created_at, updated_at) '
            ."select ?::uuid, ?::uuid, ?::bigint, 'draft', ?::jsonb, ?::int, 0, 0, ?::timestamptz, ?::timestamptz "
            .'where exists (select 1 from finance_account_members where user_id = ? and account_id = ?)',
            [$batchId, $accountId, $viewer->userId(), json_encode($profileSnapshot, JSON_THROW_ON_ERROR), count($rows), $now, $now, $viewer->userId(), $accountId]
        );
        abort_if($created === 0, 404);

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            $payload = array_map(fn (array $r) => ['id' => (string) Str::uuid7()] + $r, $chunk);
            DB::affectingStatement(
                'insert into finance_import_rows (id, batch_id, row_no, booked_on, amount_minor, kind, description, counterparty, reference, import_hash, state, error_code, include, probable_transaction_id, created_at) '
                .'select x.id, ?::uuid, x.row_no, x.booked_on, x.amount_minor, x.kind, x.description, x.counterparty, x.reference, x.import_hash, x.state, x.error_code, x.include, x.probable_transaction_id, ?::timestamptz '
                .'from jsonb_to_recordset(?::jsonb) as x(id uuid, row_no int, booked_on date, amount_minor bigint, kind text, description text, counterparty text, reference text, import_hash text, state text, error_code text, include boolean, probable_transaction_id uuid) '
                .'where exists (select 1 from finance_import_batches b where b.id = ?::uuid and b.user_id = ? '
                .'and b.account_id in (select account_id from finance_account_members where user_id = ?))',
                [$batchId, $now, json_encode($payload, JSON_THROW_ON_ERROR), $batchId, $viewer->userId(), $viewer->userId()]
            );
        }

        return $batchId;
    }

    /** The batch with its account name and currency. A draft is visible only to its creator; null otherwise. */
    public function findBatchForViewer(Viewer $viewer, string $id): ?object
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        return Visibility::apply(
            DB::table('finance_import_batches as b')
                ->join('finance_accounts as a', 'a.id', '=', 'b.account_id')
                ->where('b.id', $id)
                ->where(fn ($q) => $q->where('b.status', '<>', 'draft')->orWhere('b.user_id', $viewer->userId())),
            $viewer,
            'b.account_id'
        )->select(['b.id', 'b.account_id', 'b.user_id', 'b.status', 'b.row_count', 'b.added_count', 'b.matched_count', 'b.kept_count',
            'b.created_at', 'a.name as account_name', 'a.currency as account_currency'])->first();
    }

    /** @return array{total: int, new: int, duplicate: int, probable: int, invalid: int} */
    public function counts(Viewer $viewer, string $batchId): array
    {
        $rows = Visibility::apply(
            DB::table('finance_import_rows as r')
                ->join('finance_import_batches as b', 'b.id', '=', 'r.batch_id')
                ->where('r.batch_id', $batchId),
            $viewer,
            'b.account_id'
        )->groupBy('r.state')->selectRaw('r.state as state, count(*) as n')->get();

        $by = [];
        foreach ($rows as $row) {
            $by[(string) $row->state] = (int) $row->n;
        }

        return [
            'total' => array_sum($by),
            'new' => $by['new'] ?? 0,
            'duplicate' => $by['duplicate'] ?? 0,
            'probable' => $by['probable_duplicate'] ?? 0,
            'invalid' => $by['invalid'] ?? 0,
        ];
    }

    /** @return list<object> staged rows in file order, with the probable hand-entered transaction when flagged */
    public function batchRows(Viewer $viewer, string $batchId): array
    {
        return Visibility::apply(
            DB::table('finance_import_rows as r')
                ->join('finance_import_batches as b', 'b.id', '=', 'r.batch_id')
                ->leftJoin('finance_transactions as t', 't.id', '=', 'r.probable_transaction_id')
                ->where('r.batch_id', $batchId),
            $viewer,
            'b.account_id'
        )->orderBy('r.row_no')->orderBy('r.id')->select([
            'r.id', 'r.row_no', 'r.booked_on', 'r.description', 'r.counterparty', 'r.amount_minor', 'r.kind', 'r.state',
            'r.error_code', 'r.include', 'r.probable_transaction_id',
            't.description as probable_description', 't.booked_on as probable_booked_on', 't.amount_minor as probable_amount_minor',
        ])->get()->all();
    }

    /** @return list<object> committed and undone batches on visible accounts, any creator, newest first */
    public function history(Viewer $viewer, int $limit = 20): array
    {
        return Visibility::apply(
            DB::table('finance_import_batches as b')
                ->join('finance_accounts as a', 'a.id', '=', 'b.account_id')
                ->whereIn('b.status', ['committed', 'undone']),
            $viewer,
            'b.account_id'
        )->orderByDesc('b.created_at')->orderByDesc('b.id')->limit($limit)
            ->select(['b.id', 'b.created_at', 'b.account_id', 'a.name as account_name', 'b.added_count', 'b.status'])
            ->get()->all();
    }

    /** @return list<FinanceImportProfile> family-wide saved profiles, by name */
    public function profiles(): array
    {
        return FinanceImportProfile::query()->orderByRaw('lower(name)')->orderBy('id')->get()->all();
    }

    public function findProfile(string $id): ?FinanceImportProfile
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        return FinanceImportProfile::query()->find($id);
    }

    public function profileNameTaken(string $name): bool
    {
        return FinanceImportProfile::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists();
    }

    /** @param  array<string, mixed>  $settings */
    public function createProfile(string $name, array $settings, int $userId): FinanceImportProfile
    {
        $profile = new FinanceImportProfile;
        $profile->forceFill(['name' => $name, 'settings' => $settings, 'created_by' => $userId])->save();

        return $profile;
    }
}
