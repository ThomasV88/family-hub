<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopping_lists', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->foreignId('store_id')->nullable()->constrained('shopping_stores')->nullOnDelete();
            $table->string('store_name', 60)->nullable();
            $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 8)->default('open');
            $table->timestampTz('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('remind_at')->nullable();
            $table->timestampTz('reminder_enqueued_at')->nullable();
            $table->foreignId('source_list_id')->nullable()->constrained('shopping_lists')->nullOnDelete();
            $table->string('source_kind', 5)->nullable();
            $table->timestampsTz();

            $table->index('status');
            $table->index('creator_id');
            $table->index('responsible_id');
        });

        DB::statement("ALTER TABLE shopping_lists ADD CONSTRAINT shopping_lists_status_check CHECK (status IN ('open','locked','done'))");
        DB::statement("ALTER TABLE shopping_lists ADD CONSTRAINT shopping_lists_source_kind_check CHECK (source_kind IS NULL OR source_kind IN ('copy','carry'))");
        DB::statement("ALTER TABLE shopping_lists ADD CONSTRAINT shopping_lists_done_check CHECK ((status = 'done') = (done_at IS NOT NULL))");
        DB::statement("ALTER TABLE shopping_lists ADD CONSTRAINT shopping_lists_locked_check CHECK (status <> 'locked' OR locked_at IS NOT NULL)");
        DB::statement('ALTER TABLE shopping_lists ADD CONSTRAINT shopping_lists_source_check CHECK (source_list_id IS NULL OR source_kind IS NOT NULL)');
        DB::statement("CREATE UNIQUE INDEX shopping_lists_one_carry ON shopping_lists (source_list_id) WHERE source_kind = 'carry'");
        DB::statement("CREATE INDEX shopping_lists_done_at_index ON shopping_lists (done_at DESC) WHERE status = 'done'");
        DB::statement('CREATE INDEX shopping_lists_remind_at_index ON shopping_lists (remind_at) WHERE remind_at IS NOT NULL AND reminder_enqueued_at IS NULL');

        Schema::create('shopping_list_members', function (Blueprint $table) {
            $table->foreignId('list_id')->constrained('shopping_lists')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 12);
            $table->timestampTz('created_at')->nullable();
            $table->primary(['list_id', 'user_id']);
            $table->index('user_id');
        });

        DB::statement("ALTER TABLE shopping_list_members ADD CONSTRAINT shopping_list_members_role_check CHECK (role IN ('contributor','viewer'))");

        Schema::create('shopping_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('list_id')->constrained('shopping_lists')->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->nullable()->constrained('shopping_catalog_items')->nullOnDelete();
            $table->string('name', 80);
            $table->decimal('quantity', 5, 2)->default(1);
            $table->foreignId('unit_id')->nullable()->constrained('shopping_units')->nullOnDelete();
            $table->string('note', 200)->nullable();
            $table->string('state', 12)->default('pending');
            $table->timestampTz('state_changed_at')->nullable();
            $table->foreignId('state_changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('position')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['list_id', 'position']);
        });

        DB::statement("ALTER TABLE shopping_items ADD CONSTRAINT shopping_items_state_check CHECK (state IN ('pending','bought','unavailable'))");
        DB::statement('ALTER TABLE shopping_items ADD CONSTRAINT shopping_items_quantity_check CHECK (quantity > 0 AND quantity <= 999.99)');
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_items');
        Schema::dropIfExists('shopping_list_members');
        Schema::dropIfExists('shopping_lists');
    }
};
