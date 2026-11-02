<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contabilidad de partida doble.
 *
 * - acc_accounts: catálogo de cuentas (árbol). Solo las cuentas de detalle
 *   (is_postable) reciben movimientos.
 * - acc_account_mappings: qué cuenta usa cada asiento automático
 *   (ingresos por servicios, CxC ARS, formas de pago, etc.).
 * - acc_periods: meses contables; un mes cerrado no acepta asientos.
 * - acc_journal_entries / acc_journal_lines: asientos y sus líneas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->enum('type', ['asset', 'liability', 'equity', 'income', 'expense']);
            $table->enum('nature', ['debit', 'credit']);
            $table->foreignId('parent_id')->nullable()->constrained('acc_accounts')->nullOnDelete();
            $table->boolean('is_postable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('acc_account_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('label', 150);
            $table->foreignId('account_id')->constrained('acc_accounts');
            $table->timestamps();
        });

        Schema::create('acc_periods', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['year', 'month']);
        });

        Schema::create('acc_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('sequence');
            $table->string('number', 20)->unique();
            $table->date('date');
            $table->string('description', 255);
            // invoice, payment, ars_payment, cash_close, manual, reversal
            $table->string('source_type', 30);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->enum('status', ['posted', 'reversed'])->default('posted');
            $table->foreignId('reversal_of_id')->nullable()->constrained('acc_journal_entries');
            $table->decimal('total', 14, 2);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['year', 'sequence']);
            // Un asiento automático por documento (evita duplicados).
            $table->unique(['source_type', 'source_id']);
            $table->index('date');
        });

        Schema::create('acc_journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('acc_journal_entries')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('acc_accounts');
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->string('description', 255)->nullable();
            // Dimensiones para auxiliares y reportes
            $table->foreignId('insurance_id')->nullable()->constrained('insurances');
            $table->foreignId('patient_id')->nullable()->constrained('users');
            $table->foreignId('catalog_services_id')->nullable()->constrained('catalog_services');
            $table->foreignId('payment_method_id')->nullable()->constrained('in_payment_methods');
            $table->timestamps();
            $table->index(['account_id', 'entry_id']);
            $table->index('insurance_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_journal_lines');
        Schema::dropIfExists('acc_journal_entries');
        Schema::dropIfExists('acc_periods');
        Schema::dropIfExists('acc_account_mappings');
        Schema::dropIfExists('acc_accounts');
    }
};
