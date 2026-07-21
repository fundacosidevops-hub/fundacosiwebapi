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
        Schema::create('medical_assistances', function (Blueprint $table) {
            $table->id();

            // Relación con la tabla usuarios
            $table->foreignId('doctor_id')
                  ->constrained('users')
                  ->cascadeOnDelete();

            // Horario
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();

            // Cantidad de pacientes
            $table->unsignedInteger('patient_quantity');
            $table->boolean('is_active')->default(false);

            // Fecha del siguiente día o próxima asistencia
            $table->date('next_date');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medical_assistances');
    }
};
