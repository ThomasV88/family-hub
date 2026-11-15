<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditPrune extends Command
{
    protected $signature = 'audit:prune';

    protected $description = 'Delete audit rows older than 2 years (owner database role only)';

    public function handle(): int
    {
        if ($this->currentRole() === 'fh_app') {
            $this->error('audit:prune must run with the owner role (migrate service)');

            return 1;
        }

        // The removal trigger on audit_logs only lets the owner role delete inside a transaction that opts in.
        $count = DB::transaction(function (): int {
            DB::statement("set local audit.prune = 'on'");

            return DB::delete("delete from audit_logs where occurred_at < now() - interval '2 years'");
        });
        $this->info("Pruned {$count} audit rows.");

        return 0;
    }

    protected function currentRole(): string
    {
        return (string) DB::selectOne('select current_user as role')->role;
    }
}
