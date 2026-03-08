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
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            $table->string('avatar')->nullable();
            $table->string('name', 50);
            $table->string('last_name', 50);
            $table->string('email', 100)->unique();
            $table->foreignId('position_id')->nullable()->constrained('positions');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('phone', 15)->nullable();
            $table->string('policy', 20)->nullable();
            $table->string('exequatur', 11)->nullable();
            $table->boolean('staff_physician')->default(true);
            $table->enum('gender', ['Masculino', 'Femenino'])->nullable();
            $table->foreignId('marital_status_id')->nullable()->constrained('marital_statuses');
            $table->foreignId('nationalities_id')->nullable()->constrained('nationalities');
            $table->foreignId('user_type_id')->nullable()->constrained('user_types');
            $table->date('birth_date')->nullable();
            $table->foreignId('document_type_id')->nullable()->constrained('document_types');
            $table->string('document_number', 25)->nullable();
            $table->foreignId('insurance_id')->nullable()->constrained('insurances');
            $table->boolean('is_active')->default(true);
            $table->integer('created_user')->nullable();

            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
