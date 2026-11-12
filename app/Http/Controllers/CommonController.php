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
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class CommonController
{
    /**
     * Corte entre el turno de la mañana y el de la tarde. Hasta las 11:00:59
     * se ofrecen los médicos de la mañana (empiezan antes de las 11:00, o sea
     * hasta las 10:59); desde las 11:01:00, los de la tarde (empiezan a las
     * 11:00 o después). Así un médico de las 11:00 sale en un solo turno.
     * Facturación avisa en rojo los últimos SHIFT_WARN_MINUTES minutos (ver shiftInfo()).
     */
    private const SHIFT_CUTOFF = '11:00:00';
    private const SHIFT_WARN_MINUTES = 15;

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

        return response()->json([
            'now' => $now->toIso8601String(),
            'cutoff' => substr(self::SHIFT_CUTOFF, 0, 5),
            'switch_at' => $switchAt->toIso8601String(),
            'warn_minutes' => self::SHIFT_WARN_MINUTES,
            'shift' => $now->lt($switchAt) ? 'morning' : 'afternoon',
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

                    // Turno de la mañana hasta las 11:00:59; luego, el de la tarde.
                    if ($now->lt($this->shiftSwitchAt($now))) {
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

        // Buscar nuevo turno
        $q = DB::transaction(function () use ($validated) {

            $baseQuery = QueueManager::whereBetween('created_at', [
                Carbon::today()->startOfDay(),
                Carbon::today()->endOfDay(),
            ])
                ->where('status', 'pending')
                ->where('location', $validated['userLocationsId']);

            // Buscar primero un turno especial
            $q = (clone $baseQuery)
                ->where('special_turn', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            // Si no hay turnos especiales, tomar el más antiguo sin importar si es especial o no
            if (! $q) {
                $q = (clone $baseQuery)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();
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
            'message' => '',
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
            QueueManager::with('user.position', 'doctor.position')
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
                'status' => 'required|string',
            ]);

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
    public function updateTurnCashier(Request $request)
    {
        try {
            $validated = $request->validate([
                'ticketId' => 'required|integer',
                'cashierId' => 'required|integer',
            ]);

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
