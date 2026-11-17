<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The bulk scope no_text (documents without recognised text; the run is text-only, mode text). varchar(12) already fits.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['ai_bulk_dry_runs', 'ai_bulk_runs'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_scope_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_scope_check CHECK (scope IN ('no_proposal', 'no_text', 'last_failed', 'all'))");
        }
    }

    public function down(): void
    {
        $open = (int) DB::table('ai_bulk_dry_runs')->where('scope', 'no_text')->count()
            + (int) DB::table('ai_bulk_runs')->where('scope', 'no_text')->count();
        if ($open > 0) {
            throw new RuntimeException("Refusing to roll back: {$open} bulk dry run(s) or run(s) have scope 'no_text'. Resolve them first (they are never rewritten silently).");
        }

        foreach (['ai_bulk_dry_runs', 'ai_bulk_runs'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_scope_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_scope_check CHECK (scope IN ('no_proposal', 'last_failed', 'all'))");
        }
    }
};
