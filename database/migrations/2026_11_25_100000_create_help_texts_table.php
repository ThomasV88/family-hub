<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contextual help ("?" tips): one row per topic key of resources/help/topics.json, one text per supported locale and a
 * per-locale marker that an administrator edited it, so `help:sync` can refresh defaults without overwriting edits.
 * The runtime role needs plain read/write, which the default privileges of the owner role already give.
 * edited_by is a plain id without a foreign key: removing a user must never remove or change a help text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_texts', function (Blueprint $table) {
            $table->string('key', 120)->primary();
            $table->string('area', 40);
            $table->string('screen', 60);
            $table->string('field', 60);
            $table->text('text_sk')->default('');
            $table->text('text_pl')->default('');
            $table->text('text_en')->default('');
            $table->boolean('edited_sk')->default(false);
            $table->boolean('edited_pl')->default(false);
            $table->boolean('edited_en')->default(false);
            $table->timestampTz('edited_at')->nullable();
            $table->unsignedBigInteger('edited_by')->nullable();
            $table->timestampsTz();
        });

        foreach (['sk', 'pl', 'en'] as $locale) {
            DB::statement("ALTER TABLE help_texts ADD CONSTRAINT help_texts_text_{$locale}_length CHECK (char_length(text_{$locale}) <= 400)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('help_texts');
    }
};
