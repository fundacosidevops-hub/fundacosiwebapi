<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CatalogServicesSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('catalog_services')->insert([
            ['description' => 'AUDIOMETRIA', 'inventory' => 0, 'ambulatory' => 1, 'internment' => 1, 'emergency' => 0, 'pattern' => 0, 'rate' => 1, 'is_active' => 1],
            ['description' => 'CAFETERIA', 'inventory' => 1, 'ambulatory' => 1, 'internment' => 0, 'emergency' => 0, 'pattern' => 0, 'rate' => 1, 'is_active' => 1],
            ['description' => 'CARDIOLOGIA', 'inventory' => 1, 'ambulatory' => 1, 'internment' => 1, 'emergency' => 0, 'pattern' => 1, 'rate' => 1, 'is_active' => 1],
            ['description' => 'CIRUGIA MENOR', 'inventory' => 0, 'ambulatory' => 0, 'internment' => 0, 'emergency' => 0, 'pattern' => 0, 'rate' => 1, 'is_active' => 1],
            ['description' => 'CONSULTA MÉDICA', 'inventory' => 1, 'ambulatory' => 1, 'internment' => 0, 'emergency' => 0, 'pattern' => 0, 'rate' => 1, 'is_active' => 1],
            ['description' => 'COPIA', 'inventory' => 0, 'ambulatory' => 0, 'internment' => 0, 'emergency' => 0, 'pattern' => 0, 'rate' => 1, 'is_active' => 1],
            ['description' => 'DERMATOLOGIA', 'inventory' => 0, 'ambulatory' => 0, 'internment' => 0, 'emergency' => 0, 'pattern' => 0, 'rate' => 1, 'is_active' => 1],
            ['description' => 'ECO-DOPPLER', 'inventory' => 0, 'ambulatory' => 1, 'internment' => 1, 'emergency' => 0, 'pattern' => 1, 'rate' => 1, 'is_active' => 1],
            ['description' => 'EMERGENCIA', 'inventory' => 1, 'ambulatory' => 1, 'internment' => 0, 'emergency' => 1, 'pattern' => 0, 'rate' => 1, 'is_active' => 1],
            ['description' => 'ENDOSCOPIA', 'inventory' => 0, 'ambulatory' => 1, 'internment' => 1, 'emergency' => 0, 'pattern' => 1, 'rate' => 1, 'is_active' => 1],
            ['description' => 'EQUIPOS', 'inventory' => 1, 'ambulatory' => 0, 'internment' => 0, 'emergency' => 0, 'pattern' => 0, 'rate' => 1, 'is_active' => 1],
            ['description' => 'ERROR ELIMINAR', 'inventory' => 0, 'ambulatory' => 0, 'internment' => 0, 'emergency' => 0, 'pattern' => 0, 'rate' => 1, 'is_active' => 1],
            ['description' => 'ESTUDIOS ESPECIALES', 'inventory' => 1, 'ambulatory' => 1, 'internment' => 0, 'emergency' => 1, 'pattern' => 0, 'rate' => 1, 'is_active' => 1],
            ['description' => 'GASTOS CLINICOS', 'inventory' => 0, 'ambulatory' => 1, 'internment' => 1, 'emergency' => 0, 'pattern' => 0, 'rate' => 1, 'is_active' => 1],
            ['description' => 'GINECOLOGIA', 'inventory' => 0, 'ambulatory' => 1, 'internment' => 0, 'emergency' => 0, 'pattern' => 0, 'rate' => 1, 'is_active' => 1],
            ['description' => 'HONORARIOS MÉDICOS', 'inventory' => 1, 'ambulatory' => 1, 'internment' => 1, 'emergency' => 0, 'pattern' => 1, 'rate' => 1, 'is_active' => 1],
            ['description' => 'HOSPITALIZACIÓN', 'inventory' => 0, 'ambulatory' => 0, 'internment' => 1, 'emergency' => 0, 'pattern' => 0, 'rate' => 1, 'is_active' => 1],
            ['description' => 'LABORATORIO', 'inventory' => 1, 'ambulatory' => 1, 'internment' => 0, 'emergency' => 0, 'pattern' => 0, 'rate' => 1, 'is_active' => 1],
            ['description' => 'MAMOGRAFIA', 'inventory' => 0, 'ambulatory' => 1, 'internment' => 0, 'emergency' => 1, 'pattern' => 1, 'rate' => 1, 'is_active' => 1],
            ['description' => 'MATERIAL GASTABLE', 'inventory' => 1, 'ambulatory' => 1, 'internment' => 1, 'emergency' => 1, 'pattern' => 0, 'rate' => 1, 'is_active' => 1],
            ['description' => 'MEDICAMENTOS', 'inventory' => 1, 'ambulatory' => 0, 'internment' => 1, 'emergency' => 1, 'pattern' => 0, 'rate' => 1, 'is_active' => 1],
            ['description' => 'MISCELANEO', 'inventory' => 0, 'ambulatory' => 1, 'internment' => 0, 'emergency' => 0, 'pattern' => 0, 'rate' => 1, 'is_active' => 1],
            ['description' => 'NEUMOLOGIA', 'inventory' => 0, 'ambulatory' => 1, 'internment' => 0, 'emergency' => 1, 'pattern' => 0, 'rate' => 1, 'is_active' => 1],
            ['description' => 'ODONTOLOGIA', 'inventory' => 1, 'ambulatory' => 1, 'internment' => 1, 'emergency' => 1, 'pattern' => 0, 'rate' => 1, 'is_active' => 1],
            ['description' => 'ORTOPEDA', 'inventory' => 0, 'ambulatory' => 1, 'internment' => 0, 'emergency' => 1, 'pattern' => 0, 'rate' => 1, 'is_active' => 1],
            ['description' => 'PATOLOGIA', 'inventory' => 0, 'ambulatory' => 1, 'internment' => 1, 'emergency' => 1, 'pattern' => 0, 'rate' => 1, 'is_active' => 1],
            ['description' => 'PROCEDIMIENTO', 'inventory' => 0, 'ambulatory' => 1, 'internment' => 1, 'emergency' => 0, 'pattern' => 1, 'rate' => 1, 'is_active' => 1],
            ['description' => 'PSICOLOGIA', 'inventory' => 0, 'ambulatory' => 1, 'internment' => 0, 'emergency' => 0, 'pattern' => 0, 'rate' => 1, 'is_active' => 1],
            ['description' => 'RAYOS X', 'inventory' => 1, 'ambulatory' => 1, 'internment' => 1, 'emergency' => 0, 'pattern' => 1, 'rate' => 1, 'is_active' => 1],
            ['description' => 'RESONANCIA', 'inventory' => 1, 'ambulatory' => 0, 'internment' => 0, 'emergency' => 0, 'pattern' => 1, 'rate' => 0, 'is_active' => 1],
            ['description' => 'SALA DE CIRUGÍA', 'inventory' => 1, 'ambulatory' => 0, 'internment' => 1, 'emergency' => 0, 'pattern' => 0, 'rate' => 1, 'is_active' => 1],
            ['description' => 'SONOGRAFÍA', 'inventory' => 1, 'ambulatory' => 1, 'internment' => 1, 'emergency' => 0, 'pattern' => 1, 'rate' => 1, 'is_active' => 1],
            ['description' => 'TOMOGRAFÍA', 'inventory' => 1, 'ambulatory' => 1, 'internment' => 1, 'emergency' => 0, 'pattern' => 1, 'rate' => 1, 'is_active' => 1],
            ['description' => 'VACUNAS', 'inventory' => 1, 'ambulatory' => 1, 'internment' => 0, 'emergency' => 1, 'pattern' => 0, 'rate' => 0, 'is_active' => 1],
            ['description' => 'NEUROLOGIA', 'inventory' => 1, 'ambulatory' => 1, 'internment' => 0, 'emergency' => 1, 'pattern' => 0, 'rate' => 0, 'is_active' => 1],

        ]);
    }
}
