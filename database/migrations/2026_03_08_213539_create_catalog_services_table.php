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
        Schema::create('catalog_services', function (Blueprint $table) {
            $table->id();
            $table->string('description');
            $table->boolean('inventory')->default(true);
            $table->boolean('ambulatory')->default(true);
            $table->boolean('internment')->default(true);
            $table->boolean('emergency')->default(true);
            $table->boolean('pattern')->default(true);
            $table->boolean('rate')->default(true);
            $table->boolean('is_active')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services_catalog');
    }
};
