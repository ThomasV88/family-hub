<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('series_id')->nullable();
            $table->string('title', 200);
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('todo');
            $table->string('visibility', 16)->default('family');
            $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->time('due_time', 0)->nullable();
            $table->string('timezone', 64);
            $table->integer('remind_before_minutes')->nullable()->default(0);
            $table->timestampTz('remind_at')->nullable();
            $table->string('recurrence_rule', 255)->nullable();
            $table->string('recurrence_mode', 16)->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('skipped_at')->nullable();
            $table->timestampsTz();

            $table->index(['assignee_id', 'status']);
            $table->index('creator_id');
            $table->index('visibility');
        });

        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_status_check CHECK (status IN ('todo','in_progress','done'))");
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_visibility_check CHECK (visibility IN ('personal','family','selected'))");
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_remind_before_check CHECK (remind_before_minutes IS NULL OR remind_before_minutes IN (0,15,60,1440))');
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_recurrence_mode_check CHECK (recurrence_mode IS NULL OR recurrence_mode IN ('schedule','completion'))");
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_due_time_needs_date CHECK (due_time IS NULL OR due_date IS NOT NULL)');
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_recurrence_needs_date CHECK (recurrence_rule IS NULL OR due_date IS NOT NULL)');
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_rule_mode_together CHECK ((recurrence_rule IS NULL) = (recurrence_mode IS NULL))');
        DB::statement('CREATE INDEX tasks_remind_at_index ON tasks (remind_at) WHERE remind_at IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX tasks_one_open_per_series ON tasks (series_id) WHERE series_id IS NOT NULL AND completed_at IS NULL AND skipped_at IS NULL');

        Schema::create('task_members', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unique(['task_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_members');
        Schema::dropIfExists('tasks');
    }
};
