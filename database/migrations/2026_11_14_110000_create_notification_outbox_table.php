<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 40);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('dedupe_key', 191)->unique();
            $table->jsonb('payload');
            $table->string('status', 16)->default('pending');
            $table->smallInteger('attempts')->default(0);
            $table->timestampTz('available_at');
            $table->timestampTz('sent_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestampsTz();

            $table->index(['status', 'available_at']);
        });

        DB::statement("ALTER TABLE notification_outbox ADD CONSTRAINT notification_outbox_channel_check CHECK (channel IN ('mail','push'))");
        DB::statement("ALTER TABLE notification_outbox ADD CONSTRAINT notification_outbox_status_check CHECK (status IN ('pending','queued','sending','sent','failed','skipped'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_outbox');
    }
};
