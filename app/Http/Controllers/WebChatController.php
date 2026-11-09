<?php

namespace App\Http\Controllers;

use App\Models\AppointmentRequest;
use App\Models\CatalogServices;
use App\Models\Insurances;
use App\Models\MedicalCatalogServices;
use App\Models\User;
use App\Services\AuditLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * API pública del chatbot del sitio web (rutas /v1/web/*, clave X-Web-Key).
 *
 * Solo expone datos aptos para el público: nombres de servicios y médicos,
 * horario del día y seguros aceptados. Nunca devuelve documentos, correos,
 * teléfonos ni datos de pacientes.
 */
class WebChatController extends Controller
{
    private const DOCTOR_TYPE = 3;

    /** Servicios activos con sus médicos (solo nombre) y seguros aceptados. */
    public function info()
    {
        $hidden = array_map('mb_strtoupper', (array) config('webchat.hidden_services', []));

        $services = CatalogServices::where('is_active', true)
            ->orderBy('description')
            ->get(['id', 'description'])
            ->reject(fn ($s) => in_array(mb_strtoupper($s->description), $hidden, true))
            ->values();

        $doctorsByService = $this->doctorsByService($services->pluck('id')->all());

        return response()->json([
            'isSuccess' => true,
            'services' => $services->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->description,
                'doctors' => $doctorsByService[$s->id] ?? [],
            ])->values(),
            'insurances' => Insurances::where(function ($q) {
                $q->where('is_active', true)->orWhereNull('is_active');
            })
                ->orderBy('description')
                ->get(['id', 'description', 'abbreviation'])
                ->map(fn ($i) => [
                    'id' => $i->id,
                    'name' => $i->description,
                    'abbreviation' => $i->abbreviation,
                ])->values(),
        ]);
    }

    /**
     * Médicos que atienden en una fecha (hoy o mañana), con hora de inicio
     * y cupos. Usa la misma regla que el kiosco: la asistencia del día se
     * registra el día anterior (medical_assistances.next_date = fecha).
     * Query: date=today|tomorrow (por defecto today), service_id opcional.
     */
    public function schedule(Request $request)
    {
        $validated = $request->validate([
            'date' => 'nullable|in:today,tomorrow',
            'service_id' => 'nullable|integer',
        ]);

        $day = ($validated['date'] ?? 'today') === 'tomorrow' ? Carbon::tomorrow() : Carbon::today();
        $isToday = $day->isToday();

        $rows = DB::table('medical_assistances as ma')
            ->join('users as u', 'u.id', '=', 'ma.doctor_id')
            ->leftJoin('medical_catalog_services as mcs', 'mcs.users_id', '=', 'u.id')
            ->leftJoin('catalog_services as cs', 'cs.id', '=', 'mcs.catalog_services_id')
            ->where('u.user_type_id', self::DOCTOR_TYPE)
            ->where(fn ($q) => $q->where('u.is_active', true)->orWhereNull('u.is_active'))
            ->where('ma.is_active', true)
            ->whereDate('ma.next_date', $day->toDateString())
            ->when(! empty($validated['service_id']), fn ($q) => $q->where('cs.id', $validated['service_id']))
            ->orderBy('ma.start_time')
            ->get([
                'u.id as doctor_id', 'u.name', 'u.last_name',
                'cs.id as service_id', 'cs.description as service',
                'ma.start_time', 'ma.patient_quantity',
            ])
            ->unique('doctor_id')
            ->values();

        // Cupos usados hoy = facturas del médico hoy (misma regla que el kiosco).
        $used = [];
        if ($isToday && $rows->isNotEmpty()) {
            $used = DB::table('in_invoices')
                ->whereIn('doctor_id', $rows->pluck('doctor_id'))
                ->whereDate('created_at', $day->toDateString())
                ->groupBy('doctor_id')
                ->pluck(DB::raw('COUNT(*)'), 'doctor_id')
                ->all();
        }

        return response()->json([
            'isSuccess' => true,
            'date' => $day->toDateString(),
            'doctors' => $rows->map(function ($r) use ($used, $isToday) {
                $quota = (int) $r->patient_quantity;
                $taken = (int) ($used[$r->doctor_id] ?? 0);

                return [
                    'id' => $r->doctor_id,
                    'name' => trim($r->name . ' ' . $r->last_name),
                    'service_id' => $r->service_id,
                    'service' => $r->service,
                    'start_time' => $r->start_time ? substr($r->start_time, 0, 5) : null,
                    'quota' => $quota,
                    'available' => $isToday ? max(0, $quota - $taken) : $quota,
                ];
            })->values(),
        ]);
    }

    /** Registra una solicitud de cita. El personal llama para confirmarla. */
    public function storeAppointment(Request $request)
    {
        $validated = $request->validate([
            'patient_name' => 'required|string|min:3|max:120',
            'phone' => ['required', 'string', 'max:25', 'regex:/^\+?[0-9\-\s().]{10,25}$/'],
            'service_id' => 'nullable|integer|exists:catalog_services,id',
            'service_name' => 'required_without:service_id|nullable|string|max:120',
            'doctor_id' => 'nullable|integer',
            'preferred_date' => 'required|string|max:120',
            'preferred_time' => 'nullable|string|max:60',
            'reason' => 'nullable|string|max:300',
        ]);

        $clean = fn (?string $v) => $v === null ? null : trim(strip_tags($v));
        $phoneDigits = preg_replace('/\D/', '', $validated['phone']);

        $max = (int) config('webchat.max_requests_per_phone', 3);
        $today = AppointmentRequest::whereDate('created_at', Carbon::today())
            ->where('phone', $phoneDigits)->count();
        if ($today >= $max) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Ya hay varias solicitudes con este teléfono hoy. El personal se comunicará pronto.',
            ], 429);
        }

        $service = ! empty($validated['service_id'])
            ? CatalogServices::where('is_active', true)->find($validated['service_id'])
            : null;

        // El médico solo se acepta si es doctor activo de ese servicio.
        $doctor = null;
        if (! empty($validated['doctor_id'])) {
            $doctor = User::where('user_type_id', self::DOCTOR_TYPE)
                ->where(fn ($q) => $q->where('is_active', true)->orWhereNull('is_active'))
                ->find($validated['doctor_id']);
            if ($doctor && $service && ! MedicalCatalogServices::where('users_id', $doctor->id)
                ->where('catalog_services_id', $service->id)->exists()) {
                $doctor = null;
            }
        }

        $appointment = AppointmentRequest::create([
            'code' => $this->newCode(),
            'patient_name' => $clean($validated['patient_name']),
            'phone' => $phoneDigits,
            'catalog_services_id' => $service?->id,
            'service_name' => $service?->description ?? $clean($validated['service_name']),
            'doctor_id' => $doctor?->id,
            'doctor_name' => $doctor ? trim($doctor->name . ' ' . $doctor->last_name) : null,
            'preferred_date' => $clean($validated['preferred_date']),
            'preferred_time' => $clean($validated['preferred_time'] ?? null),
            'reason' => $clean($validated['reason'] ?? null),
            'status' => 'pending',
            'source' => 'web-chat',
        ]);

        AuditLog::record('appointment_request.created', 'appointment_request', $appointment->id,
            "Solicitud de cita {$appointment->code} desde el sitio web ({$appointment->service_name})");

        return response()->json([
            'isSuccess' => true,
            'code' => $appointment->code,
            'service' => $appointment->service_name,
            'doctor' => $appointment->doctor_name,
            'message' => 'Solicitud registrada. El personal llamará para confirmar.',
        ], 201);
    }

    /** @return array<int, array<int, array{id:int,name:string}>> */
    private function doctorsByService(array $serviceIds): array
    {
        if (! $serviceIds) {
            return [];
        }

        return DB::table('medical_catalog_services as mcs')
            ->join('users as u', 'u.id', '=', 'mcs.users_id')
            ->whereIn('mcs.catalog_services_id', $serviceIds)
            ->where('u.user_type_id', self::DOCTOR_TYPE)
            ->where(fn ($q) => $q->where('u.is_active', true)->orWhereNull('u.is_active'))
            ->orderBy('u.name')
            ->get(['mcs.catalog_services_id', 'u.id', 'u.name', 'u.last_name'])
            ->groupBy('catalog_services_id')
            ->map(fn ($g) => $g->unique('id')->map(fn ($d) => [
                'id' => $d->id,
                'name' => trim($d->name . ' ' . $d->last_name),
            ])->values()->all())
            ->all();
    }

    private function newCode(): string
    {
        do {
            $code = 'C-' . now()->format('ymd') . '-' . strtoupper(Str::random(4));
        } while (AppointmentRequest::where('code', $code)->exists());

        return $code;
    }
}
