<?php

namespace App\Finance\Accounts;

use App\Access\Module;
use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Finance\Queries\AccountAdminQuery;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Admin-side account writes. Every member change is audited as its own content-free entry. */
class AccountAdmin
{
    public function __construct(private readonly AccountAdminQuery $accounts, private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, type: string, currency: string, opening_balance_minor: int, balance_as_of: string}  $attributes
     * @param  list<int>  $memberIds
     */
    public function create(User $admin, array $attributes, array $memberIds): string
    {
        return DB::transaction(function () use ($admin, $attributes, $memberIds) {
            $account = $this->accounts->create($attributes, $admin->id);
            $this->syncMembers($admin, (string) $account->id, $memberIds);

            return (string) $account->id;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $memberIds
     */
    public function update(User $admin, string $accountId, array $attributes, array $memberIds): void
    {
        DB::transaction(function () use ($admin, $accountId, $attributes, $memberIds) {
            $this->accounts->change($accountId, $attributes);
            $this->syncMembers($admin, $accountId, $memberIds);
        });
    }

    /** @param  list<int>  $memberIds */
    private function syncMembers(User $admin, string $accountId, array $memberIds): void
    {
        $diff = $this->accounts->syncMembers($accountId, $memberIds);

        foreach (['added' => $diff['added'], 'removed' => $diff['removed']] as $change => $ids) {
            foreach ($ids as $id) {
                $member = User::query()->find($id);
                if ($member === null) {
                    continue;
                }
                // The account's opaque public id is the only account reference an audit row may carry.
                if ($change === 'added' && $member->id === $admin->id) {
                    $this->audit->record(AuditEvent::FinanceAdminSelfAdded, $admin, ['account' => $accountId], $member, Module::Finance);
                } else {
                    $this->audit->record(AuditEvent::FinanceVisibilityChanged, $admin, ['change' => $change, 'account' => $accountId], $member, Module::Finance);
                }
            }
        }
    }
}
