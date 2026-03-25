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
        Schema::create('in_payments', function (Blueprint $table) {

            $table->id();

            // factura
            $table->foreignId('invoices_id')->unique()
                ->constrained('in_invoices')
                ->cascadeOnDelete();

            // metodo de pago
            $table->foreignId('payment_method_id')
                ->constrained('in_payment_methods');

            // monto pagado
            $table->decimal('amount', 10, 2);

            // referencia (voucher, transferencia, etc)
            $table->string('reference')->nullable();

            // fecha del pago
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('in_payments');
    }
};
