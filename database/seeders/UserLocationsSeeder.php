<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserLocationsSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('user_locations')->upsert(
            [
                ['description' => 'Principal'],
                ['description' => 'Carpa'],
                ['description' => 'Analitica'],
            ],
            ['description']
        );
    }
}
