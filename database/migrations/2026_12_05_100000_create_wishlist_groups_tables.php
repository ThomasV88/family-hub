<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishlist_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('mode', 8);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE wishlist_groups ADD CONSTRAINT wishlist_groups_mode_check CHECK (mode IN ('gifts','shared'))");

        Schema::create('wishlist_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('wishlist_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('added_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['group_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::table('wishes', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->change();
            $table->foreignId('group_id')->nullable()->constrained('wishlist_groups')->restrictOnDelete();
            $table->foreignId('received_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['group_id', 'received_at']);
        });

        DB::statement('ALTER TABLE wishes ADD CONSTRAINT wishes_owner_xor_group CHECK ((owner_id IS NULL) <> (group_id IS NULL))');
    }

    public function down(): void
    {
        DB::statement('DELETE FROM wishes WHERE group_id IS NOT NULL');
        DB::statement('ALTER TABLE wishes DROP CONSTRAINT wishes_owner_xor_group');

        Schema::table('wishes', function (Blueprint $table) {
            $table->dropIndex(['group_id', 'received_at']);
            $table->dropConstrainedForeignId('received_by_id');
            $table->dropConstrainedForeignId('group_id');
        });

        DB::statement('ALTER TABLE wishes ALTER COLUMN owner_id SET NOT NULL');

        Schema::dropIfExists('wishlist_group_members');
        Schema::dropIfExists('wishlist_groups');
    }
};
