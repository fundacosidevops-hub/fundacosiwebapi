<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class NationalitiesSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('nationalities')->insert([
            ['description' => 'Dominicana'],
            ['description' => 'Argentina'],
            ['description' => 'Boliviana'],
            ['description' => 'Brasileña'],
            ['description' => 'Chilena'],
            ['description' => 'Colombiana'],
            ['description' => 'Costarricense'],
            ['description' => 'Cubana'],
            ['description' => 'Ecuatoriana'],
            ['description' => 'Salvadoreña'],
            ['description' => 'Española'],
            ['description' => 'Estadounidense'],
            ['description' => 'Francesa'],
            ['description' => 'Guatemalteca'],
            ['description' => 'Haitiana'],
            ['description' => 'Hondureña'],
            ['description' => 'Italiana'],
            ['description' => 'Mexicana'],
            ['description' => 'Nicaragüense'],
            ['description' => 'Paraguaya'],
            ['description' => 'Peruana'],
            ['description' => 'Puertorriqueña'],
            ['description' => 'Uruguaya'],
            ['description' => 'Venezolana'],
            ['description' => 'Extranjero'],
        ]);
    }
}
