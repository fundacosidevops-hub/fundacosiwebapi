<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('in_invoices', function (Blueprint $table) {

            $table->id();

            // paciente
            $table->foreignId('patient_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // doctor que ordena el estudio
            $table->foreignId('doctor_id')
                ->constrained('users');

            // seguro (opcional)
            $table->foreignId('insurance_id')
                ->nullable()
                ->constrained('insurances');

            // autorización del seguro
            $table->integer('authorization_number')->nullable();

            // tipo de facturación
            $table->enum('billing_type', [
                'private',
                'insurance',
            ]);

            // numeración de factura
            $table->string('invoice_number')->unique();

            // estado
            $table->foreignId('status_id')->constrained('in_invoice_statuses');
            // montos
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('tax', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);

            // control
            $table->timestamp('paid_at')->nullable();

            // auditoría
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users');

            $table->foreignId('canceled_by')
                ->nullable()
                ->constrained('users');

            $table->text('cancel_reason')->nullable();
            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('in_invoices');
    }
};
