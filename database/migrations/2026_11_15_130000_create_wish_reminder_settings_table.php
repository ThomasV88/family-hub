<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wish_reminder_settings', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->jsonb('lead_days')->default('[14,3]');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wish_reminder_settings');
    }
};
