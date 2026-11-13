<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            // Only the SHA-256 hash of the link token is stored.
            $table->char('token_hash', 64)->unique();
            $table->string('preset', 20);
            $table->string('locale', 5);
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('mail_failed_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX invitations_one_open ON invitations (lower(email)) WHERE accepted_at IS NULL AND revoked_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
