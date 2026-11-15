<?php

namespace App\Console\Commands;

use App\Medical\Documents\MedicalDocumentStore;
use App\Medical\Queries\SystemQuery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class PruneMedicalOrphans extends Command
{
    protected $signature = 'medical:prune-orphans {--dry-run : Count what would be removed and delete nothing} {--force : Skip the circuit breaker (deliberate cleanup only)}';

    protected $description = 'Removes stored medical files that no document row references, and directories of people that no longer exist (after the grace period)';

    public function handle(MedicalDocumentStore $store, SystemQuery $system): int
    {
        $cutoff = now()->subMinutes((int) config('medical.orphan_grace_minutes'))->getTimestamp();

        // Inventory and removal go only through the store; the rows are asked through the system query (ids and paths only).
        $inventory = $store->files();
        $old = array_values(array_filter($inventory, fn (array $file): bool => $file['modifiedAt'] <= $cutoff));
        $paths = array_column($old, 'path');
        $known = array_flip($system->knownPaths($paths));
        $orphanFiles = array_values(array_filter($paths, fn (string $path): bool => ! isset($known[$path])));

        $allDirectories = $store->subjectDirectories();
        $directories = array_values(array_filter($allDirectories, fn (array $dir): bool => $dir['modifiedAt'] <= $cutoff));
        $existing = array_flip($system->existingSubjectIds(array_column($directories, 'subjectId')));
        $orphanDirectories = array_values(array_filter($directories, fn (array $dir): bool => ! isset($existing[$dir['subjectId']])));

        $counts = [
            'files' => count($orphanFiles), 'files_total' => count($inventory),
            'directories' => count($orphanDirectories), 'directories_total' => count($allDirectories),
        ];
        Log::info('medical:prune-orphans candidates', $counts);

        if (! $this->option('force') && $this->breakerTripped($system, $counts)) {
            Log::error('medical:prune-orphans aborted by the circuit breaker, nothing deleted', $counts);
            $this->error("Aborted: {$counts['files']} of {$counts['files_total']} files and {$counts['directories']} of {$counts['directories_total']} directories look orphaned. Nothing was deleted. Check that this is the right database, then run again with --force.");

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info("Dry run: would prune {$counts['files']} orphan files and {$counts['directories']} directories.");

            return self::SUCCESS;
        }

        $files = 0;
        foreach ($orphanFiles as $path) {
            try {
                $store->delete([$path]);
                $files++;
            } catch (InvalidArgumentException) {
                // Not a file this application wrote: left alone.
            }
        }

        $removed = 0;
        foreach ($orphanDirectories as $dir) {
            try {
                $store->deleteSubjectDirectory($dir['subjectId']);
                $removed++;
            } catch (InvalidArgumentException) {
                // A directory name that is not a person id: left alone.
            }
        }

        $this->info("Pruned {$files} orphan files and {$removed} directories.");

        return self::SUCCESS;
    }

    /**
     * A wrong or freshly restored database makes everything look orphaned. Refuse a bulk delete (many candidates and a
     * large share of the inventory) and any delete while the table that should reference the files is empty.
     *
     * @param  array{files: int, files_total: int, directories: int, directories_total: int}  $counts
     */
    private function breakerTripped(SystemQuery $system, array $counts): bool
    {
        $max = (int) config('medical.orphan_max_delete');
        $ratio = (float) config('medical.orphan_max_ratio');
        $bulk = fn (int $candidates, int $total): bool => $candidates > $max && $candidates / max($total, 1) > $ratio;

        return $bulk($counts['files'], $counts['files_total'])
            || $bulk($counts['directories'], $counts['directories_total'])
            || ($counts['files'] > 0 && ! $system->hasDocuments())
            || ($counts['directories'] > 0 && ! $system->hasSubjects());
    }
}
