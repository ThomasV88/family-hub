<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the fetch job actually started. The fetching reaper measures from this moment, not from the creation of the
 * row, so a job that waits behind other jobs on the shared default queue is not closed as a network error.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipe_imports', function (Blueprint $table) {
            $table->timestampTz('fetch_started_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('recipe_imports', function (Blueprint $table) {
            $table->dropColumn('fetch_started_at');
        });
    }
};
