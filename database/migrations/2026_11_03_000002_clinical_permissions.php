<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permisos del historial clínico. Por confidencialidad, Administrador NO los
 * recibe automáticamente: se le dan a quien atiende pacientes.
 *  - clinical.view    ver expedientes
 *  - clinical.write   consultas, diagnóstico, receta, firmar (médicos)
 *  - clinical.triage  signos vitales, alergias y antecedentes (enfermería)
 *  - clinical.attach  subir resultados y documentos
 */
return new class extends Migration
{
    private array $grant = [
        'SuperAdmin' => ['clinical.view', 'clinical.write', 'clinical.triage', 'clinical.attach'],
        'Medico' => ['clinical.view', 'clinical.write', 'clinical.triage', 'clinical.attach'],
        'Enfermeria' => ['clinical.view', 'clinical.triage', 'clinical.attach'],
    ];

    public function up(): void
    {
        $now = now();
        foreach (['clinical.view', 'clinical.write', 'clinical.triage', 'clinical.attach'] as $name) {
            DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'api', 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach ($this->grant as $role => $perms) {
            $roleId = DB::table('roles')->where('name', $role)->where('guard_name', 'api')->value('id');
            if (! $roleId) {
                continue;
            }
            foreach ($perms as $perm) {
                $permId = DB::table('permissions')->where('name', $perm)->where('guard_name', 'api')->value('id');
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permId, 'role_id' => $roleId]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->where('name', 'like', 'clinical.%')->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
