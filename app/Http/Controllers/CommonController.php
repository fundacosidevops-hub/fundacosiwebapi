<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserInfoResource;
use App\Http\Resources\UserListResource;
use App\Models\CatalogServices;
use App\Models\Insurances;
use App\Models\RelationshipTypes;
use App\Models\InsurancesRate;
use App\Models\MedicalCatalogServices;
use App\Models\MedicalAssistance;
use App\Models\Nationalities;
use App\Models\QueueManager;
use App\Models\User;
use App\Models\UserLocations;
use App\Services\AuditLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class CommonController
{
    /**
     * Corte entre el turno de la mañana y el de la tarde (el único lugar donde
     * se define). Hasta las 10:15:59 se ofrecen los médicos de la mañana
     * (empiezan antes de las 10:15); desde las 10:16:00, los de la tarde
     * (empiezan a las 10:15 o después). Así cada médico sale en un solo turno.
     * Facturación avisa en rojo los últimos SHIFT_WARN_MINUTES minutos y el PDF
     * de horarios usa este mismo corte (ver shiftInfo()).
     */
    private const SHIFT_CUTOFF = '10:15:00';
    private const SHIFT_WARN_MINUTES = 15;

    /**
     * Días sin turno de la tarde (ISO: 1 = lunes … 6 = sábado, 7 = domingo).
     * Esos días se factura todo el día con todos los médicos, sin el corte
     * de la mañana/tarde ni la alerta.
     */
    private const SHIFT_NO_SPLIT_DAYS = [6];

    private function isNoSplitDay(Carbon $date): bool
    {
        return in_array($date->dayOfWeekIso, self::SHIFT_NO_SPLIT_DAYS, true);
    }

    /** Momento en que la lista pasa a ser la del turno de la tarde. */
    private function shiftSwitchAt(Carbon $now): Carbon
    {
        [$h, $m] = array_map('intval', explode(':', self::SHIFT_CUTOFF));

        return $now->copy()->setTime($h, $m, 59)->addSecond();
    }

    /**
     * Facturación: hora del servidor y momento del cambio de turno, para
     * mostrar la alerta con el mismo reloj con el que se filtran los médicos.
     */
    public function shiftInfo()
    {
        $now = Carbon::now();
        $switchAt = $this->shiftSwitchAt($now);

        $noSplit = $this->isNoSplitDay($now);

        return response()->json([
            'now' => $now->toIso8601String(),
            'cutoff' => substr(self::SHIFT_CUTOFF, 0, 5),
            'switch_at' => $switchAt->toIso8601String(),
            'warn_minutes' => self::SHIFT_WARN_MINUTES,
            'shift' => $noSplit ? 'all_day' : ($now->lt($switchAt) ? 'morning' : 'afternoon'),
            // Días sin turno de la tarde (ISO 1–7) y si hoy es uno de ellos.
            'no_split_days' => self::SHIFT_NO_SPLIT_DAYS,
            'split_today' => ! $noSplit,
        ]);
    }

    #[OA\Get(
        path: '/api/v1/common/insurance',
        summary: 'Obtener todos los seguros a facturar',
        tags: ['Common'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Datos obtenido correctamente'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    public function getInsurance()
    {
        return response()->json(Insurances::all());
    }
    #[OA\Get(
        path: '/api/v1/common/medical-studies',
        summary: 'Obtener todos los estudios.',
        tags: ['Common'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'insurance_id',
                description: 'ID del seguro',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
            new OA\Parameter(
                name: 'service_id',
                description: 'ID del servicio',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datos obtenido correctamente'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    public function getStudiesByInsurance(Request $request)
    {
        return response()->json(
            InsurancesRate::with('medicalStudies')
                ->where('insurances_id', $request->insurance_id)
                ->where('is_active', true)
                ->get()
        );
    }
    #[OA\Get(
        path: '/api/v1/common/catalog-services',
        summary: 'Obtener todos los catalogos de servicios.',
        tags: ['Common'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Datos obtenido correctamente'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    public function getCatalogServices()
    {
        return response()->json(
            CatalogServices::with('medicalCatalogServices.users')
                ->where('is_active', true)
                ->get()
                ->map(function ($service) {
                    return [
                        'id' => $service->id,
                        'description' => $service->description,
                        'doctors' => $service->medicalCatalogServices
                            ->filter(fn($item) => $item->users)
                            ->map(function ($item) {
                                return [
                                    'id' => $item->users->id,
                                    'name' => $item->users->name . ' ' . $item->users->last_name,
                                ];
                            })->values(),
                    ];
                })
        );
    }
    #[OA\Get(
        path: '/api/v1/common/catalog-services-doctor',
        summary: 'Obtener todos los doctores por ID del servicio.',
        tags: ['Common'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'service_id',
                description: 'ID del doctor',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datos obtenido correctamente'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    public function getDoctorsByCatalogServices(Request $request)
    {

        $now = Carbon::now();
        return response()->json(
            MedicalCatalogServices::with([
                'users',
                'users.medicalAssistances' => function ($query) use ($now) {
                    $query->whereDate('next_date', $now);
                },
                'users.queueManagerDoctor' => function ($query) use ($now) {
                    $query->whereDate('created_at', $now)
                        ->where('status', '!=', 'skip');
                }
            ])
                ->where('catalog_services_id', $request->service_id)
                ->whereHas('users.medicalAssistances', function ($q) use ($now) {
                    $q->where('is_active', true)
                        ->whereDate('next_date', $now)
                        ->whereRaw("
                        patient_quantity >
                        (
                            SELECT COUNT(*)
                            FROM in_invoices
                            WHERE in_invoices.doctor_id = medical_assistances.doctor_id
                            AND DATE(in_invoices.created_at) = CURDATE()
                        )
                    ");

                    // Turno de la mañana hasta el corte; luego, el de la tarde.
                    // Los días sin turno de la tarde (sábado) no hay corte: todos.
                    if ($this->isNoSplitDay($now)) {
                        // sin filtro de hora
                    } elseif ($now->lt($this->shiftSwitchAt($now))) {
                        $q->where('start_time', '<', self::SHIFT_CUTOFF);
                    } else {
                        $q->where('start_time', '>=', self::SHIFT_CUTOFF);
                    }
                })

                ->get()
                ->map(function ($res) {
                    return $res->users;
                })
        );
    }
    #[OA\Get(
        path: '/api/v1/common/patient-info',
        summary: 'Obtener datos del paciente',
        tags: ['Common'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'document_id',
                in: 'query',
                required: true,
                description: 'Documento del paciente',
                schema: new OA\Schema(type: 'string'),
                example: '00107508525'
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datos obtenido correctamente'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    public function getPatientInformation(Request $request)
    {
        $documentId = str_replace(' ', '', (string) $request->documentId);

        if ($documentId === '') {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Paciente no encontrado',
            ], 200);
        }

        $user = User::with([
            'insurance',
            'relationShipDependent.insurance',
            'relationShipDependent.relationShipType',
        ])
            ->where('document_number', $documentId)
            ->first();

        if (! $user) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Paciente no encontrado',
            ], 200);
        }

        // Endpoint público (kiosco): solo se devuelve lo necesario para sacar
        // el turno. Nada de documento, teléfono, correo ni fecha completa.
        $insurance = fn ($u) => $u->insurance
            ? ['id' => $u->insurance->id, 'description' => $u->insurance->description]
            : null;

        return response()->json([
            'isSuccess' => true,
            'message' => '',
            'data' => [
                'id' => $user->id,
                'name' => $this->firstWord($user->name),
                'insurance' => $insurance($user),
                'dependents' => $user->relationShipDependent
                    ->where('is_active', true)
                    ->values()
                    ->map(fn ($d) => [
                        'id' => $d->id,
                        'name' => trim($this->firstWord($d->name) . ' ' . mb_substr((string) $d->last_name, 0, 1) . '.'),
                        'relationship' => $d->relationShipType?->description,
                        'age' => $d->birth_date ? Carbon::parse($d->birth_date)->age : null,
                        'insurance' => $insurance($d),
                    ]),
            ],
        ], 200);
    }

    private function firstWord(?string $value): string
    {
        return explode(' ', trim((string) $value))[0] ?? '';
    }
    #[OA\Post(
        path: '/api/v1/save-ticket',
        summary: 'Guardar ticket',
        tags: ['Common'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code', 'documentId', 'locationId', 'insuranceId', 'catalogServiceId', 'doctorId', 'billingType'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', example: 'AA1'),
                    new OA\Property(property: 'documentId', type: 'string', example: '00118479953'),
                    new OA\Property(property: 'locationId', type: 'integer', example: 1),
                    new OA\Property(property: 'insuranceId', type: 'integer', example: 1),
                    new OA\Property(property: 'catalogServiceId', type: 'integer', example: 1),
                    new OA\Property(property: 'doctorId', type: 'integer', example: 1),
                    new OA\Property(property: 'billingType', type: 'string', example: 'private'),
                    new OA\Property(property: 'specialTurn', type: 'boolean', example: false),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Usuario creado correctamente'),
            new OA\Response(response: 400, description: 'Datos inválidos'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    public function saveTicket(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'regex:/^[\p{Lu}0-9]{1,4}$/u'],
            'documentId' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9-]+$/'],
            'locationId' => 'required|integer',
            'catalogServiceId' => 'required|integer',
            'insuranceId' => 'nullable|integer',
            'doctorId' => 'required|integer',
            'billingType' => 'required|string',
            'specialTurn' => 'nullable|boolean',
            'patientUserId' => 'nullable|integer',
        ]);

        // El turno puede ser para el titular del documento o para uno de sus
        // dependientes (por ejemplo, un menor sin cédula).
        $patientUserId = null;
        if (! empty($validated['patientUserId'])) {
            $holder = User::where('document_number', $validated['documentId'])->first();
            $patient = User::find($validated['patientUserId']);

            $isHolder = $holder && $patient && $patient->id === $holder->id;
            $isDependent = $holder && $patient && (int) $patient->dependent_of === $holder->id;

            if (! $isHolder && ! $isDependent) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'La persona seleccionada no está vinculada a este documento.',
                ], 422);
            }
            $patientUserId = $patient->id;
        }

        // Límites contra abuso: un turno activo por persona y servicio, y un
        // máximo de turnos por documento al día (titular + dependientes).
        $today = QueueManager::where('patient_id', $validated['documentId'])
            ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()]);

        $maxPerDocument = (int) config('kiosk.max_tickets_per_document', 6);
        if ((clone $today)->count() >= $maxPerDocument) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Este documento ya alcanzó el máximo de turnos de hoy. Pase por recepción.',
            ], 429);
        }

        $active = (clone $today)
            ->where('catalog_services_id', $validated['catalogServiceId'])
            ->whereIn('status', ['pending', 'called'])
            ->when(
                $patientUserId,
                fn ($q) => $q->where('patient_user_id', $patientUserId),
                fn ($q) => $q->whereNull('patient_user_id')
            )
            ->value('ticket');
        if ($active) {
            return response()->json([
                'isSuccess' => false,
                'message' => "Esta persona ya tiene el turno {$active} para este servicio.",
            ], 422);
        }

        DB::transaction(function () use ($validated, $patientUserId, &$data) {

            $lastNumber = QueueManager::where('catalog_services_id', $validated['catalogServiceId'])
                ->whereDate('created_at', now())
                ->lockForUpdate()
                ->max('curr_number');

            $nextNumber = $lastNumber ? $lastNumber + 1 : 1;

            $ticket = $validated['code'] . '-' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);

            QueueManager::create([
                'queue_code' => $validated['code'],
                'curr_number' => $nextNumber,
                'ticket' => $ticket,
                'patient_id' => $validated['documentId'],
                'patient_user_id' => $patientUserId,
                'location' => $validated['locationId'],
                'assign_user_id' => null,
                'billing_type' => $validated['billingType'],
                'insurance_id' => $validated['insuranceId'] ?? null,
                'catalog_services_id' => $validated['catalogServiceId'],
                'doctor_id' => $validated['doctorId'],
                'special_turn' => $validated['specialTurn'] ?? false,
            ]);
            $data = QueueManager::with('doctor.position')
                ->where('patient_id', $validated['documentId'])
                ->where('ticket', $ticket)
                ->where('doctor_id', $validated['doctorId'])
                ->whereDate('created_at', now())->first();
        });

        return response()->json($data, 200);
    }
    #[OA\Get(
        path: '/api/v1/common/call-next-queue',
        summary: 'Llamar siguiente turno.',
        tags: ['Common'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'userLocationsId',
                in: 'query',
                required: true,
                description: 'Id ubicacion usuario.',
                schema: new OA\Schema(type: 'string'),
                example: '2'
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datos obtenido correctamente'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    public function callNextQueue(Request $request)
    {
        $validated = $request->validate([
            'userLocationsId' => 'nullable|integer',
        ]);
        //  Verificar si ya tiene turno activo
        $existing = QueueManager::with('user.position')
            ->where('assign_user_id', auth()->id())
            ->where('location', $validated['userLocationsId'])
            ->whereBetween('created_at', [
                Carbon::today()->startOfDay(),
                Carbon::today()->endOfDay(),
            ])
            ->where('status', 'called')
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return response()->json(['isSuccess' => true, 'message' => '', 'data' => $existing], 200);
        }

        // Buscar nuevo turno. Cada cajero responde solo a sus turnos:
        //  1) un turno pendiente que ya tenga asignado (desde el listado). Pero
        //     si de esa misma especialidad hay uno SIN asignar que llegó antes
        //     (se le olvidó asignárselo), se llama ese, el más viejo;
        //  2) si no tiene, uno pendiente SIN asignar.
        // Nunca toma un turno pendiente asignado a otro cajero.
        // En cada caso, primero los preferenciales y luego por orden de llegada.
        $notice = '';
        $q = DB::transaction(function () use ($validated, &$notice) {

            $baseQuery = QueueManager::whereBetween('created_at', [
                Carbon::today()->startOfDay(),
                Carbon::today()->endOfDay(),
            ])
                ->where('status', 'pending')
                ->where('location', $validated['userLocationsId']);

            $next = fn ($query) => (clone $query)
                ->orderByDesc('special_turn')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            $q = $next((clone $baseQuery)->where('assign_user_id', auth()->id()));

            if ($q) {
                // ¿Un turno de la misma especialidad, sin asignar, que llegó antes?
                $older = (clone $baseQuery)
                    ->whereNull('assign_user_id')
                    ->where('catalog_services_id', $q->catalog_services_id)
                    ->where(fn ($w) => $w->where('created_at', '<', $q->created_at)
                        ->orWhere(fn ($x) => $x->where('created_at', $q->created_at)->where('id', '<', $q->id)))
                    // Un preferencial no se adelanta con uno normal más viejo.
                    ->when($q->special_turn, fn ($w) => $w->where('special_turn', true))
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();
                if ($older) {
                    $notice = "Se llamó {$older->ticket} porque llegó antes que {$q->ticket}. "
                        . "{$q->ticket} sigue asignado a ti para después.";
                    $q = $older;
                }
            } else {
                $q = $next((clone $baseQuery)->whereNull('assign_user_id'));
            }

            if (! $q) {
                return null;
            }

            $q->update([
                'assign_user_id' => auth()->id(),
                'status' => 'called',
            ]);

            return $q->load('user.position');
        });

        if (! $q) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'No hay turnos disponible.',
                'data' => [],
            ], 200);
        }

        return response()->json([
            'isSuccess' => true,
            // Aviso informativo cuando se adelantó un turno más viejo (vacío si no).
            'message' => $notice,
            'data' => $q
        ], 200);
    }
    #[OA\Get(
        path: '/api/v1/common/get-ticket-location',
        summary: 'Obtener todos los turnos por ubicacion.',
        tags: ['Common'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'locationId',
                in: 'query',
                required: true,
                description: 'Ubicacion del kiosko',
                schema: new OA\Schema(type: 'string'),
                example: '2'
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datos obtenido correctamente'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    public function getTicketByLocation(Request $request)
    {
        // Pantalla pública: solo el turno y la caja que lo llama. Nada de
        // cédulas, correos ni datos del cajero.
        return response()->json(
            QueueManager::with('user.position')
                ->where('location', (int) $request->locationId)
                ->whereNotNull('assign_user_id')
                ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])
                ->orderBy('id')
                ->get()
                ->map(fn ($q) => [
                    'id' => $q->id,
                    'ticket' => $q->ticket,
                    'curr_number' => $q->curr_number,
                    'location' => $q->location,
                    'status' => $q->status,
                    'special_turn' => (bool) $q->special_turn,
                    'user' => [
                        'position' => [
                            'description' => $q->user?->position?->description ?? '',
                        ],
                    ],
                ])
                ->values(),
            200
        );
    }
    #[OA\Get(
        path: '/api/v1/common/get-all-tickets-location',
        summary: 'Obtener todos los turnos por ubicacion.',
        tags: ['Common'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'locationId',
                in: 'query',
                required: true,
                description: 'Ubicacion del kiosko',
                schema: new OA\Schema(type: 'string'),
                example: '2'
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datos obtenido correctamente'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    public function getAllTicketsByLocation(Request $request)
    {
        return response()->json(
            QueueManager::with('user.position', 'doctor.position', 'catalogServices:id,description', 'patient:id,name,last_name')
                ->where('location', $request->locationId)
                ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])
                ->orderBy('id', 'desc')
                ->get(),
            200
        );
    }
    #[OA\Get(
        path: '/api/v1/common/get-user-locations',
        summary: 'Obtener todos las ubicaciones.',
        tags: ['Common'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Datos obtenido correctamente'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    public function getUserLocations()
    {
        return response()->json(
            UserLocations::all(),
            200
        );
    }
    #[OA\Post(
        path: '/api/v1/skip-turn',
        summary: 'Saltar ticket',
        tags: ['Common'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['ticketId'],
                properties: [
                    new OA\Property(property: 'ticketId', type: 'integer', example: 1),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Usuario creado correctamente'),
            new OA\Response(response: 400, description: 'Datos inválidos'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    public function skipTurn(Request $request)
    {
        try {
            $validated = $request->validate([
                'ticketId' => 'required|integer',
            ]);

            $data = DB::transaction(function () use ($validated) {

                $updated = QueueManager::where('id', $validated['ticketId'])
                    ->update(['status' => 'skip']);

                if ($updated === 0) {
                    throw new \Exception('No se encontró el ticket o no se pudo actualizar.');
                }

                return $updated;
            });

            return response()->json([
                'isSuccess' => true,
                'message' => $data,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    #[OA\Post(
        path: '/api/v1/common/update-turn-status',
        summary: 'Cambiar status a ticket',
        tags: ['Common'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['ticketId', 'status'],
                properties: [
                    new OA\Property(property: 'ticketId', type: 'integer', example: 1),
                    new OA\Property(property: 'status', type: 'string', example: 'skip'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Usuario creado correctamente'),
            new OA\Response(response: 400, description: 'Datos inválidos'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    public function updateTurnStatus(Request $request)
    {
        try {
            $validated = $request->validate([
                'ticketId' => 'required|integer',
                'status' => 'required|string|in:pending,called,skip,done',
            ]);

            // Un turno terminado ya se facturó: no se vuelve a tocar.
            if (QueueManager::where('id', $validated['ticketId'])->value('status') === 'done') {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Ese turno ya está terminado y no se puede modificar.',
                ], 422);
            }

            $data = DB::transaction(function () use ($validated) {
                if ($validated['status'] == 'pending') {
                    $updated = QueueManager::where('id', $validated['ticketId'])
                        ->update(['status' => $validated['status'], 'assign_user_id' => null]);
                } else {
                    $updated = QueueManager::where('id', $validated['ticketId'])
                        ->update(['status' => $validated['status']]);
                }
                if ($updated === 0) {
                    throw new \Exception('No se encontró el ticket o no se pudo actualizar.');
                }

                return $updated;
            });

            return response()->json([
                'isSuccess' => true,
                'message' => $data,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    #[OA\Post(
        path: '/api/v1/common/update-turn-cashier',
        summary: 'Cambiar cajero a ticket',
        tags: ['Common'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['ticketId', 'cashierId'],
                properties: [
                    new OA\Property(property: 'ticketId', type: 'integer', example: 1),
                    new OA\Property(property: 'cashierId', type: 'integer', example: 'skip'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Usuario creado correctamente'),
            new OA\Response(response: 400, description: 'Datos inválidos'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    /**
     * Listado de turnos → «Asignarme todos»: el cajero se asigna de una vez los
     * turnos de hoy de una especialidad que están pendientes y SIN asignar en
     * su sede. No toca los que ya tiene otro cajero. Debe poder recibir turnos
     * (rol de facturación, de esa sede y con caja abierta).
     */
    public function assignSpecialtyTurns(Request $request)
    {
        $v = $request->validate([
            'catalogServiceId' => 'required|integer|exists:catalog_services,id',
        ], [
            'catalogServiceId.required' => 'Elige la especialidad.',
            'catalogServiceId.exists' => 'La especialidad no existe.',
        ]);

        $user = auth()->user();
        $locationId = (int) $user->user_locations_id;
        if (! BillingController::eligibleCashiers($locationId)->contains('id', (int) $user->id)) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Para asignarte turnos debes tener tu caja abierta y ser Caja, Administrador o SuperAdmin de esta sede.',
            ], 422);
        }

        $ids = DB::transaction(function () use ($v, $locationId, $user) {
            $ids = QueueManager::whereBetween('created_at', [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()])
                ->where('location', $locationId)
                ->where('catalog_services_id', $v['catalogServiceId'])
                ->where('status', 'pending')
                ->whereNull('assign_user_id')
                ->lockForUpdate()
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                // whereNull otra vez: si otro cajero tomó alguno en el camino, no se pisa.
                QueueManager::whereIn('id', $ids)->whereNull('assign_user_id')
                    ->update(['assign_user_id' => $user->id]);
            }

            return QueueManager::whereIn('id', $ids)->where('assign_user_id', $user->id)->pluck('id');
        });

        $service = DB::table('catalog_services')->where('id', $v['catalogServiceId'])->value('description');
        if ($ids->isNotEmpty()) {
            AuditLog::record('turn.bulk_assigned', 'catalog_service', (int) $v['catalogServiceId'],
                sprintf('Se asignó %d turno(s) de %s', $ids->count(), $service),
                null, ['turns' => $ids->values()]);
        }

        return response()->json([
            'isSuccess' => true,
            'message' => $ids->isEmpty()
                ? "No hay turnos de {$service} pendientes sin asignar."
                : sprintf('Te asignaste %d %s de %s.', $ids->count(), $ids->count() === 1 ? 'turno' : 'turnos', $service),
            'data' => ['ids' => $ids->values(), 'count' => $ids->count()],
        ]);
    }

    /**
     * Listado de turnos → cambiar el documento (y la persona) de un turno que
     * se sacó mal en el kiosco. Solo turnos de hoy, de la sede del cajero, que
     * estén pendientes o llamados. La persona debe ser el titular de ese
     * documento o uno de sus dependientes; si el documento no está registrado,
     * se guarda solo el documento y el paciente se registra al facturar.
     */
    public function updateTurnDocument(Request $request)
    {
        // Los espacios no cuentan (como en el kiosco): «001 1234567 8» = «00112345678».
        $request->merge(['documentId' => str_replace(' ', '', (string) $request->input('documentId'))]);
        $v = $request->validate([
            'ticketId' => 'required|integer',
            'documentId' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9-]+$/'],
            'patientUserId' => 'nullable|integer',
        ], [
            'documentId.required' => 'Escribe el documento.',
            'documentId.max' => 'El documento es demasiado largo.',
            'documentId.regex' => 'El documento solo admite letras, números y guiones.',
        ]);
        $document = str_replace(' ', '', $v['documentId']);

        $turn = QueueManager::find($v['ticketId']);
        $user = auth()->user();
        if (! $turn || (int) $turn->location !== (int) $user->user_locations_id
            || ! Carbon::parse($turn->created_at)->isToday()) {
            return response()->json(['isSuccess' => false, 'message' => 'No se encontró el turno en tu sede de hoy.'], 404);
        }
        if (! in_array($turn->status, ['pending', 'called'], true)) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Solo se puede cambiar el documento de un turno pendiente o llamado.',
            ], 422);
        }

        // La persona del turno: el titular del documento o uno de sus dependientes.
        $holder = User::where('document_number', $document)->first(['id', 'name', 'last_name']);
        $patientUserId = null;
        if (! empty($v['patientUserId'])) {
            $allowed = $holder
                ? User::where('id', $holder->id)->orWhere('dependent_of', $holder->id)->pluck('id')
                : collect();
            if (! $allowed->contains((int) $v['patientUserId'])) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Esa persona no corresponde al documento.',
                ], 422);
            }
            $patientUserId = (int) $v['patientUserId'];
        } elseif ($holder) {
            $patientUserId = (int) $holder->id;
        }

        // Igual que el kiosco: no dos turnos activos de la misma persona para el mismo servicio.
        $duplicate = QueueManager::whereBetween('created_at', [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()])
            ->where('id', '!=', $turn->id)
            ->where('patient_id', $document)
            ->where('catalog_services_id', $turn->catalog_services_id)
            ->whereIn('status', ['pending', 'called'])
            ->when($patientUserId, fn ($q) => $q->where('patient_user_id', $patientUserId), fn ($q) => $q->whereNull('patient_user_id'))
            ->value('ticket');
        if ($duplicate) {
            return response()->json([
                'isSuccess' => false,
                'message' => "Esa persona ya tiene el turno {$duplicate} para este servicio.",
            ], 422);
        }

        $fullName = fn ($id) => ($u = $id ? User::find($id, ['id', 'name', 'last_name']) : null)
            ? trim($u->name . ' ' . $u->last_name) : null;
        $before = [
            'document' => $turn->patient_id,
            'patient_user_id' => $turn->patient_user_id,
            'patient' => $fullName($turn->patient_user_id),
        ];
        if ($before['document'] === $document && (int) $before['patient_user_id'] === (int) $patientUserId) {
            return response()->json(['isSuccess' => true, 'message' => 'Sin cambios.', 'data' => [
                'id' => $turn->id, 'documentId' => $document, 'patientUserId' => $patientUserId, 'patientName' => $before['patient'],
            ]]);
        }

        $turn->forceFill(['patient_id' => $document, 'patient_user_id' => $patientUserId])->save();

        $name = $fullName($patientUserId);
        $who = fn ($doc, $person) => ($person ?: 'sin paciente registrado') . " (doc. {$doc})";
        AuditLog::record('turn.document_changed', 'queue_manager', $turn->id,
            sprintf('Turno %s: pasó de %s a %s', $turn->ticket, $who($before['document'] ?: '—', $before['patient']), $who($document, $name)),
            $before, ['document' => $document, 'patient_user_id' => $patientUserId, 'patient' => $name]);

        return response()->json([
            'isSuccess' => true,
            'message' => "Turno {$turn->ticket} actualizado" . ($name ? ": ahora es de {$name}." : '. El documento no está registrado: el paciente se registra al facturar.'),
            'data' => [
                'id' => $turn->id,
                'documentId' => $document,
                'patientUserId' => $patientUserId,
                'patientName' => $name,
            ],
        ]);
    }

    public function updateTurnCashier(Request $request)
    {
        try {
            $validated = $request->validate([
                'ticketId' => 'required|integer',
                'cashierId' => 'required|integer',
            ]);

            // Solo cajeros de la sede del turno, con rol de facturación y caja abierta.
            $turn = QueueManager::find($validated['ticketId']);
            if (! $turn) {
                return response()->json(['isSuccess' => false, 'message' => 'No se encontró el turno.'], 404);
            }
            if ($turn->status === 'done') {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Ese turno ya está terminado y no se puede modificar.',
                ], 422);
            }
            $eligible = BillingController::eligibleCashiers((int) $turn->location)
                ->contains('id', (int) $validated['cashierId']);
            if (! $eligible) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Ese cajero no puede recibir el turno: debe estar activo, ser Caja, Administrador o SuperAdmin de esta sede y tener su caja abierta.',
                ], 422);
            }

            $data = DB::transaction(function () use ($validated) {
                $updated = QueueManager::where('id', $validated['ticketId'])
                    ->update(['assign_user_id' => $validated['cashierId']]);

                if ($updated === 0) {
                    throw new \Exception('No se encontró el ticket o no se pudo actualizar.');
                }

                return $updated;
            });

            return response()->json([
                'isSuccess' => true,
                'message' => $data,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    #[OA\Get(
        path: '/api/v1/common/get-nationalities',
        summary: 'Obtener todos las nacionalidades.',
        tags: ['Common'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Datos obtenido correctamente'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    public function getNationalities()
    {
        return response()->json(
            Nationalities::all(),
            200
        );
    }

    #[OA\Post(
        path: '/api/v1/common/update-assistances-doctor',
        summary: 'Guardar asistencia del doctor',
        tags: ['Common'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['doctorId', 'startTime', 'patientQuantity'],
                properties: [
                    new OA\Property(property: 'doctorId', type: 'integer', example: 2),
                    new OA\Property(property: 'startTime', type: 'string', example: '08:00'),
                    new OA\Property(property: 'patientQuantity', type: 'integer', example: 25),
                    new OA\Property(property: 'isActive', type: 'boolean', example: false),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Factura creada correctamente'),
            new OA\Response(response: 400, description: 'Datos inválidos'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    public function updateAssistancesDoctor(Request $request)
    {
        $validated = $request->validate([
            'doctorId' => 'required|integer',
            'startTime' => 'nullable',
            'patientQuantity' => 'nullable|integer',
            'isActive' => 'boolean',
            'date' => 'required|date',
        ]);

        DB::beginTransaction();

        try {

            $nextDate = Carbon::parse($validated['date'])
                ->addDay()
                ->toDateString();

            // Buscar si ya existe un registro del doctor creado hoy
            $medicalAssistance = MedicalAssistance::where('doctor_id', $validated['doctorId'])
                ->whereDate('created_at', $validated['date'])
                ->first();

            if ($medicalAssistance) {

                // Actualizar
                $medicalAssistance->update([
                    'start_time' => $request->input('startTime'),
                    'patient_quantity' => $request->input('patientQuantity'),
                    'is_active' => $request->input('isActive')
                ]);
            } else {

                // Insertar
                MedicalAssistance::create([
                    'doctor_id' => $validated['doctorId'],
                    'start_time' => $request->input('startTime'),
                    'end_time' => null,
                    'patient_quantity' => $request->input('patientQuantity'),
                    'is_active' => $request->input('isActive'),
                    'next_date' => $nextDate,
                    'created_at' => $validated['date']
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Asistencia guardada correctamente'
            ], 200);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'message' => 'Error al guardar la asistencia',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    #[OA\Get(
        path: '/api/v1/common/get-all-doctors',
        summary: 'Obtener todos los usuarios',
        tags: ['Common'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'date',
                in: 'query',
                required: true,
                description: 'Fecha para filtrar los doctores',
                schema: new OA\Schema(type: 'string'),
                example: '2023-10-01'
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datos obtenido correctamente'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    public function getAllDoctors(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|string',
        ]);
        $users = User::with([
            'position',
            'medicalAssistances' => function ($query) use ($validated) {
                $query->whereDate('created_at', $validated['date']);
            },
            'billingPatients' => function ($query) use ($validated) {
                $query->whereDate('created_at', $validated['date'])
                    ->where('status_id', 3);
            },
            'billingPatients.patient',
            'billingPatients.doctor.position',
            // Turnos viejos (sin paciente exacto): se buscan por documento.
            'billingPatients.patient.queueManager' => function ($query) use ($validated) {
                $query->whereDate('created_at', $validated['date'])
                    ->whereNull('patient_user_id')
                    ->where('status', 'done');
            },
            // Turnos nuevos: por el ID del paciente (sirve para menores sin documento
            // y evita mostrarle al tutor el turno de su hijo).
            'billingPatients.patient.queueManagerByUser' => function ($query) use ($validated) {
                $query->whereDate('created_at', $validated['date'])
                    ->where('status', 'done');
            },
            'nationalities',
            'maritalStatus',
            'documentType',
            'insurance',
            'userLocations',
            'userType',
            'roles'
        ])
            ->where('user_type_id', 3)->get();

        return UserListResource::collection($users);
    }
    #[OA\Get(
        path: '/api/v1/common/get-relationship-types',
        summary: 'Obtener todos los tipos de relación',
        tags: ['Common'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Datos obtenido correctamente'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    public function getRelationshipTypes()
    {
        return response()->json(RelationshipTypes::all());
    }
    public function updateRelationships(Request $request)
    {
        $validated = $request->validate([
            'holderId' => 'required|integer|exists:users,id',
            'dependentId' => 'required|integer|exists:users,id|different:holderId',
            'relationshipTypeId' => 'required|integer|exists:relationship_types,id',
        ], [
            'dependentId.different' => 'Un usuario no puede ser dependiente de sí mismo.',
        ]);

        $holder = User::find($validated['holderId']);
        $dependent = User::find($validated['dependentId']);

        // Un solo nivel: el titular no puede ser dependiente de otro, y el
        // dependiente no puede tener dependientes propios.
        if ($holder->dependent_of) {
            return $this->relationshipError('El titular ya es dependiente de otra persona. Vincula los dependientes a ese titular.');
        }
        if ($dependent->relationShipDependent()->exists()) {
            return $this->relationshipError('Esta persona tiene sus propios dependientes y no puede ser dependiente.');
        }
        if ($dependent->dependent_of && (int) $dependent->dependent_of !== $holder->id) {
            return $this->relationshipError('Esta persona ya es dependiente de otro titular. Desvincúlala primero.');
        }

        try {
            $dependent->update([
                'dependent_of' => $holder->id,
                'relationship_type_id' => $validated['relationshipTypeId'],
            ]);

            return response()->json($this->dependentsOf($holder->id), 200);
        } catch (\Exception $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Error al vincular el dependiente.',
            ], 500);
        }
    }

    public function removeRelationship(Request $request)
    {
        $validated = $request->validate([
            'holderId' => 'required|integer|exists:users,id',
            'dependentId' => 'required|integer|exists:users,id',
        ]);

        $dependent = User::where('id', $validated['dependentId'])
            ->where('dependent_of', $validated['holderId'])
            ->first();

        if (! $dependent) {
            return $this->relationshipError('Esta persona no es dependiente de este titular.');
        }

        // Un menor sin documento solo se puede encontrar por su tutor.
        if (empty($dependent->document_number)) {
            return $this->relationshipError('Un menor sin documento debe tener un tutor. Vincúlalo a otro tutor en lugar de quitarlo.');
        }

        $dependent->update([
            'dependent_of' => null,
            'relationship_type_id' => null,
        ]);

        return response()->json($this->dependentsOf((int) $validated['holderId']), 200);
    }

    private function dependentsOf(int $holderId)
    {
        return User::with('relationShipType')
            ->where('dependent_of', $holderId)
            ->get();
    }

    private function relationshipError(string $message)
    {
        return response()->json([
            'isSuccess' => false,
            'message' => $message,
        ], 422);
    }
}
