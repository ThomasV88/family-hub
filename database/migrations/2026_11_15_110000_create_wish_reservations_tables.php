<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Reservations live in their own tables: no column, FK or relation ties them to the wish row itself. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wish_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wish_id')->unique()->constrained('wishes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('cobuy_state', 8)->default('none');
            $table->timestampsTz();

            $table->index('user_id');
        });

        DB::statement("ALTER TABLE wish_reservations ADD CONSTRAINT wish_reservations_cobuy_check CHECK (cobuy_state IN ('none','open','closed'))");

        Schema::create('wish_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained('wish_reservations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->bigInteger('amount_minor')->nullable();
            $table->timestampsTz();

            $table->unique(['reservation_id', 'user_id']);
            $table->index('user_id');
        });

        DB::statement('ALTER TABLE wish_contributions ADD CONSTRAINT wish_contributions_amount_check CHECK (amount_minor IS NULL OR amount_minor > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('wish_contributions');
        Schema::dropIfExists('wish_reservations');
    }
};
