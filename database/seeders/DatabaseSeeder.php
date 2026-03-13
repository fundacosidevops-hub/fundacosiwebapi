<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PositionSeeder::class,
            DocumentTypeSeeder::class,
            InsurancesSeeder::class,
            MaritalStatusesSeeder::class,
            UserTypeSeeder::class,
            NationalitiesSeeder::class,
            CatalogServicesSeeder::class,
            MedicalStudiesSeeder::class,
            InsurancesRateSeeder::class,
        ]);
        User::factory()->create([
            'name' => 'User',
            'last_name' => 'Test',
            'email' => 'test@fundacosixxi.com',
            'position_id' => 4,
            'gender' => 'Masculino',
            'marital_status_id' => 1,
            'nationalities_id' => 1,
            'user_type_id' => 1,
            'document_type_id' => 1,
            'insurance_id' => 1,
            'password' => bcrypt('123456'),
        ])->assignRole('SuperAdmin');
    }
}
