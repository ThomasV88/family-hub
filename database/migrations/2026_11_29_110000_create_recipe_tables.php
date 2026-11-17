<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->string('title', 120);
            $table->string('title_key', 255)->default('');
            $table->string('description', 1000)->nullable();
            $table->string('visibility', 7)->default('private');
            $table->smallInteger('servings')->nullable();
            $table->smallInteger('prep_minutes')->nullable();
            $table->smallInteger('cook_minutes')->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->string('source_host', 255)->nullable();
            $table->string('created_via', 6)->default('manual');
            $table->text('search_key')->default('');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE recipes ADD CONSTRAINT recipes_visibility_check CHECK (visibility IN ('private','shared'))");
        DB::statement("ALTER TABLE recipes ADD CONSTRAINT recipes_created_via_check CHECK (created_via IN ('manual','url'))");
        DB::statement('ALTER TABLE recipes ADD CONSTRAINT recipes_servings_check CHECK (servings IS NULL OR (servings >= 1 AND servings <= 99))');
        DB::statement('ALTER TABLE recipes ADD CONSTRAINT recipes_prep_minutes_check CHECK (prep_minutes IS NULL OR (prep_minutes >= 0 AND prep_minutes <= 1440))');
        DB::statement('ALTER TABLE recipes ADD CONSTRAINT recipes_cook_minutes_check CHECK (cook_minutes IS NULL OR (cook_minutes >= 0 AND cook_minutes <= 1440))');
        DB::statement('CREATE INDEX recipes_visibility_created_index ON recipes (visibility, created_at DESC)');
        DB::statement('CREATE INDEX recipes_author_created_index ON recipes (author_id, created_at DESC)');

        Schema::create('recipe_categories', function (Blueprint $table) {
            $table->foreignId('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $table->string('name', 30);
            $table->string('name_key', 90);
            $table->smallInteger('position')->default(0);
            $table->primary(['recipe_id', 'name_key']);
            $table->index('name_key');
        });

        Schema::create('recipe_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $table->string('path', 255);
            $table->string('thumb_path', 255);
            $table->smallInteger('position')->default(0);
            $table->boolean('is_main')->default(false);
            $table->integer('version')->default(1);
            $table->timestampTz('created_at')->nullable();
            $table->index(['recipe_id', 'position']);
        });

        DB::statement('CREATE UNIQUE INDEX recipe_photos_one_main ON recipe_photos (recipe_id) WHERE is_main');

        Schema::create('recipe_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $table->smallInteger('position')->default(0);
            $table->string('name', 120);
            $table->string('name_key', 360);
            $table->decimal('quantity', 6, 2)->nullable();
            $table->foreignId('unit_id')->nullable()->constrained('shopping_units')->nullOnDelete();
            $table->string('note', 80)->nullable();
            $table->index(['recipe_id', 'position']);
        });

        DB::statement('ALTER TABLE recipe_ingredients ADD CONSTRAINT recipe_ingredients_quantity_check CHECK (quantity IS NULL OR (quantity > 0 AND quantity <= 9999))');

        Schema::create('recipe_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $table->smallInteger('position')->default(0);
            $table->string('body', 1000);
            $table->index(['recipe_id', 'position']);
        });

        Schema::create('recipe_member_states', function (Blueprint $table) {
            $table->foreignId('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('favourite')->default(false);
            $table->text('note')->nullable();
            $table->smallInteger('servings')->nullable();
            $table->timestampTz('updated_at')->nullable();
            $table->primary(['recipe_id', 'user_id']);
        });

        DB::statement('ALTER TABLE recipe_member_states ADD CONSTRAINT recipe_member_states_servings_check CHECK (servings IS NULL OR (servings >= 1 AND servings <= 99))');
        DB::statement('CREATE INDEX recipe_member_states_favourite_index ON recipe_member_states (user_id) WHERE favourite');

        Schema::create('recipe_ingredient_ticks', function (Blueprint $table) {
            $table->foreignId('ingredient_id')->constrained('recipe_ingredients')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('created_at')->nullable();
            $table->primary(['ingredient_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('recipe_step_done', function (Blueprint $table) {
            $table->foreignId('step_id')->constrained('recipe_steps')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('created_at')->nullable();
            $table->primary(['step_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_step_done');
        Schema::dropIfExists('recipe_ingredient_ticks');
        Schema::dropIfExists('recipe_member_states');
        Schema::dropIfExists('recipe_steps');
        Schema::dropIfExists('recipe_ingredients');
        Schema::dropIfExists('recipe_photos');
        Schema::dropIfExists('recipe_categories');
        Schema::dropIfExists('recipes');
    }
};
