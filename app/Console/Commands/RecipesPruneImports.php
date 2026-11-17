<?php

namespace App\Console\Commands;

use App\Recipes\Import\RecipeImportQuery;
use Illuminate\Console\Command;

class RecipesPruneImports extends Command
{
    protected $signature = 'recipes:prune-imports';

    protected $description = 'Drop the payload of abandoned recipe drafts and delete old import rows';

    public function handle(RecipeImportQuery $imports): int
    {
        $expired = $imports->expireReady((int) config('recipes.import.ready_ttl_hours'));
        $deleted = $imports->deleteFinishedOlderThan((int) config('recipes.import.retention_days'));
        $this->line($expired.' '.$deleted);

        return 0;
    }
}
