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
        ]);
        User::factory()->create([
            'name' => 'User Test',
            'email' => 'test@fundacosixxi.com',
            'position_id' => 4,
            'password' => bcrypt('123456'),
        ])->assignRole('SuperAdmin');
    }
}
