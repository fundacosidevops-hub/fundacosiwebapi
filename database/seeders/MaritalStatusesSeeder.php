<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MaritalStatusesSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('marital_statuses')->insert([
            ['description' => 'Soltero (a)'],
            ['description' => 'Casado (a)'],
            ['description' => 'Unión Libre'],
            ['description' => 'Viudo (a)'],
            ['description' => 'Sin especificar'],
        ]);
    }
}
