<?php

namespace App\Http\Controllers;

use App\Models\MedicalCatalogServices;
use App\Models\User;
use App\Services\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ajustes → mantenimientos (solo SuperAdmin y Administrador, ver rutas):
 *  - Médicos por especialidad: mover un doctor a otro servicio, o asignarle
 *    uno si no tiene.
 *  - Posiciones: crear y renombrar registros de la tabla positions.
 *
 * Un doctor es un usuario de tipo «Doctor» (user_type_id = 3) y su
 * especialidad vive en medical_catalog_services (users_id → catalog_services_id),
 * igual que en Usuarios del sistema y en el kiosco.
 */
class CatalogAdminController extends Controller
{
    private const DOCTOR_TYPE = 3;

    /* ------------------------------------------------------------------ */
    /* Médicos por especialidad                                            */
    /* ------------------------------------------------------------------ */

    public function doctorServices()
    {
        $doctors = User::where('user_type_id', self::DOCTOR_TYPE)
            ->orderBy('name')->orderBy('last_name')
            ->get(['id', 'name', 'last_name', 'is_active']);

        // Una especialidad por doctor: si hubiera más de una fila, manda la primera.
        $assigned = MedicalCatalogServices::whereIn('users_id', $doctors->pluck('id'))
            ->orderBy('id')
            ->get(['users_id', 'catalog_services_id'])
            ->unique('users_id')
            ->pluck('catalog_services_id', 'users_id');

        $services = DB::table('catalog_services')
            ->orderBy('description')
            ->get(['id', 'description', 'is_active']);

        $row = fn ($d) => [
            'id' => $d->id,
            'name' => trim($d->name . ' ' . $d->last_name),
            'is_active' => $d->is_active !== false && $d->is_active !== 0,
            'service_id' => $assigned[$d->id] ?? null,
        ];
        $list = $doctors->map($row)->values();

        return response()->json([
            'services' => $services->map(fn ($s) => [
                'id' => $s->id,
                'description' => $s->description,
                'is_active' => (bool) $s->is_active,
                'doctors' => $list->where('service_id', $s->id)->values(),
            ])->values(),
            'unassigned' => $list->whereNull('service_id')->values(),
            'doctors' => $list,
        ]);
    }

    public function setDoctorService(Request $request, int $id)
    {
        $v = $request->validate([
            'serviceId' => 'required|integer|exists:catalog_services,id',
        ], [
            'serviceId.required' => 'Elige la especialidad.',
            'serviceId.exists' => 'La especialidad no existe.',
        ]);

        $doctor = User::find($id);
        if (! $doctor || (int) $doctor->user_type_id !== self::DOCTOR_TYPE) {
            return $this->fail('El usuario no es un doctor.', 404);
        }

        $active = DB::table('catalog_services')->where('id', $v['serviceId'])->value('is_active');
        if (! $active) {
            return $this->fail('Esa especialidad está inactiva.');
        }

        $current = MedicalCatalogServices::where('users_id', $doctor->id)->orderBy('id')->first();
        if ($current && (int) $current->catalog_services_id === (int) $v['serviceId']) {
            return $this->fail('El doctor ya está en esa especialidad.');
        }

        $beforeId = $current?->catalog_services_id;

        // Mover (si ya tenía especialidad) o agregar (si no tenía).
        if ($current) {
            $current->update(['catalog_services_id' => $v['serviceId']]);
        } else {
            MedicalCatalogServices::create([
                'users_id' => $doctor->id,
                'catalog_services_id' => $v['serviceId'],
            ]);
        }

        $service = DB::table('catalog_services')->where('id', $v['serviceId'])->value('description');
        $name = trim($doctor->name . ' ' . $doctor->last_name);
        $before = $beforeId ? DB::table('catalog_services')->where('id', $beforeId)->value('description') : null;
        AuditLog::record('doctor.service_changed', 'user', $doctor->id,
            $before ? "Movió a {$name} de {$before} a {$service}" : "Agregó a {$name} a {$service}",
            ['service' => $before], ['service' => $service]);

        return response()->json([
            'isSuccess' => true,
            'moved' => (bool) $current,
            'message' => $current
                ? "{$name} ahora está en {$service}."
                : "{$name} se agregó a {$service}.",
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Posiciones                                                          */
    /* ------------------------------------------------------------------ */

    public function positions()
    {
        // Consulta directa: la tabla no tiene timestamps y la relación
        // Positions::users() del modelo apunta a otra columna.
        $counts = DB::table('users')->whereNotNull('position_id')
            ->groupBy('position_id')->selectRaw('position_id, COUNT(*) as total')
            ->pluck('total', 'position_id');

        return response()->json(
            DB::table('positions')->orderBy('description')->get(['id', 'description'])
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'description' => $p->description,
                    'users' => (int) ($counts[$p->id] ?? 0),
                ])
        );
    }

    public function storePosition(Request $request)
    {
        $description = $this->positionName($request);
        if ($this->positionExists($description)) {
            return $this->fail('Ya existe una posición con ese nombre.');
        }

        $id = DB::table('positions')->insertGetId(['description' => $description]);
        AuditLog::record('position.created', 'position', $id, "Creó la posición «{$description}»");

        return response()->json([
            'isSuccess' => true,
            'message' => "Posición «{$description}» creada.",
            'data' => ['id' => $id, 'description' => $description, 'users' => 0],
        ], 201);
    }

    public function updatePosition(Request $request, int $id)
    {
        if (! DB::table('positions')->where('id', $id)->exists()) {
            return $this->fail('La posición no existe.', 404);
        }

        $description = $this->positionName($request);
        if ($this->positionExists($description, $id)) {
            return $this->fail('Ya existe una posición con ese nombre.');
        }

        $old = DB::table('positions')->where('id', $id)->value('description');
        DB::table('positions')->where('id', $id)->update(['description' => $description]);
        AuditLog::record('position.renamed', 'position', $id, "Renombró la posición «{$old}» a «{$description}»",
            ['description' => $old], ['description' => $description]);

        return response()->json([
            'isSuccess' => true,
            'message' => 'Posición actualizada.',
            'data' => ['id' => $id, 'description' => $description],
        ]);
    }

    private function positionName(Request $request): string
    {
        $v = $request->validate([
            'description' => 'required|string|min:2|max:50',
        ], [
            'description.required' => 'Escribe el nombre de la posición.',
            'description.min' => 'El nombre es muy corto.',
            'description.max' => 'El nombre admite hasta 50 caracteres.',
        ]);

        // Espacios de más fuera; primera letra en mayúscula (como las existentes).
        $name = preg_replace('/\s+/u', ' ', trim($v['description']));

        return Str::ucfirst($name);
    }

    private function positionExists(string $description, ?int $exceptId = null): bool
    {
        return DB::table('positions')->whereRaw('LOWER(description) = ?', [Str::lower($description)])
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();
    }

    private function fail(string $message, int $status = 422)
    {
        return response()->json(['isSuccess' => false, 'message' => $message], $status);
    }
}
