<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('in_invoice_items', function (Blueprint $table) {

            $table->id();

            // factura
            $table->foreignId('invoice_id')
                ->constrained('in_invoices')
                ->cascadeOnDelete();

            // estudio medico
            $table->foreignId('medical_study_id')
                ->nullable()
                ->constrained('medical_studies');

            // cantidad
            $table->integer('quantity')->default(1);

            // precio unitario
            $table->decimal('unit_price', 10, 2);

            // descuento por item
            $table->decimal('discount', 10, 2)->default(0);

            // monto cubierto por seguro
            $table->decimal('insurance_coverage', 10, 2)->default(0);

            // monto que paga el paciente
            $table->decimal('patient_amount', 10, 2)->default(0);

            // total del item
            $table->decimal('total', 10, 2);

            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('in_invoice_items');
    }
};
