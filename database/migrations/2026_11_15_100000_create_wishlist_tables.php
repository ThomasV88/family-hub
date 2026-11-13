<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 200);
            $table->text('notes')->nullable();
            $table->bigInteger('price_minor')->nullable();
            $table->char('price_currency', 3)->default('EUR');
            $table->string('size_notes', 200)->nullable();
            $table->smallInteger('priority')->default(1);
            $table->string('image_path')->nullable();
            $table->string('thumb_path')->nullable();
            $table->string('image_source', 16)->nullable();
            $table->string('image_source_host', 255)->nullable();
            $table->string('image_status', 16)->default('none');
            $table->timestampTz('received_at')->nullable();
            $table->timestampsTz();

            $table->index(['owner_id', 'received_at']);
        });

        DB::statement('ALTER TABLE wishes ADD CONSTRAINT wishes_price_check CHECK (price_minor IS NULL OR price_minor >= 0)');
        DB::statement("ALTER TABLE wishes ADD CONSTRAINT wishes_currency_check CHECK (price_currency ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE wishes ADD CONSTRAINT wishes_priority_check CHECK (priority IN (0,1,2))');
        DB::statement("ALTER TABLE wishes ADD CONSTRAINT wishes_image_source_check CHECK (image_source IS NULL OR image_source IN ('fetched','uploaded'))");
        DB::statement("ALTER TABLE wishes ADD CONSTRAINT wishes_image_status_check CHECK (image_status IN ('none','pending','ready','failed'))");

        Schema::create('wish_urls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wish_id')->constrained('wishes')->cascadeOnDelete();
            $table->string('url', 2048);
            $table->string('label', 100)->nullable();
            $table->smallInteger('position')->default(0);

            $table->index(['wish_id', 'position']);
        });

        Schema::create('wishlist_managers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('manager_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['owner_id', 'manager_id']);
            $table->index('manager_id');
        });

        DB::statement('ALTER TABLE wishlist_managers ADD CONSTRAINT wishlist_managers_not_self CHECK (owner_id <> manager_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlist_managers');
        Schema::dropIfExists('wish_urls');
        Schema::dropIfExists('wishes');
    }
};
