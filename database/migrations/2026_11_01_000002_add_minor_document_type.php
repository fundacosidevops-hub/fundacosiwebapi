<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tipo de documento para menores que aún no tienen cédula ni pasaporte.
 * Se identifican por su número de registro y por su tutor.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('document_types')->insertOrIgnore([
            'description' => 'Menor sin documento',
        ]);
    }

    public function down(): void
    {
        $id = DB::table('document_types')
            ->where('description', 'Menor sin documento')
            ->value('id');

        if ($id && ! DB::table('users')->where('document_type_id', $id)->exists()) {
            DB::table('document_types')->where('id', $id)->delete();
        }
    }
};
