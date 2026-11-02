<?php

namespace App\Http\Controllers\Clinical;

use App\Models\Clinical\Encounter;
use App\Models\User;
use Carbon\Carbon;

/** Utilidades compartidas por los controladores del historial clínico. */
trait Concerns
{
    /** Exige al menos uno de los permisos indicados. */
    protected function authorizeAny(string ...$permissions): void
    {
        $user = auth()->user();
        foreach ($permissions as $p) {
            if ($user?->can($p)) {
                return;
            }
        }
        abort(403, 'No tienes permiso para esta sección del historial clínico.');
    }

    protected function hasPerm(string $permission): bool
    {
        return (bool) auth()->user()?->can($permission);
    }

    protected function fail(string $message, int $status = 422)
    {
        return response()->json(['isSuccess' => false, 'message' => $message], $status);
    }

    /** Datos básicos del paciente para encabezados. */
    protected function patientCard(User $p): array
    {
        $p->loadMissing(['insurance:id,description', 'holder:id,name,last_name,phone']);
        $age = $p->birth_date ? Carbon::parse($p->birth_date)->diff(now()) : null;

        return [
            'id' => $p->id,
            'name' => $p->name,
            'last_name' => $p->last_name,
            'document_number' => $p->document_number,
            'gender' => $p->gender,
            'birth_date' => $p->birth_date,
            'age' => $age ? ($age->y > 0 ? "{$age->y} años" : "{$age->m} meses") : null,
            'age_years' => $age?->y,
            'phone' => $p->phone,
            'insurance' => $p->insurance?->description,
            'policy' => $p->policy,
            'holder' => $p->holder ? [
                'id' => $p->holder->id,
                'name' => trim($p->holder->name . ' ' . $p->holder->last_name),
                'phone' => $p->holder->phone,
            ] : null,
        ];
    }

    /** El médico de la consulta (o cualquiera con permiso si aún no tiene médico). */
    protected function assertDoctorOf(Encounter $e): ?\Illuminate\Http\JsonResponse
    {
        if ($e->doctor_id && (int) $e->doctor_id !== (int) auth()->id() && ! auth()->user()->hasRole('SuperAdmin')) {
            $d = $e->doctor;

            return $this->fail('Esta consulta es de ' . trim(($d?->name ?? '') . ' ' . ($d?->last_name ?? '')) . '. Solo ese médico puede modificarla o firmarla.', 403);
        }

        return null;
    }

    protected function personName(?User $u): ?string
    {
        return $u ? trim($u->name . ' ' . $u->last_name) : null;
    }
}
