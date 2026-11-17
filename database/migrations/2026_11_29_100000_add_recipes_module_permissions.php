<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE module_permissions DROP CONSTRAINT module_permissions_module_check');
        DB::statement("ALTER TABLE module_permissions ADD CONSTRAINT module_permissions_module_check CHECK (module IN ('todo','wishlist','finance','health','shopping','recipes'))");

        // Every member keeps equivalent access: Recipes start at the To-do level, never above it.
        DB::statement("INSERT INTO module_permissions (user_id, module, level, created_at, updated_at) SELECT user_id, 'recipes', level, now(), now() FROM module_permissions WHERE module = 'todo' ON CONFLICT (user_id, module) DO NOTHING");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM module_permissions WHERE module = 'recipes'");
        DB::statement('ALTER TABLE module_permissions DROP CONSTRAINT module_permissions_module_check');
        DB::statement("ALTER TABLE module_permissions ADD CONSTRAINT module_permissions_module_check CHECK (module IN ('todo','wishlist','finance','health','shopping'))");
    }
};
