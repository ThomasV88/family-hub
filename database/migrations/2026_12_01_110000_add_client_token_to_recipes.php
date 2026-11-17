<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency token of "create recipe": the form sends one random token per form session, so a retry after a lost
 * response returns the recipe the first request created instead of making a second one. Unique per author; rows
 * without a token (older recipes, imports by other paths) are not constrained.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->string('client_token', 64)->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX recipes_author_client_token_unique ON recipes (author_id, client_token) WHERE client_token IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS recipes_author_client_token_unique');
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropColumn('client_token');
        });
    }
};
