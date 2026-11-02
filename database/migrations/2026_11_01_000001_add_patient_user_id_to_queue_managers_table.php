<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda en el turno el ID exacto del paciente.
 *
 * patient_id sigue guardando el documento con el que se sacó el turno
 * (el del tutor cuando el turno es para un menor), y patient_user_id
 * identifica a la persona que se va a atender, tenga o no documento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_managers', function (Blueprint $table) {
            $table->foreignId('patient_user_id')
                ->nullable()
                ->after('patient_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('queue_managers', function (Blueprint $table) {
            $table->dropForeign(['patient_user_id']);
            $table->dropColumn('patient_user_id');
        });
    }
};
