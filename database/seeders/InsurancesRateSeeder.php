<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InsurancesRateSeeder extends Seeder
{
    public function run(): void
    {
        $studies = DB::table('medical_study')->pluck('id');

        // Estudios que tendrán 80%
        $studiesWithZeroPercentage = [18, 19, 21, 22, 23, 30, 48, 50, 52, 53, 55,
            57, 58, 59, 60, 61, 63, 66, 68, 69, 70, 95, 98, 122,
            182, 195, 213, 298, 313, 432, 467, 468, 513, 514, 515, 516, 517, 537, 580,
            608, 642, 644, 672, 673, 696, 720, 726, 774, 778, 783, 784, 785, 788,
            790, 791, 806, 808, 818, 833, 834, 841, 842, 845, 846, 971,
            1549, 1550, 1552, 1556, 1559, 1560, 1562, 1563, 1567, 1570, 1610, 1616, 1630,
            1636, 1811, 1855, 1885, 1886, 1887, 1888, 1890, 1893, 1904, 1905, 1906, 1908,
            1909, 2158, 2159, 2168, 2169, 2208, 2210, 2212, 2214, 2216, 2226, 2228, 2233, 2235,
            2237, 2238, 2335];

        $chunk = [];

        foreach ($studies as $study) {

            for ($insurance = 1; $insurance <= 27; $insurance++) {
                $percentage = 0.00;
                $percentage = in_array($study, $studiesWithZeroPercentage)
                    ? 80.00
                    : 0.00;

                $chunk[] = [
                    'medical_studies_id' => $study,
                    'insurances_id' => $insurance,
                    'percentage' => $percentage,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (count($chunk) == 500) {
                    DB::table('insurances_rate')->insert($chunk);
                    $chunk = [];
                }
            }
        }

        if (! empty($chunk)) {
            DB::table('insurances_rate')->insert($chunk);
        }
    }
}
