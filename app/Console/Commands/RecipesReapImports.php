<?php

namespace App\Console\Commands;

use App\Medical\Ai\FailureCode;
use App\Recipes\Import\RecipeImportQuery;
use Illuminate\Console\Command;

class RecipesReapImports extends Command
{
    protected $signature = 'recipes:reap-imports';

    protected $description = 'Close recipe imports a dead worker left behind and clear their page text';

    public function handle(RecipeImportQuery $imports): int
    {
        $count = 0;
        foreach ($imports->idsStuck('fetching', 'created_at', (int) config('recipes.import.reap_fetching_minutes')) as $id) {
            $count += $imports->failFetching($id, 'network') ? 1 : 0;
        }
        foreach ($imports->idsStuck('queued', 'queued_at', (int) config('recipes.import.reap_queued_minutes')) as $id) {
            $count += $imports->failQueued($id) ? 1 : 0;
        }
        foreach ($imports->idsStuck('running', 'started_at', (int) config('recipes.import.reap_running_minutes')) as $id) {
            $count += $imports->finishFailed($id, FailureCode::WorkerLost, 'default', $imports->runDurationMs($id)) ? 1 : 0;
        }
        $this->line((string) $count);

        return 0;
    }
}
