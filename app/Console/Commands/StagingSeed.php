<?php

namespace App\Console\Commands;

use Database\Seeders\StagingSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StagingSeed extends Command
{
    protected $signature = 'staging:seed {--force : Required explicit opt-in}';

    protected $description = 'Seed synthetic demo data (staging, local and testing only)';

    public function handle(): int
    {
        if (! app()->environment(['staging', 'local', 'testing'])) {
            $this->error('staging:seed refused: environment '.app()->environment());

            return 1;
        }

        if (! $this->option('force')) {
            $this->error('staging:seed requires --force.');

            return 1;
        }

        $count = DB::transaction(fn () => (new StagingSeeder)->run());

        $this->info("staging:seed created {$count} synthetic users");

        return 0;
    }
}
