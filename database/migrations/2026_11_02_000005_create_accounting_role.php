<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rol "Contabilidad": ve y gestiona la contabilidad (asientos, catálogo,
 * reclamaciones a ARS, cierres de mes). No factura ni cobra en caja.
 */
return new class extends Migration
{
    private array $permissions = ['accounting.view', 'accounting.manage'];

    public function up(): void
    {
        $now = now();
        DB::table('roles')->insertOrIgnore([
            'name' => 'Contabilidad', 'guard_name' => 'api', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $roleId = DB::table('roles')->where('name', 'Contabilidad')->where('guard_name', 'api')->value('id');

        foreach ($this->permissions as $name) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $name, 'guard_name' => 'api', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $permId = DB::table('permissions')->where('name', $name)->where('guard_name', 'api')->value('id');
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permId, 'role_id' => $roleId]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('name', 'Contabilidad')->where('guard_name', 'api')->value('id');
        if ($roleId && ! DB::table('model_has_roles')->where('role_id', $roleId)->exists()) {
            DB::table('role_has_permissions')->where('role_id', $roleId)->delete();
            DB::table('roles')->where('id', $roleId)->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
