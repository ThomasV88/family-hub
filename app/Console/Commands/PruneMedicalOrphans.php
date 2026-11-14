<?php

namespace App\Console\Commands;

use App\Medical\Documents\MedicalDocumentStore;
use App\Medical\Queries\SystemQuery;
use Illuminate\Console\Command;
use InvalidArgumentException;

class PruneMedicalOrphans extends Command
{
    protected $signature = 'medical:prune-orphans';

    protected $description = 'Removes stored medical files that no document row references, and directories of people that no longer exist (after the grace period)';

    public function handle(MedicalDocumentStore $store, SystemQuery $system): int
    {
        $cutoff = now()->subMinutes((int) config('medical.orphan_grace_minutes'))->getTimestamp();

        // Inventory and removal go only through the store; the rows are asked through the system query (ids and paths only).
        $old = array_values(array_filter($store->files(), fn (array $file): bool => $file['modifiedAt'] <= $cutoff));
        $paths = array_column($old, 'path');
        $known = array_flip($system->knownPaths($paths));
        $files = 0;
        foreach ($paths as $path) {
            if (isset($known[$path])) {
                continue;
            }
            try {
                $store->delete([$path]);
                $files++;
            } catch (InvalidArgumentException) {
                // Not a file this application wrote: left alone.
            }
        }

        $directories = array_values(array_filter($store->subjectDirectories(), fn (array $dir): bool => $dir['modifiedAt'] <= $cutoff));
        $existing = array_flip($system->existingSubjectIds(array_column($directories, 'subjectId')));
        $removed = 0;
        foreach ($directories as $dir) {
            if (isset($existing[$dir['subjectId']])) {
                continue;
            }
            try {
                $store->deleteSubjectDirectory($dir['subjectId']);
                $removed++;
            } catch (InvalidArgumentException) {
                // A directory name that is not a person id: left alone.
            }
        }

        $this->info("Pruned {$files} orphan files and {$removed} directories.");

        return 0;
    }
}
