<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('module', 20);
            $table->smallInteger('level');
            $table->timestamps();
            $table->unique(['user_id', 'module']);
        });

        DB::statement("ALTER TABLE module_permissions ADD CONSTRAINT module_permissions_module_check CHECK (module IN ('todo','wishlist','finance','health'))");
        DB::statement('ALTER TABLE module_permissions ADD CONSTRAINT module_permissions_level_check CHECK (level BETWEEN 0 AND 2)');
    }

    public function down(): void
    {
        Schema::dropIfExists('module_permissions');
    }
};
