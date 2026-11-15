<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 60);
            $table->string('type', 10);
            $table->char('currency', 3);
            $table->bigInteger('opening_balance_minor')->default(0);
            $table->date('balance_as_of');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['id', 'currency'], 'finance_accounts_id_currency_unique');
        });

        DB::statement("ALTER TABLE finance_accounts ADD CONSTRAINT finance_accounts_type_check CHECK (type IN ('bank','cash','savings','card'))");
        DB::statement("ALTER TABLE finance_accounts ADD CONSTRAINT finance_accounts_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE finance_accounts ADD CONSTRAINT finance_accounts_opening_check CHECK (opening_balance_minor BETWEEN -10000000000000 AND 10000000000000)');

        Schema::create('finance_account_members', function (Blueprint $table) {
            $table->foreignUuid('account_id')->constrained('finance_accounts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('created_at')->nullable();

            $table->primary(['account_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('finance_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind', 7);
            $table->string('default_key', 40)->nullable()->unique();
            $table->string('name', 40)->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE finance_categories ADD CONSTRAINT finance_categories_kind_check CHECK (kind IN ('expense','income'))");
        DB::statement('ALTER TABLE finance_categories ADD CONSTRAINT finance_categories_label_check CHECK (default_key IS NOT NULL OR name IS NOT NULL)');
        DB::statement('CREATE UNIQUE INDEX finance_categories_kind_name_unique ON finance_categories (kind, lower(name)) WHERE name IS NOT NULL');

        $now = now();
        $defaults = [
            'expense' => ['housing', 'groceries', 'transport', 'health', 'kids', 'leisure', 'savings', 'other'],
            'income' => ['salary', 'benefits', 'other_income'],
        ];
        foreach ($defaults as $kind => $keys) {
            foreach ($keys as $key) {
                DB::table('finance_categories')->insert([
                    'id' => (string) Str::uuid7(),
                    'kind' => $kind,
                    'default_key' => $key,
                    'name' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        Schema::create('finance_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->char('currency', 3);
            $table->string('kind', 8);
            $table->bigInteger('amount_minor');
            $table->date('booked_on');
            $table->string('description', 160)->nullable();
            $table->string('note', 1000)->nullable();
            $table->foreignUuid('category_id')->nullable()->constrained('finance_categories')->nullOnDelete();
            $table->uuid('transfer_id')->nullable();
            $table->string('source', 6)->default('manual');
            $table->uuid('import_batch_id')->nullable();
            $table->char('import_hash', 64)->nullable();
            $table->uuid('reconciled_batch_id')->nullable();
            $table->timestampTz('edited_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['id', 'account_id'], 'finance_transactions_id_account_unique');
        });

        DB::statement('ALTER TABLE finance_transactions ADD CONSTRAINT finance_transactions_account_currency_fk FOREIGN KEY (account_id, currency) REFERENCES finance_accounts (id, currency) ON DELETE CASCADE');
        DB::statement("ALTER TABLE finance_transactions ADD CONSTRAINT finance_transactions_kind_check CHECK (kind IN ('income','expense','transfer'))");
        DB::statement("ALTER TABLE finance_transactions ADD CONSTRAINT finance_transactions_source_check CHECK (source IN ('manual','import'))");
        DB::statement("ALTER TABLE finance_transactions ADD CONSTRAINT finance_transactions_amount_check CHECK (amount_minor <> 0 AND amount_minor BETWEEN -10000000000000 AND 10000000000000 AND ((kind = 'income' AND amount_minor > 0) OR (kind = 'expense' AND amount_minor < 0) OR kind = 'transfer'))");
        DB::statement("ALTER TABLE finance_transactions ADD CONSTRAINT finance_transactions_transfer_check CHECK ((kind = 'transfer') = (transfer_id IS NOT NULL))");
        DB::statement('CREATE UNIQUE INDEX finance_transactions_import_hash_unique ON finance_transactions (account_id, import_hash) WHERE import_hash IS NOT NULL');
        DB::statement('CREATE INDEX finance_transactions_account_date_idx ON finance_transactions (account_id, booked_on DESC, id)');
        DB::statement('CREATE INDEX finance_transactions_transfer_idx ON finance_transactions (transfer_id) WHERE transfer_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_transactions');
        Schema::dropIfExists('finance_categories');
        Schema::dropIfExists('finance_account_members');
        Schema::dropIfExists('finance_accounts');
    }
};
