<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MaritalStatusesSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('marital_statuses')->upsert(
            [
                ['description' => 'Soltero (a)'],
                ['description' => 'Casado (a)'],
                ['description' => 'Divorciado (a)'],
                ['description' => 'Unión Libre'],
                ['description' => 'Viudo (a)'],
                ['description' => 'Sin especificar'],
            ],
            ['description'], // columna única
            []
        );
    }
}
