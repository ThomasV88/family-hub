<?php

namespace App\Console\Commands;

use App\Help\HelpTexts;
use Illuminate\Console\Command;

class HelpSync extends Command
{
    protected $signature = 'help:sync';

    protected $description = 'Reconcile the contextual help table with resources/help (idempotent; keeps administrator edits, reports orphans)';

    public function handle(HelpTexts $help): int
    {
        $result = $help->sync();

        $this->info(sprintf(
            'help:sync inserted %d, refreshed %d, unchanged %d (edited locales kept: %d).',
            $result['inserted'], $result['refreshed'], $result['unchanged'], $result['kept_edited'],
        ));
        foreach ($result['orphans'] as $key) {
            $this->warn("Orphan help key (in the database, not in the registry; kept): {$key}");
        }

        return 0;
    }
}
