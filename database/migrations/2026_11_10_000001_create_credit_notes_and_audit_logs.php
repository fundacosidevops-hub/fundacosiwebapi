<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Anulaciones con nota de crédito (NCF B04) y bitácora de acciones.
 *
 * - in_credit_notes: una nota de crédito por factura (anulación total).
 * - sys_audit_logs: quién hizo qué y cuándo (facturas, cobros, cajas,
 *   médicos, posiciones, usuarios, contabilidad, ARS, NCF, accesos).
 * - Estado «Anulada» en in_invoice_statuses.
 *
 * No crea la secuencia B04: el rango lo autoriza la DGII y se registra en
 * Ajustes → Comprobantes NCF.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('in_credit_notes')) {
            Schema::create('in_credit_notes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('invoice_id')->unique()->constrained('in_invoices');
                $table->string('ncf_number', 19)->unique();
                $table->text('reason');
                // Monto de la nota = servicios facturados (paciente + cobertura).
                $table->decimal('amount', 12, 2);
                $table->decimal('patient_amount', 12, 2)->default(0);
                $table->decimal('coverage', 12, 2)->default(0);
                // Devolución al paciente si la factura estaba cobrada.
                $table->decimal('refund_amount', 12, 2)->default(0);
                $table->foreignId('refund_method_id')->nullable()->constrained('in_payment_methods');
                $table->unsignedBigInteger('cash_session_id')->nullable()->index();
                $table->timestamp('refunded_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sys_audit_logs')) {
            Schema::create('sys_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('user_name', 120)->nullable();
                $table->string('action', 60)->index();
                $table->string('entity_type', 40)->nullable()->index();
                $table->unsignedBigInteger('entity_id')->nullable()->index();
                $table->string('description', 500);
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->timestamp('created_at')->nullable()->index();
            });
        }

        if (Schema::hasTable('in_invoice_statuses')
            && ! DB::table('in_invoice_statuses')->where('name', 'Anulada')->exists()) {
            DB::table('in_invoice_statuses')->insert([
                'name' => 'Anulada',
                'description' => 'Factura anulada con nota de crédito (B04)',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sys_audit_logs');
        Schema::dropIfExists('in_credit_notes');
        // El estado «Anulada» se deja: puede haber facturas que lo usen.
    }
};
