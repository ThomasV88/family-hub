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

        $seeder = new StagingSeeder;
        $count = DB::transaction(fn () => $seeder->run());

        $this->info("staging:seed created {$count} synthetic users");

        // The help tips are not demo data (no user owns them), but a reseeded staging must have them, so the seed path syncs them too.
        $this->call('help:sync');
        foreach ($seeder->summary as $module => $counts) {
            $this->line('  '.$module.': '.(is_array($counts) ? json_encode($counts) : $counts));
        }

        return 0;
    }
}
