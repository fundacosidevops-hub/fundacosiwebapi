<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PositionSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('positions')->insert([
            ['description' => 'Encargado Cardologia'],
            ['description' => 'Cardiologo'],
            ['description' => 'Soporte Tecnico'],
            ['description' => 'Desarrollador de Aplicaciones'],
            ['description' => 'Cajero/a'],
            ['description' => 'Sin cargo'],
        ]);
    }
}
