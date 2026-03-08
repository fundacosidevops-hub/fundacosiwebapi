<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MedicalStudySeeder extends Seeder
{
    public function run(): void
    {
        DB::table('medical_study')->insert([
            ['description' => 'ACUFENOMETRIA							   ', 'price' => 1500.00, 'is_active' => 1, 'catalog_service_id' => 1, 'created_user' => null, 'created_at' => null],
            ['description' => 'AUDIOMETRIA TONAR LAMINAR                   ', 'price' => 1500.00, 'is_active' => 1, 'catalog_service_id' => 1, 'created_user' => null, 'created_at' => null],
            ['description' => 'CONTROL DE EPISTAXIS                        ', 'price' => 0.00, 'is_active' => 1, 'catalog_service_id' => 1, 'created_user' => null, 'created_at' => null],
            ['description' => 'CUERPO EXTRAÑO NASAL                        ', 'price' => 800.00, 'is_active' => 1, 'catalog_service_id' => 1, 'created_user' => null, 'created_at' => null],
            ['description' => 'CUERPO EXTRAÑO OTICO                        ', 'price' => 800.00, 'is_active' => 1, 'catalog_service_id' => 1, 'created_user' => null, 'created_at' => null],
            ['description' => 'CURA FARINGITIS CRONICA                     ', 'price' => 300.00, 'is_active' => 1, 'catalog_service_id' => 1, 'created_user' => null, 'created_at' => null],
            ['description' => 'CURA OTOMICOSIS                             ', 'price' => 300.00, 'is_active' => 1, 'catalog_service_id' => 1, 'created_user' => null, 'created_at' => null],
            ['description' => 'EMISIONES OTOACUSTICAS                      ', 'price' => 1500.00, 'is_active' => 1, 'catalog_service_id' => 1, 'created_user' => null, 'created_at' => null],
            ['description' => 'ESTUDIOS DE AUDIOLOGIA                      ', 'price' => 1500.00, 'is_active' => 1, 'catalog_service_id' => 1, 'created_user' => null, 'created_at' => null],
            ['description' => 'HONORARIO MEDICOS OTORRINO                  ', 'price' => 0.00, 'is_active' => 1, 'catalog_service_id' => 1, 'created_user' => null, 'created_at' => null],
            ['description' => 'LAVADO DE OIDO                              ', 'price' => 600.00, 'is_active' => 1, 'catalog_service_id' => 1, 'created_user' => null, 'created_at' => null],
            ['description' => 'LOGOAUDIOMETRIA                             ', 'price' => 1500.00, 'is_active' => 1, 'catalog_service_id' => 1, 'created_user' => null, 'created_at' => null],
            ['description' => 'OTOEMISIONES ACUSTICAS                      ', 'price' => 0.00, 'is_active' => 1, 'catalog_service_id' => 1, 'created_user' => null, 'created_at' => null],
            ['description' => 'POTENCIALES EVOCADOS AUDITIVOS AUDIOLOGIA   ', 'price' => 1500.00, 'is_active' => 1, 'catalog_service_id' => 1, 'created_user' => null, 'created_at' => null],
            ['description' => 'PRUEBA DE FUNCION TUBARICA CON TIMPANOMETRIA', 'price' => 0.00, 'is_active' => 1, 'catalog_service_id' => 1, 'created_user' => null, 'created_at' => null],
            ['description' => 'TIMPANOMETRIA							   ', 'price' => 1500.00, 'is_active' => 1, 'catalog_service_id' => 1, 'created_user' => null, 'created_at' => null],

        ]);
    }
}
