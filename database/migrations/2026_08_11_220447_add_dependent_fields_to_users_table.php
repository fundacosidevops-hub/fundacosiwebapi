<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('dependent_of')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('relationship_type_id')
                ->nullable()
                ->constrained('relationship_types')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['dependent_of']);
            $table->dropForeign(['relationship_type_id']);

            $table->dropColumn([
                'dependent_of',
                'relationship_type_id',
            ]);
        });
    }
};
