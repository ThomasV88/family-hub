<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('birth_date')->nullable();
        });

        Schema::create('wish_occasions', function (Blueprint $table) {
            $table->id();
            // null owner = the whole family
            $table->foreignId('owner_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('title', 100)->nullable();
            $table->smallInteger('month');
            $table->smallInteger('day');
            $table->smallInteger('year')->nullable();
            $table->char('calendar', 2)->nullable();
            $table->string('name', 60)->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE wish_occasions ADD CONSTRAINT wish_occasions_kind_check CHECK (kind IN ('nameday','custom'))");
        DB::statement('ALTER TABLE wish_occasions ADD CONSTRAINT wish_occasions_month_check CHECK (month BETWEEN 1 AND 12)');
        DB::statement('ALTER TABLE wish_occasions ADD CONSTRAINT wish_occasions_day_check CHECK (day BETWEEN 1 AND 31)');
        DB::statement("ALTER TABLE wish_occasions ADD CONSTRAINT wish_occasions_calendar_check CHECK (calendar IS NULL OR calendar IN ('sk','pl'))");
        DB::statement("ALTER TABLE wish_occasions ADD CONSTRAINT wish_occasions_nameday_check CHECK (kind <> 'nameday' OR (owner_id IS NOT NULL AND calendar IS NOT NULL AND name IS NOT NULL AND year IS NULL))");
        DB::statement("ALTER TABLE wish_occasions ADD CONSTRAINT wish_occasions_custom_check CHECK (kind <> 'custom' OR title IS NOT NULL)");
        DB::statement("CREATE UNIQUE INDEX wish_occasions_one_nameday_per_owner ON wish_occasions (owner_id) WHERE kind = 'nameday'");
    }

    public function down(): void
    {
        Schema::dropIfExists('wish_occasions');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('birth_date');
        });
    }
};
