<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda en cada factura el turno del que salió (para mostrar el número de
 * turno y el orden de llegada en Historial clínico → Pacientes del día).
 * Las facturas anteriores se relacionan con su turno por paciente, médico,
 * servicio y fecha, así que esta columna es opcional.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('in_invoices') && ! Schema::hasColumn('in_invoices', 'queue_manager_id')) {
            Schema::table('in_invoices', function (Blueprint $table) {
                $table->unsignedBigInteger('queue_manager_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('in_invoices', 'queue_manager_id')) {
            Schema::table('in_invoices', function (Blueprint $table) {
                $table->dropIndex(['queue_manager_id']);
                $table->dropColumn('queue_manager_id');
            });
        }
    }
};
