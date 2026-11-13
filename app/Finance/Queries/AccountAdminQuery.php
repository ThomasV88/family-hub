<?php

namespace App\Finance\Queries;

use App\Finance\Viewer;
use App\Finance\Visibility;
use App\Models\FinanceAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Account metadata for the admin settings screen. Reads of metadata (name, type, currency, members) are not
 * membership-scoped by design (an admin manages who sees what), but everything money-shaped (opening balance,
 * transaction existence and dates) takes a Viewer and goes through Visibility, so an admin only ever gets money
 * facts for accounts they were ticked on.
 */
class AccountAdminQuery
{
    /** @return list<array{id: string, name: string, type: string, currency: string, memberIds: list<int>}> */
    public function metadata(): array
    {
        $members = [];
        foreach (DB::table('finance_account_members')->orderBy('user_id')->get(['account_id', 'user_id']) as $row) {
            $members[(string) $row->account_id][] = (int) $row->user_id;
        }

        return DB::table('finance_accounts')
            ->orderByRaw('lower(name)')
            ->orderBy('id')
            ->get(['id', 'name', 'type', 'currency'])
            ->map(fn ($a) => [
                'id' => (string) $a->id,
                'name' => (string) $a->name,
                'type' => (string) $a->type,
                'currency' => (string) $a->currency,
                'memberIds' => $members[(string) $a->id] ?? [],
            ])
            ->all();
    }

    /** The account model for metadata edits only; never read money fields off it for an unticked admin. */
    public function find(string $id): ?FinanceAccount
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        return FinanceAccount::query()->find($id, ['id', 'name', 'type', 'currency']);
    }

    /** @return list<int> */
    public function memberIds(string $accountId): array
    {
        return DB::table('finance_account_members')
            ->where('account_id', $accountId)
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function isMember(string $accountId, int $userId): bool
    {
        return DB::table('finance_account_members')
            ->where('account_id', $accountId)
            ->where('user_id', $userId)
            ->exists();
    }

    /** @return array{minor: int, asOf: string}|null null unless the viewer is a member of the account */
    public function opening(Viewer $viewer, string $accountId): ?array
    {
        $row = Visibility::apply(DB::table('finance_accounts')->where('id', $accountId), $viewer, 'id')
            ->first(['opening_balance_minor', 'balance_as_of']);

        return $row === null ? null : [
            'minor' => (int) $row->opening_balance_minor,
            'asOf' => substr((string) $row->balance_as_of, 0, 10),
        ];
    }

    public function hasTransactions(Viewer $viewer, string $accountId): bool
    {
        return Visibility::apply(DB::table('finance_transactions')->where('account_id', $accountId), $viewer)->exists();
    }

    public function earliestBookedOn(Viewer $viewer, string $accountId): ?string
    {
        $min = Visibility::apply(DB::table('finance_transactions')->where('account_id', $accountId), $viewer)->min('booked_on');

        return $min === null ? null : substr((string) $min, 0, 10);
    }

    /** @param  array{name: string, type: string, currency: string, opening_balance_minor: int, balance_as_of: string}  $attributes */
    public function create(array $attributes, int $createdBy): FinanceAccount
    {
        $account = new FinanceAccount;
        $account->forceFill($attributes + ['created_by' => $createdBy])->save();

        return $account;
    }

    /** @param  array<string, mixed>  $attributes */
    public function change(string $accountId, array $attributes): void
    {
        $account = FinanceAccount::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
        $account->forceFill($attributes)->save();
    }

    /**
     * Makes the member set exactly $userIds. Rows that stay are left alone.
     *
     * @param  list<int>  $userIds
     * @return array{added: list<int>, removed: list<int>}
     */
    public function syncMembers(string $accountId, array $userIds): array
    {
        $existing = $this->memberIds($accountId);
        $added = array_values(array_diff($userIds, $existing));
        $removed = array_values(array_diff($existing, $userIds));

        if ($removed !== []) {
            DB::table('finance_account_members')->where('account_id', $accountId)->whereIn('user_id', $removed)->delete();
        }
        if ($added !== []) {
            $now = now();
            DB::table('finance_account_members')->insert(array_map(
                fn (int $id) => ['account_id' => $accountId, 'user_id' => $id, 'created_at' => $now],
                $added,
            ));
        }

        return ['added' => $added, 'removed' => $removed];
    }
}
