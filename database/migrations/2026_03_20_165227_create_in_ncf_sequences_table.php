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
        Schema::create('in_ncf_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // B02, B01, etc.
            $table->string('prefix'); // B02
            $table->unsignedBigInteger('from_number');
            $table->unsignedBigInteger('to_number');
            $table->unsignedBigInteger('current_number')->nullable();
            $table->date('expires_at');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('in_ncf_sequences');
    }
};
