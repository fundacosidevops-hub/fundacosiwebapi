<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas por cobrar a ARS.
 *
 * La parte que cubre el seguro queda por cobrar a la ARS desde que se
 * factura. Las facturas se agrupan en reclamaciones; cuando la ARS paga, se
 * registra la liquidación: lo pagado va al banco y lo rechazado (glosa) a gasto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_ars_claims', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('insurance_id')->constrained('insurances');
            $table->date('period_from');
            $table->date('period_to');
            // draft = en preparación, sent = enviada, partial = pagada en parte, paid = saldada
            $table->enum('status', ['draft', 'sent', 'partial', 'paid'])->default('draft');
            $table->decimal('total_claimed', 14, 2)->default(0);
            $table->decimal('total_paid', 14, 2)->default(0);
            $table->decimal('total_glosa', 14, 2)->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->string('ars_reference', 60)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('acc_ars_claim_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_id')->constrained('acc_ars_claims')->cascadeOnDelete();
            // Cada factura solo puede reclamarse una vez.
            $table->foreignId('invoice_id')->unique()->constrained('in_invoices');
            $table->string('authorization_number', 40)->nullable();
            $table->decimal('amount_claimed', 14, 2);
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->decimal('amount_glosa', 14, 2)->default(0);
            $table->string('glosa_reason', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('acc_ars_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_id')->constrained('acc_ars_claims');
            $table->date('date');
            $table->decimal('amount_paid', 14, 2);
            $table->decimal('amount_glosa', 14, 2)->default(0);
            $table->foreignId('bank_account_id')->constrained('acc_accounts');
            $table->string('reference', 60)->nullable();
            $table->json('items');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_ars_payments');
        Schema::dropIfExists('acc_ars_claim_items');
        Schema::dropIfExists('acc_ars_claims');
    }
};
