<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('in_invoice_logs', function (Blueprint $table) {

            $table->id();

            // factura afectada
            $table->foreignId('invoice_id')
                ->constrained('in_invoices')
                ->cascadeOnDelete();

            // usuario que hizo la acción
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users');

            // acción realizada
            $table->string('action');

            // descripción más detallada
            $table->text('description')->nullable();

            // datos antes del cambio
            $table->json('old_values')->nullable();

            // datos después del cambio
            $table->json('new_values')->nullable();

            // ip del usuario
            $table->string('ip_address')->nullable();

            // navegador o sistema
            $table->string('user_agent')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('in_invoice_logs');
    }
};
