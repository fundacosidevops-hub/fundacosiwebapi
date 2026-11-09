<?php

namespace App\Http\Controllers;

use App\Models\AppointmentRequest;
use App\Services\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Bandeja del personal para las solicitudes de cita del sitio web.
 * Acceso: quien tiene acceso a Facturación (ver rutas).
 */
class AppointmentRequestController extends Controller
{
    /** Lista paginada. Filtros: status, q (nombre, teléfono o código), from, to (Y-m-d). */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(AppointmentRequest::STATUSES)],
            'q' => 'nullable|string|max:80',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:5|max:100',
        ]);

        $q = AppointmentRequest::with('handler:id,name,last_name')
            ->when($validated['status'] ?? null, fn ($w, $s) => $w->where('status', $s))
            ->when($validated['from'] ?? null, fn ($w, $d) => $w->whereDate('created_at', '>=', $d))
            ->when($validated['to'] ?? null, fn ($w, $d) => $w->whereDate('created_at', '<=', $d))
            ->when($validated['q'] ?? null, function ($w, $term) {
                $digits = preg_replace('/\D/', '', $term);
                $w->where(function ($x) use ($term, $digits) {
                    $x->where('patient_name', 'like', "%{$term}%")
                        ->orWhere('code', 'like', "%{$term}%");
                    if ($digits !== '') {
                        $x->orWhere('phone', 'like', "%{$digits}%");
                    }
                });
            })
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at');

        return response()->json([
            'isSuccess' => true,
            'pending' => AppointmentRequest::where('status', 'pending')->count(),
            'data' => $q->paginate($validated['per_page'] ?? 25),
        ]);
    }

    /** Cambia el estado y/o las notas del personal. */
    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(AppointmentRequest::STATUSES)],
            'staff_notes' => 'nullable|string|max:1000',
        ]);

        $item = AppointmentRequest::findOrFail($id);
        $old = $item->only(['status', 'staff_notes']);

        $item->update([
            'status' => $validated['status'],
            'staff_notes' => $validated['staff_notes'] ?? $item->staff_notes,
            'handled_by' => auth('api')->id(),
            'handled_at' => now(),
        ]);

        AuditLog::record('appointment_request.updated', 'appointment_request', $item->id,
            "Solicitud {$item->code}: {$old['status']} → {$item->status}", $old, $item->only(['status', 'staff_notes']));

        return response()->json(['isSuccess' => true, 'data' => $item->fresh('handler:id,name,last_name')]);
    }
}
