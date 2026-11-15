<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Archive instead of delete (owner decision B of the production release): a person or record older than
 * medical.delete_window_hours can only be archived. An archived row stays in the database and on disk, is hidden by the
 * single scoped query layer (App\Medical\Visibility) and can be restored by a member with Edit. archived_by is the user
 * who archived it; a user who is removed only clears that column, never the record. Existing grants of the tables cover
 * the new columns.
 */
return new class extends Migration
{
    private const TABLES = [
        'medical_subjects' => 'medical_subjects_archived_index',
        'medical_visits' => 'medical_visits_archived_index',
        'medical_medications' => 'medical_medications_archived_index',
        'medical_allergies' => 'medical_allergies_archived_index',
        'medical_vaccinations' => 'medical_vaccinations_archived_index',
        'medical_documents' => 'medical_documents_archived_index',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $index) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->timestampTz('archived_at')->nullable();
                $blueprint->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            });
            // The Archive listing reads only archived rows, which are few: a partial index keeps it small.
            $column = $table === 'medical_subjects' ? 'archived_at' : 'subject_id, archived_at';
            DB::statement("CREATE INDEX {$index} ON {$table} ({$column}) WHERE archived_at IS NOT NULL");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('archived_by');
                $blueprint->dropColumn('archived_at');
            });
        }
    }
};
