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
        Schema::create('queue_manager', function (Blueprint $table) {
            $table->id();

            $table->string('queue_code');

            $table->foreignId('patient_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('assign_user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->enum('billing_type', [
                'private',
                'insured',
            ]);
            // seguro
            $table->foreignId('insurance_id')
                ->nullable()
                ->constrained('insurances');

            // catalogo de servicio
            $table->foreignId('catalog_services_id')
                ->nullable()
                ->constrained('catalog_services');

            // doctor que ordena el estudio
            $table->foreignId('doctor_id')
                ->constrained('users');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('queue_manager');
    }
};
