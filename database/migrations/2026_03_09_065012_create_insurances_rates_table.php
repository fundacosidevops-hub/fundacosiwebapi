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
        Schema::create('insurances_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_studies_id')->nullable()->constrained('medical_studies');
            $table->foreignId('insurances_id')->nullable()->constrained('insurances');
            $table->decimal('percentage', 8, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('insurances_rates');
    }
};
