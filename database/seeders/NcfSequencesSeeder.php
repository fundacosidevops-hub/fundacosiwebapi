<?php

namespace Database\Seeders;

use App\Models\NcfSequences;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class NcfSequencesSeeder extends Seeder
{
    public function run(): void
    {
        $sequences = [
            [
                'type' => 'B02',
                'prefix' => 'B02',
                'from_number' => 1,
                'to_number' => 10000000,
                'current_number' => null,
                'expires_at' => Carbon::now()->addYear(),
                'is_active' => true,
            ],
            [
                'type' => 'B01',
                'prefix' => 'B01',
                'from_number' => 1,
                'to_number' => 10000000,
                'current_number' => null,
                'expires_at' => Carbon::now()->addYear(),
                'is_active' => true,
            ],
            [
                'type' => 'B04',
                'prefix' => 'B04',
                'from_number' => 1,
                'to_number' => 10000000,
                'current_number' => null,
                'expires_at' => Carbon::now()->addYear(),
                'is_active' => true,
            ],
        ];

        foreach ($sequences as $sequence) {
            NcfSequences::updateOrCreate(
                ['type' => $sequence['type']],
                $sequence
            );
        }
    }
}
