<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_budgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('category_id')->constrained('finance_categories')->cascadeOnDelete();
            $table->char('currency', 3);
            // NULL is a tombstone: no budget from effective_from onwards.
            $table->bigInteger('amount_minor')->nullable();
            $table->date('effective_from');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['category_id', 'currency', 'effective_from'], 'finance_budgets_version_unique');
        });

        DB::statement("ALTER TABLE finance_budgets ADD CONSTRAINT finance_budgets_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE finance_budgets ADD CONSTRAINT finance_budgets_amount_check CHECK (amount_minor IS NULL OR amount_minor BETWEEN 1 AND 10000000000000)');
        DB::statement('ALTER TABLE finance_budgets ADD CONSTRAINT finance_budgets_first_day_check CHECK (EXTRACT(DAY FROM effective_from) = 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_budgets');
    }
};
