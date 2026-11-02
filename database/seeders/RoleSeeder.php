<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // Limpiar cache de permisos
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        /*
        |--------------------------------------------------------------------------
        |  Crear Permisos
        |--------------------------------------------------------------------------
        */

        $permissions = [
            // Doctor
            'doctor.view',
            'doctor.create',
            'doctor.update',
            'doctor.delete',
            // Pacientes
            'patients.view',
            'patients.create',
            'patients.update',
            'patients.delete',

            // Citas
            'appointments.view',
            'appointments.create',
            'appointments.update',
            'appointments.delete',

            // Usuarios
            'users.view',
            'users.create',
            'users.update',
            'users.delete',

            // Facturación / Caja
            'billing.view',
            'billing.create',
            'billing.update',

            // Farmacia
            'pharmacy.view',
            'pharmacy.create',
            'pharmacy.update',

            // Laboratorio
            'laboratory.view',
            'laboratory.create',
            'laboratory.update',
            // Contabilidad / caja
            'accounting.view',
            'accounting.manage',
            'cash.operate',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'api',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        |  Crear Roles
        |--------------------------------------------------------------------------
        */

        $roles = [
            'SuperAdmin',
            'Administrador',
            'Medico',
            'Enfermeria',
            'Recepcion',
            'Caja',
            'Farmacia',
            'Laboratorio',
            'Contabilidad',
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate([
                'name' => $role,
                'guard_name' => 'api',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        |  Asignar Permisos por Rol
        |--------------------------------------------------------------------------
        */

        // SuperAdmin → todos los permisos
        Role::findByName('SuperAdmin', 'api')
            ->givePermissionTo(Permission::all());

        // Administrador
        Role::findByName('Administrador', 'api')
            ->givePermissionTo([
                'patients.view',
                'patients.create',
                'patients.update',
                'appointments.view',
                'appointments.create',
                'users.view',
                'users.create',
                'users.update',
                'billing.view',
                'accounting.view',
                'accounting.manage',
                'cash.operate',
            ]);

        // Médico
        Role::findByName('Medico', 'api')
            ->givePermissionTo([
                'patients.view',
                'appointments.view',
                'appointments.update'
            ]);

        // Enfermería
        Role::findByName('Enfermeria', 'api')
            ->givePermissionTo([
                'patients.view',
                'appointments.view',
            ]);

        // Recepción
        Role::findByName('Recepcion', 'api')
            ->givePermissionTo([
                'patients.create',
                'appointments.create',
                'appointments.view',
            ]);

        // Caja
        Role::findByName('Caja', 'api')
            ->givePermissionTo([
                'billing.view',
                'billing.create',
                'cash.operate',
            ]);

        // Farmacia
        Role::findByName('Farmacia', 'api')
            ->givePermissionTo([
                'pharmacy.view',
                'pharmacy.create',
                'pharmacy.update',
            ]);

        // Laboratorio
        Role::findByName('Laboratorio', 'api')
            ->givePermissionTo([
                'laboratory.view',
                'laboratory.create',
                'laboratory.update',
            ]);

        // Contabilidad
        Role::findByName('Contabilidad', 'api')
            ->givePermissionTo([
                'accounting.view',
                'accounting.manage',
            ]);
    }
}
