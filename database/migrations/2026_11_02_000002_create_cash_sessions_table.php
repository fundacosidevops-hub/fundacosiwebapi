<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuadre de caja por cajero: cada cobro queda dentro de una caja abierta.
 * Al cerrar se compara el efectivo contado con el esperado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamp('opened_at');
            $table->decimal('opening_amount', 14, 2)->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->decimal('expected_cash', 14, 2)->nullable();
            $table->decimal('counted_cash', 14, 2)->nullable();
            $table->decimal('difference', 14, 2)->nullable();
            $table->json('totals_by_method')->nullable();
            $table->unsignedInteger('payments_count')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::table('in_payments', function (Blueprint $table) {
            $table->foreignId('cash_session_id')->nullable()->after('payment_method_id')
                ->constrained('acc_cash_sessions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('in_payments', function (Blueprint $table) {
            $table->dropForeign(['cash_session_id']);
            $table->dropColumn('cash_session_id');
        });
        Schema::dropIfExists('acc_cash_sessions');
    }
};
