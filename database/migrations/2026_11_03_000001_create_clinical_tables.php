<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial clínico.
 *
 * Expediente del paciente: antecedentes, alergias, medicamentos de uso
 * continuo y vacunas. Consultas: triaje (signos vitales de enfermería) →
 * consulta del médico → firmada (ya no se modifica; solo notas aclaratorias).
 * Cada consulta lleva su receta y sus órdenes de laboratorio o imágenes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cl_backgrounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('blood_type', 5)->nullable();
            $table->text('personal')->nullable();     // patológicos personales
            $table->text('family')->nullable();       // heredofamiliares
            $table->text('surgical')->nullable();     // quirúrgicos y hospitalizaciones
            $table->text('gyneco')->nullable();       // gineco-obstétricos
            $table->text('habits')->nullable();       // tabaco, alcohol, drogas, actividad física
            $table->text('other')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('cl_allergies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('users')->cascadeOnDelete();
            $table->string('substance', 150);
            $table->string('reaction', 255)->nullable();
            $table->enum('severity', ['leve', 'moderada', 'grave'])->default('moderada');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('cl_medications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('dose', 100)->nullable();
            $table->string('frequency', 100)->nullable();
            $table->boolean('active')->default(true);
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('cl_vaccines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 150);
            $table->date('applied_on')->nullable();
            $table->string('dose', 50)->nullable();
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('cl_encounters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('users');
            $table->foreignId('doctor_id')->nullable()->constrained('users');
            // Una consulta por factura (la atención que se pagó).
            $table->foreignId('invoice_id')->nullable()->unique()->constrained('in_invoices')->nullOnDelete();
            $table->enum('status', ['triage', 'in_progress', 'signed'])->default('in_progress');
            $table->text('reason')->nullable();           // motivo de consulta
            $table->text('current_illness')->nullable();  // enfermedad actual
            $table->text('physical_exam')->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('plan')->nullable();
            $table->text('indications')->nullable();      // indicaciones al paciente
            $table->text('notes')->nullable();            // notas de especialidad
            $table->timestamp('started_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->foreignId('signed_by')->nullable()->constrained('users');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['patient_id', 'created_at']);
            $table->index(['doctor_id', 'created_at']);
        });

        Schema::create('cl_vital_signs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('users');
            $table->foreignId('encounter_id')->nullable()->constrained('cl_encounters')->cascadeOnDelete();
            $table->decimal('weight_kg', 6, 2)->nullable();
            $table->decimal('height_cm', 6, 2)->nullable();
            $table->decimal('bmi', 5, 2)->nullable();
            $table->decimal('temperature_c', 4, 1)->nullable();
            $table->unsignedSmallInteger('systolic')->nullable();
            $table->unsignedSmallInteger('diastolic')->nullable();
            $table->unsignedSmallInteger('heart_rate')->nullable();
            $table->unsignedSmallInteger('respiratory_rate')->nullable();
            $table->unsignedTinyInteger('spo2')->nullable();
            $table->unsignedSmallInteger('glucose')->nullable();
            $table->string('notes', 255)->nullable();
            $table->foreignId('taken_by')->nullable()->constrained('users');
            $table->timestamp('taken_at');
            $table->timestamps();
        });

        Schema::create('cl_prescription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('encounter_id')->constrained('cl_encounters')->cascadeOnDelete();
            $table->string('medication', 150);
            $table->string('presentation', 100)->nullable(); // tabletas 500 mg, jarabe…
            $table->string('dose', 100)->nullable();
            $table->string('frequency', 100)->nullable();
            $table->string('duration', 100)->nullable();
            $table->string('route', 50)->nullable();         // oral, IM, tópica…
            $table->string('quantity', 50)->nullable();
            $table->string('instructions', 255)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('cl_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('encounter_id')->constrained('cl_encounters')->cascadeOnDelete();
            $table->enum('type', ['laboratorio', 'imagen', 'referimiento', 'otro'])->default('laboratorio');
            $table->string('description', 255);
            $table->string('notes', 255)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('cl_addenda', function (Blueprint $table) {
            $table->id();
            $table->foreignId('encounter_id')->constrained('cl_encounters')->cascadeOnDelete();
            $table->text('text');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('cl_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('users');
            $table->foreignId('encounter_id')->nullable()->constrained('cl_encounters')->nullOnDelete();
            $table->enum('category', ['laboratorio', 'imagen', 'documento', 'otro'])->default('laboratorio');
            $table->string('title', 150);
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users');
            $table->softDeletes();
            $table->timestamps();
        });

        // Quién vio, firmó, imprimió o descargó cada expediente.
        Schema::create('cl_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('users');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('action', 30);
            $table->string('detail', 255)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['patient_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['cl_access_logs', 'cl_attachments', 'cl_addenda', 'cl_orders', 'cl_prescription_items',
            'cl_vital_signs', 'cl_encounters', 'cl_vaccines', 'cl_medications', 'cl_allergies', 'cl_backgrounds'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
