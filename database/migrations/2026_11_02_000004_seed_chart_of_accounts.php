<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Catálogo de cuentas inicial, cuentas de los asientos automáticos y permisos.
 * Las cuentas se pueden editar y ampliar después desde Contabilidad → Catálogo.
 */
return new class extends Migration
{
    /** [código, nombre, tipo, naturaleza, ¿recibe movimientos?] */
    private array $accounts = [
        ['1', 'ACTIVOS', 'asset', 'debit', false],
        ['1.1', 'Activos corrientes', 'asset', 'debit', false],
        ['1.1.01', 'Efectivo en caja', 'asset', 'debit', false],
        ['1.1.01.01', 'Caja general', 'asset', 'debit', true],
        ['1.1.01.02', 'Caja chica', 'asset', 'debit', true],
        ['1.1.02', 'Bancos', 'asset', 'debit', false],
        ['1.1.02.01', 'Banco - cuenta corriente principal', 'asset', 'debit', true],
        ['1.1.03', 'Cuentas por cobrar', 'asset', 'debit', false],
        ['1.1.03.01', 'Cuentas por cobrar ARS', 'asset', 'debit', true],
        ['1.1.03.02', 'Cuentas por cobrar pacientes', 'asset', 'debit', true],
        ['1.1.03.03', 'Tarjetas por liquidar', 'asset', 'debit', true],
        ['1.2', 'Activos no corrientes', 'asset', 'debit', false],
        ['1.2.01', 'Mobiliario y equipos de oficina', 'asset', 'debit', true],
        ['1.2.02', 'Equipos médicos', 'asset', 'debit', true],
        ['1.2.03', 'Depreciación acumulada', 'asset', 'credit', true],

        ['2', 'PASIVOS', 'liability', 'credit', false],
        ['2.1', 'Pasivos corrientes', 'liability', 'credit', false],
        ['2.1.01', 'Cuentas por pagar proveedores', 'liability', 'credit', true],
        ['2.1.02', 'Retenciones por pagar', 'liability', 'credit', true],
        ['2.1.03', 'Nómina por pagar', 'liability', 'credit', true],

        ['3', 'PATRIMONIO', 'equity', 'credit', false],
        ['3.1', 'Fondo social', 'equity', 'credit', true],
        ['3.2', 'Excedentes (déficits) acumulados', 'equity', 'credit', true],
        ['3.3', 'Excedente (déficit) del ejercicio', 'equity', 'credit', true],

        ['4', 'INGRESOS', 'income', 'credit', false],
        ['4.1', 'Ingresos por servicios de salud', 'income', 'credit', false],
        ['4.1.01', 'Servicios médicos y de laboratorio', 'income', 'credit', true],
        ['4.1.02', 'Descuentos concedidos', 'income', 'debit', true],
        ['4.2', 'Otros ingresos', 'income', 'credit', false],
        ['4.2.01', 'Donaciones recibidas', 'income', 'credit', true],
        ['4.2.02', 'Sobrantes de caja', 'income', 'credit', true],
        ['4.2.03', 'Otros ingresos', 'income', 'credit', true],

        ['5', 'GASTOS', 'expense', 'debit', false],
        ['5.1', 'Gastos operativos', 'expense', 'debit', false],
        ['5.1.01', 'Sueldos y salarios', 'expense', 'debit', true],
        ['5.1.02', 'Materiales e insumos médicos', 'expense', 'debit', true],
        ['5.1.03', 'Servicios públicos', 'expense', 'debit', true],
        ['5.1.04', 'Alquileres', 'expense', 'debit', true],
        ['5.1.05', 'Mantenimiento y reparaciones', 'expense', 'debit', true],
        ['5.1.06', 'Depreciación', 'expense', 'debit', true],
        ['5.2', 'Gastos de cobranza', 'expense', 'debit', false],
        ['5.2.01', 'Glosas de ARS (montos no reconocidos)', 'expense', 'debit', true],
        ['5.2.02', 'Comisiones bancarias y de tarjetas', 'expense', 'debit', true],
        ['5.2.03', 'Faltantes de caja', 'expense', 'debit', true],
        ['5.2.04', 'Cuentas incobrables', 'expense', 'debit', true],
    ];

    /** [clave, descripción, código de cuenta] */
    private array $mappings = [
        ['income.services', 'Ingresos por servicios facturados', '4.1.01'],
        ['income.discounts', 'Descuentos en facturas privadas', '4.1.02'],
        ['ar.ars', 'Cuentas por cobrar a ARS (cobertura del seguro)', '1.1.03.01'],
        ['ar.patients', 'Cuentas por cobrar a pacientes', '1.1.03.02'],
        ['ars.glosa', 'Glosas de ARS', '5.2.01'],
        ['bank.default', 'Banco donde depositan las ARS', '1.1.02.01'],
        ['cash.main', 'Caja de los cajeros (efectivo)', '1.1.01.01'],
        ['cash.short', 'Faltantes al cerrar caja', '5.2.03'],
        ['cash.over', 'Sobrantes al cerrar caja', '4.2.02'],
        ['payment.default', 'Forma de pago sin cuenta asignada', '1.1.01.01'],
    ];

    public function up(): void
    {
        $now = now();
        $ids = [];
        foreach ($this->accounts as [$code, $name, $type, $nature, $postable]) {
            $parent = str_contains($code, '.') ? substr($code, 0, strrpos($code, '.')) : null;
            DB::table('acc_accounts')->insertOrIgnore([
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'nature' => $nature,
                'parent_id' => $parent ? ($ids[$parent] ?? null) : null,
                'is_postable' => $postable,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $ids[$code] = DB::table('acc_accounts')->where('code', $code)->value('id');
        }

        foreach ($this->mappings as [$key, $label, $code]) {
            DB::table('acc_account_mappings')->insertOrIgnore([
                'key' => $key, 'label' => $label, 'account_id' => $ids[$code],
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Cada forma de pago va a su cuenta: efectivo a caja, tarjetas a
        // "tarjetas por liquidar" y transferencias al banco.
        foreach (DB::table('in_payment_methods')->get() as $method) {
            $text = strtolower(($method->code ?? '') . ' ' . $method->name);
            $code = match (true) {
                str_contains($text, 'card') || str_contains($text, 'tarjeta') || str_contains($text, 'terjeta') => '1.1.03.03',
                str_contains($text, 'transfer') || str_contains($text, 'cheque') => '1.1.02.01',
                default => '1.1.01.01',
            };
            DB::table('acc_account_mappings')->insertOrIgnore([
                'key' => 'payment_method.' . $method->id,
                'label' => 'Forma de pago: ' . $method->name,
                'account_id' => $ids[$code],
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Permisos: contabilidad para administración; caja para cajeros.
        $permissions = ['accounting.view', 'accounting.manage', 'cash.operate'];
        foreach ($permissions as $name) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $name, 'guard_name' => 'api', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $grant = [
            'SuperAdmin' => $permissions,
            'Administrador' => $permissions,
            'Caja' => ['cash.operate'],
        ];
        foreach ($grant as $roleName => $perms) {
            $roleId = DB::table('roles')->where('name', $roleName)->where('guard_name', 'api')->value('id');
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
        $permIds = DB::table('permissions')
            ->whereIn('name', ['accounting.view', 'accounting.manage', 'cash.operate'])
            ->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $permIds)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $permIds)->delete();
        DB::table('permissions')->whereIn('id', $permIds)->delete();
        DB::table('acc_account_mappings')->delete();
        DB::table('acc_accounts')->update(['parent_id' => null]);
        DB::table('acc_accounts')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
