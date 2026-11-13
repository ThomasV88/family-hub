<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StagingAudit extends Command
{
    protected $signature = 'staging:audit';

    protected $description = 'Fail if staging contains any non-synthetic data';

    /**
     * Rules of [table, SQL condition matching NON-synthetic rows].
     * Later phases append rules for their own tables.
     *
     * @return list<array{0: string, 1: string}>
     */
    protected function rules(): array
    {
        $synthetic = "(select id from users where email like '%@example.test')";
        // Phase 5: staging must never hold real finance data. Rows created by a deleted (null) or non-synthetic user fail.
        $createdByReal = "(created_by is null or created_by not in {$synthetic})";

        return [
            ['users', "email not like '%@example.test'"],
            // Phase 2: invitations hold the invited address; audit rows keep names (labels) and invite emails.
            ['invitations', "email not like '%@example.test'"],
            ['audit_logs', "(meta->>'email' is not null and meta->>'email' not like '%@example.test')"
                ." or (actor_id is not null and actor_id not in (select id from users where email like '%@example.test'))"
                ." or (target_user_id is not null and target_user_id not in (select id from users where email like '%@example.test'))"],
            ['finance_accounts', $createdByReal],
            ['finance_transactions', $createdByReal],
            ['finance_recurring', $createdByReal],
            ['finance_recurring_matches', $createdByReal],
            ['finance_budgets', $createdByReal],
            ['finance_import_profiles', $createdByReal],
            ['finance_import_batches', "(user_id not in {$synthetic})"],
            ['finance_account_members', "(user_id not in {$synthetic})"],
        ];
    }

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('staging:audit refused: environment production');

            return 1;
        }

        $failed = false;
        foreach ($this->rules() as [$table, $condition]) {
            $n = DB::table($table)->whereRaw($condition)->count();
            if ($n > 0) {
                $this->error("staging:audit FAILED: {$n} non-synthetic {$table}");
                $failed = true;
            }
        }

        if ($failed) {
            return 1;
        }

        $this->info('staging:audit OK');

        return 0;
    }
}
