<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Generic column-mapping profiles only: no account id, no money data, family-wide.
        Schema::create('finance_import_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 60);
            $table->jsonb('settings');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX finance_import_profiles_name_unique ON finance_import_profiles (lower(name))');

        Schema::create('finance_import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained('finance_accounts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 9)->default('draft');
            $table->jsonb('profile_snapshot')->nullable();
            $table->integer('row_count')->default(0);
            $table->integer('added_count')->default(0);
            $table->integer('matched_count')->default(0);
            $table->integer('kept_count')->nullable();
            $table->timestampTz('committed_at')->nullable();
            $table->timestampTz('undone_at')->nullable();
            $table->timestampsTz();

            $table->index(['account_id', 'created_at']);
            $table->index(['user_id', 'account_id', 'status']);
        });
        DB::statement("ALTER TABLE finance_import_batches ADD CONSTRAINT finance_import_batches_status_check CHECK (status IN ('draft','committed','undone'))");

        Schema::create('finance_import_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('batch_id')->constrained('finance_import_batches')->cascadeOnDelete();
            $table->integer('row_no');
            $table->date('booked_on')->nullable();
            $table->bigInteger('amount_minor')->nullable();
            $table->string('kind', 8)->nullable();
            $table->string('description', 500)->default('');
            $table->string('counterparty', 200)->nullable();
            $table->string('reference', 100)->nullable();
            $table->char('import_hash', 64)->nullable();
            $table->string('state', 18);
            $table->string('error_code', 20)->nullable();
            $table->boolean('include')->default(false);
            $table->foreignUuid('probable_transaction_id')->nullable()->constrained('finance_transactions')->nullOnDelete();
            $table->foreignUuid('category_id')->nullable()->constrained('finance_categories')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['batch_id', 'row_no']);
        });
        DB::statement("ALTER TABLE finance_import_rows ADD CONSTRAINT finance_import_rows_state_check CHECK (state IN ('new','duplicate','probable_duplicate','invalid'))");
        DB::statement('ALTER TABLE finance_import_rows ADD CONSTRAINT finance_import_rows_amount_check CHECK (amount_minor IS NULL OR amount_minor BETWEEN -10000000000000 AND 10000000000000)');

        DB::statement('ALTER TABLE finance_transactions ADD CONSTRAINT finance_transactions_import_batch_fk FOREIGN KEY (import_batch_id) REFERENCES finance_import_batches (id) ON DELETE SET NULL');
        DB::statement('ALTER TABLE finance_transactions ADD CONSTRAINT finance_transactions_reconciled_batch_fk FOREIGN KEY (reconciled_batch_id) REFERENCES finance_import_batches (id) ON DELETE SET NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE finance_transactions DROP CONSTRAINT IF EXISTS finance_transactions_import_batch_fk');
        DB::statement('ALTER TABLE finance_transactions DROP CONSTRAINT IF EXISTS finance_transactions_reconciled_batch_fk');
        Schema::dropIfExists('finance_import_rows');
        Schema::dropIfExists('finance_import_batches');
        Schema::dropIfExists('finance_import_profiles');
    }
};
