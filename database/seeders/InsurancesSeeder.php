<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InsurancesSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('insurances')->insert([
            ['rnc' => null, 'description' => 'APS DOMINICANA, ARS', 'abbreviation' => 'APS', 'is_active' => true],
            ['rnc' => '101069912', 'description' => 'ARS MAPFRE SALUD', 'abbreviation' => 'MAPFR', 'is_active' => true],
            ['rnc' => null, 'description' => 'ARS SENASA LARIMAL', 'abbreviation' => 'SENLA', 'is_active' => true],
            ['rnc' => '101875887', 'description' => 'ARS SENASA PENSIONADO Y JUBILADO', 'abbreviation' => 'SENP', 'is_active' => true],
            ['rnc' => '430059277', 'description' => 'ARS-UASD', 'abbreviation' => 'UASD', 'is_active' => true],
            ['rnc' => '101687827', 'description' => 'ASEMAP (AMOR Y PAZ)', 'abbreviation' => 'AAP', 'is_active' => true],
            ['rnc' => '101687827', 'description' => 'ASEMAP, ARS', 'abbreviation' => 'MAPFR', 'is_active' => true],
            ['rnc' => null, 'description' => 'BANCO CENTRAL', 'abbreviation' => 'SIMMA', 'is_active' => true],
            ['rnc' => null, 'description' => 'COLEGIO MEDICO DOMINICANO (CMD)', 'abbreviation' => 'ADUL', 'is_active' => true],
            ['rnc' => '101049928', 'description' => 'CONSTITUCION ARS', 'abbreviation' => 'BMI', 'is_active' => true],
            ['rnc' => '101557542', 'description' => 'FUTURO, ARS', 'abbreviation' => 'FUTUR', 'is_active' => true],
            ['rnc' => '101673451', 'description' => 'GRUPO MEDICO Y ASOCIADOS (GMA)', 'abbreviation' => 'CASIS', 'is_active' => true],
            ['rnc' => '102017174', 'description' => 'HUMANO SEGUROS S.A. ARS', 'abbreviation' => 'HUMAN', 'is_active' => true],
            ['rnc' => '430006612', 'description' => 'IDOPPRIL', 'abbreviation' => 'ARL', 'is_active' => true],
            ['rnc' => '130058008', 'description' => 'LA MONUMENTAL, ARS', 'abbreviation' => 'MONUM', 'is_active' => true],
            ['rnc' => '124032423', 'description' => 'METASALUD, ARS', 'abbreviation' => 'METAS', 'is_active' => true],
            ['rnc' => '101761581', 'description' => 'PALIC SALUD PBS, ARS', 'abbreviation' => 'PALIC', 'is_active' => true],
            ['rnc' => '101864427', 'description' => 'PRIMERA ARS HUMANO, ARS', 'abbreviation' => 'PRIME', 'is_active' => true],
            ['rnc' => '430089885', 'description' => 'RESERVAS, ARS', 'abbreviation' => 'BANRE', 'is_active' => true],
            ['rnc' => null, 'description' => 'SALUD SEGURA, ARS', 'abbreviation' => 'SEGUR', 'is_active' => true],
            ['rnc' => '101886481', 'description' => 'SEGUROS RENACER, ARS', 'abbreviation' => 'RENAC', 'is_active' => true],
            ['rnc' => '101864427', 'description' => 'SEMMA ARS', 'abbreviation' => 'PRIMP', 'is_active' => true],
            ['rnc' => null, 'description' => 'SENASA CONTRIBUTIVO', 'abbreviation' => 'SNNS', 'is_active' => true],
            ['rnc' => null, 'description' => 'SENASA SUBSIDIADO', 'abbreviation' => 'SSN', 'is_active' => true],
            ['rnc' => '401516454', 'description' => 'SENASA, ARS', 'abbreviation' => 'SENAS', 'is_active' => true],
            ['rnc' => '101064129', 'description' => 'SIMAG, ARS', 'abbreviation' => 'SIMAG', 'is_active' => true],
            ['rnc' => null, 'description' => 'UNIVERSAL', 'abbreviation' => null, 'is_active' => true],

        ]);
    }
}
