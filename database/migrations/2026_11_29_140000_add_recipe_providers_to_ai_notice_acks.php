<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The recipe import has its own one-time notice acknowledgment: a separate column, so the medical notice can never
 * satisfy the recipe one and the other way round. `providers` becomes nullable (the default stays for existing
 * inserts): a member who acknowledged only the recipe notice gets a row whose medical set is NULL, which every medical
 * reader already treats as "nothing acknowledged".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_notice_acks', function (Blueprint $table) {
            $table->string('recipe_providers', 40)->nullable();
        });
        DB::statement('ALTER TABLE ai_notice_acks ALTER COLUMN providers DROP NOT NULL');
    }

    public function down(): void
    {
        // Rows that exist only because of a recipe acknowledgment have no medical meaning: remove them.
        DB::table('ai_notice_acks')->whereNull('providers')->delete();
        DB::statement('ALTER TABLE ai_notice_acks ALTER COLUMN providers SET NOT NULL');
        Schema::table('ai_notice_acks', function (Blueprint $table) {
            $table->dropColumn('recipe_providers');
        });
    }
};
