<?php

namespace App\Http\Controllers;

use App\Services\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Ajustes → Bitácora (solo administradores). Solo lectura. */
class AuditLogController extends Controller
{
    /** Grupos de acciones para el filtro (prefijo de «action»). */
    public const GROUPS = [
        'invoice' => 'Facturas',
        'payment' => 'Cobros',
        'cash' => 'Cajas',
        'doctor' => 'Médicos',
        'position' => 'Posiciones',
        'user' => 'Usuarios',
        'auth' => 'Accesos',
        'accounting' => 'Contabilidad',
        'ars' => 'ARS',
        'ncf' => 'Comprobantes NCF',
        'dgii' => 'Reportes DGII',
    ];

    public function index(Request $request)
    {
        $v = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'group' => 'nullable|string|max:20',
            'user_id' => 'nullable|integer',
            'search' => 'nullable|string|max:100',
            'entity_type' => 'nullable|string|max:40',
            'entity_id' => 'nullable|integer',
            'per_page' => 'nullable|integer|min:10|max:200',
        ]);

        if (! AuditLog::enabled()) {
            return response()->json([
                'enabled' => false,
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 50, 'total' => 0],
            ]);
        }

        $q = DB::table('sys_audit_logs')
            ->when($v['from'] ?? null, fn ($w, $d) => $w->where('created_at', '>=', $d . ' 00:00:00'))
            ->when($v['to'] ?? null, fn ($w, $d) => $w->where('created_at', '<=', $d . ' 23:59:59'))
            ->when($v['group'] ?? null, fn ($w, $g) => $w->where('action', 'like', $g . '.%'))
            ->when($v['user_id'] ?? null, fn ($w, $u) => $w->where('user_id', $u))
            ->when($v['entity_type'] ?? null, fn ($w, $t) => $w->where('entity_type', $t))
            ->when($v['entity_id'] ?? null, fn ($w, $id) => $w->where('entity_id', $id))
            ->when(trim($v['search'] ?? ''), fn ($w, $s) => $w->where(fn ($x) => $x->where('description', 'like', "%{$s}%")
                ->orWhere('user_name', 'like', "%{$s}%")));

        $page = $q->orderByDesc('id')->paginate($v['per_page'] ?? 50);

        return response()->json([
            'enabled' => true,
            'data' => collect($page->items())->map(fn ($r) => [
                'id' => $r->id,
                'created_at' => $r->created_at,
                'user_id' => $r->user_id,
                'user' => $r->user_name,
                'action' => $r->action,
                'group' => self::GROUPS[strtok($r->action, '.')] ?? strtok($r->action, '.'),
                'entity_type' => $r->entity_type,
                'entity_id' => $r->entity_id,
                'description' => $r->description,
                'old' => $r->old_values ? json_decode($r->old_values, true) : null,
                'new' => $r->new_values ? json_decode($r->new_values, true) : null,
                'ip' => $r->ip_address,
            ]),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** Opciones de los filtros: grupos y usuarios que aparecen en la bitácora. */
    public function meta()
    {
        $users = AuditLog::enabled()
            ? DB::table('sys_audit_logs')->whereNotNull('user_id')
                ->groupBy('user_id', 'user_name')->orderBy('user_name')
                ->get(['user_id as id', 'user_name as name'])
            : [];

        return response()->json([
            'enabled' => AuditLog::enabled(),
            'groups' => collect(self::GROUPS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'users' => $users,
        ]);
    }
}
