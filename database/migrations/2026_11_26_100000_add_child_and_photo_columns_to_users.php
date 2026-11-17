<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Children: a users row without e-mail that can never sign in (sign_in_disabled). The database itself refuses a row
 * without e-mail that holds any credential. Profile photo columns for the same phase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('sign_in_disabled')->default(false);
            $table->string('photo_path')->nullable();
            $table->string('photo_thumb_path')->nullable();
            $table->unsignedInteger('photo_version')->default(0);
        });

        // Raw DDL on purpose: ->change() would restate the column and risk the dependent unique indexes.
        DB::statement('ALTER TABLE users ALTER COLUMN email DROP NOT NULL');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_email_not_blank CHECK (email IS NULL OR email <> '')");
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_child_no_credentials CHECK (email IS NOT NULL OR (sign_in_disabled AND google_id IS NULL AND NOT password_set AND two_factor_secret IS NULL))');

        Schema::table('invitations', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('users')->whereNull('email')->exists()) {
            throw new RuntimeException('Remove child accounts (users without e-mail) before rolling back this migration.');
        }

        Schema::table('invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_child_no_credentials');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_email_not_blank');
        DB::statement('ALTER TABLE users ALTER COLUMN email SET NOT NULL');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['sign_in_disabled', 'photo_path', 'photo_thumb_path', 'photo_version']);
        });
    }
};
