<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitudes de cita que llegan desde el chatbot del sitio web.
 * No son citas confirmadas: el personal llama al paciente y cambia el estado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_requests', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('patient_name', 120);
            $table->string('phone', 25);
            $table->foreignId('catalog_services_id')->nullable()->constrained('catalog_services')->nullOnDelete();
            $table->string('service_name', 120);
            $table->foreignId('doctor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('doctor_name', 120)->nullable();
            $table->string('preferred_date', 120);
            $table->string('preferred_time', 60)->nullable();
            $table->string('reason', 300)->nullable();
            // pending | contacted | scheduled | cancelled | no_answer
            $table->string('status', 20)->default('pending')->index();
            $table->text('staff_notes')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->string('source', 30)->default('web-chat');
            $table->timestamps();

            $table->index('created_at');
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_requests');
    }
};
