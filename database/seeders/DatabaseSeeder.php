<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\PasswordPolicy;
use Illuminate\Support\Str;
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
            InvoiceStatusSeeder::class,
            PaymentMethodsSeeder::class,
            NcfSequencesSeeder::class,
            UserLocationsSeeder::class,
        ]);
        // Contraseña del SuperAdmin inicial: SEED_SUPERADMIN_PASSWORD del .env o,
        // si no está, una aleatoria que se muestra una sola vez en consola.
        $password = (string) env('SEED_SUPERADMIN_PASSWORD', '');
        if ($password === '' || PasswordPolicy::isWeak($password)) {
            $password = Str::password(16);
            $this->command?->warn('Contraseña del SuperAdmin inicial (test@fundacosixxi.com): ' . $password);
            $this->command?->warn('Guárdala ahora: no se vuelve a mostrar.');
        }

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
            'password' => bcrypt($password),
        ])->assignRole('SuperAdmin');
    }
}
