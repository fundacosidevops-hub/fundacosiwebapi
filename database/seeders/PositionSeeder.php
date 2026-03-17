<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PositionSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('positions')->upsert([
            ['description' => 'Cardiólogo/a Ecocardiografista'],
            ['description' => 'Cardiología y Medicina Intensiva'],
            ['description' => 'Soporte Tecnico'],
            ['description' => 'Desarrollador de Aplicaciones'],
            ['description' => 'Cajero/a'],
            ['description' => 'Sin cargo'],
            ['description' => 'Odontología'],
            ['description' => 'Neumología'],
            ['description' => 'Ginecología y Obstetricia'],
            ['description' => 'Psiquiatría'],
            ['description' => 'Medicina Familiar'],
            ['description' => 'Nutrición Clínica'],
            ['description' => 'Medicina Interna'],
            ['description' => 'Sonografía'],
            ['description' => 'Nefrología'],
            ['description' => 'Patología'],
            ['description' => 'Gastroenterología'],
            ['description' => 'Otorrinolaringología'],
            ['description' => 'Psicología'],
            ['description' => 'Emergenciología'],
            ['description' => 'Medicina General'],
            ['description' => 'Neurología'],
            ['description' => 'Radiología'],
            ['description' => 'Dermatología'],
            ['description' => 'Pediatría'],
            ['description' => 'Pediatría y Perinatología'],
            ['description' => 'Oftalmología'],
            ['description' => 'Cirugía General'],
            ['description' => 'Urología'],
            ['description' => 'Traumatología y Ortopedia'],
            ['description' => 'Diabetología'],
            ['description' => 'Anestesiología'],

        ],
            ['description'], // clave única
            []);
    }
}
