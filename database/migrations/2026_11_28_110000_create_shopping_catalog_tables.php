<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Built-in units in display order; their labels come from the language files, not the database. */
    private const BUILT_IN_UNITS = ['pcs', 'g', 'kg', 'ml', 'l', 'pack', 'bunch', 'tbsp', 'tsp'];

    public function up(): void
    {
        Schema::create('shopping_units', function (Blueprint $table) {
            $table->id();
            $table->string('key', 20)->nullable()->unique();
            $table->string('name_en', 12)->nullable();
            $table->string('name_sk', 12)->nullable();
            $table->string('name_pl', 12)->nullable();
            $table->smallInteger('position')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE shopping_units ADD CONSTRAINT shopping_units_kind CHECK ('
            .'(key IS NOT NULL AND name_en IS NULL AND name_sk IS NULL AND name_pl IS NULL) '
            .'OR (key IS NULL AND name_en IS NOT NULL AND name_sk IS NOT NULL AND name_pl IS NOT NULL))');
        foreach (['en', 'sk', 'pl'] as $locale) {
            DB::statement("CREATE UNIQUE INDEX shopping_units_name_{$locale}_unique ON shopping_units (lower(name_{$locale})) WHERE name_{$locale} IS NOT NULL");
        }

        $now = now();
        foreach (self::BUILT_IN_UNITS as $i => $key) {
            DB::table('shopping_units')->insert([
                'key' => $key, 'position' => $i + 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        Schema::create('shopping_stores', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('name_key', 60)->unique();
            $table->string('logo_path')->nullable();
            $table->string('logo_thumb_path')->nullable();
            $table->integer('image_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('shopping_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('name_key', 80)->unique();
            $table->foreignId('default_unit_id')->nullable()->constrained('shopping_units')->nullOnDelete();
            $table->string('image_path')->nullable();
            $table->string('thumb_path')->nullable();
            $table->integer('image_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_catalog_items');
        Schema::dropIfExists('shopping_stores');
        Schema::dropIfExists('shopping_units');
    }
};
