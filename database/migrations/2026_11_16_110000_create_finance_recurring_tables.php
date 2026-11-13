<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_recurring', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained('finance_accounts')->cascadeOnDelete();
            $table->foreignUuid('category_id')->nullable()->constrained('finance_categories')->nullOnDelete();
            $table->string('name', 120);
            $table->bigInteger('expected_amount_minor');
            $table->string('recurrence_rule', 255);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->date('schedule_effective_from');
            $table->bigInteger('tolerance_minor')->default(0);
            $table->smallInteger('window_days')->default(5);
            $table->string('text_hint', 120)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['id', 'account_id'], 'finance_recurring_id_account_unique');
        });

        DB::statement('ALTER TABLE finance_recurring ADD CONSTRAINT finance_recurring_amount_check CHECK (expected_amount_minor > 0 AND expected_amount_minor <= 10000000000000)');
        DB::statement('ALTER TABLE finance_recurring ADD CONSTRAINT finance_recurring_ends_check CHECK (ends_on IS NULL OR ends_on >= starts_on)');
        DB::statement('ALTER TABLE finance_recurring ADD CONSTRAINT finance_recurring_tolerance_check CHECK (tolerance_minor >= 0 AND tolerance_minor <= 10000000000000)');
        DB::statement('ALTER TABLE finance_recurring ADD CONSTRAINT finance_recurring_window_check CHECK (window_days IN (1,2,3,5,7,10,14))');
        DB::statement('CREATE INDEX finance_recurring_account_idx ON finance_recurring (account_id)');

        Schema::create('finance_recurring_matches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('recurring_id');
            $table->uuid('account_id');
            $table->date('due_date');
            $table->uuid('transaction_id');
            $table->string('matched_by', 6);
            $table->bigInteger('expected_amount_minor');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->nullable();

            $table->unique(['recurring_id', 'due_date']);
            $table->unique('transaction_id');
        });

        DB::statement("ALTER TABLE finance_recurring_matches ADD CONSTRAINT finance_recurring_matches_by_check CHECK (matched_by IN ('auto','manual'))");
        DB::statement('ALTER TABLE finance_recurring_matches ADD CONSTRAINT finance_recurring_matches_recurring_fk FOREIGN KEY (recurring_id, account_id) REFERENCES finance_recurring (id, account_id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE finance_recurring_matches ADD CONSTRAINT finance_recurring_matches_transaction_fk FOREIGN KEY (transaction_id, account_id) REFERENCES finance_transactions (id, account_id) ON DELETE CASCADE');

        Schema::create('finance_match_rejections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('recurring_id');
            $table->uuid('account_id');
            $table->date('due_date');
            $table->uuid('transaction_id');
            $table->timestampTz('created_at')->nullable();

            $table->unique(['recurring_id', 'due_date', 'transaction_id']);
        });

        DB::statement('ALTER TABLE finance_match_rejections ADD CONSTRAINT finance_match_rejections_recurring_fk FOREIGN KEY (recurring_id, account_id) REFERENCES finance_recurring (id, account_id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE finance_match_rejections ADD CONSTRAINT finance_match_rejections_transaction_fk FOREIGN KEY (transaction_id, account_id) REFERENCES finance_transactions (id, account_id) ON DELETE CASCADE');
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_match_rejections');
        Schema::dropIfExists('finance_recurring_matches');
        Schema::dropIfExists('finance_recurring');
    }
};
