<?php

namespace Database\Seeders;


use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RelationshipTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('relationship_types')->upsert(
            [
                ['description' => 'Hijo/a'], 
                ['description' => 'Cónyuge'],
                ['description' => 'Padre'],
                ['description' => 'Madre'],
                ['description' => 'Hermano/a'], 
                ['description' => 'Otro'],
            ],
            ['description']
        );
    }
}
