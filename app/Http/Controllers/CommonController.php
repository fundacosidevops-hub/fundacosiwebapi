<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserInfoResource;
use App\Http\Resources\UserListResource;
use App\Models\CatalogServices;
use App\Models\Insurances;
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
                            ->filter(fn ($item) => $item->users)
                            ->map(function ($item) {
                                return [
                                    'id' => $item->users->id,
                                    'name' => $item->users->name.' '.$item->users->last_name,
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
                'users.medicalAssistances' => function ($query) {
                    $query->whereDate('next_date', Carbon::today());
         
                },
                'users.queueManagerDoctor' => function ($query) {
                    $query->whereDate('created_at', Carbon::today())
                        ->where('status', '!=', 'skip');
                }
            ])
            ->where('catalog_services_id', $request->service_id)
            ->whereHas('users.medicalAssistances', function ($q) use ($now) {
                $q->where('is_active', true)
                    ->whereDate('next_date', Carbon::today())
                    ->whereRaw("
                        patient_quantity >
                        (
                            SELECT COUNT(*)
                            FROM in_invoices
                            WHERE in_invoices.doctor_id = medical_assistances.doctor_id
                            AND DATE(in_invoices.created_at) = CURDATE()
                        )
                    ");
        
                if ($now->between(
                    Carbon::today()->setTime(0, 0),
                    Carbon::today()->setTime(10, 29, 59)
                )) {
                    $q->whereBetween('start_time', ['00:00:00', '10:30:00']);
                } else {
                    $q->where('start_time', '>=', '10:30:00');
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
        $documentId = str_replace(' ', '', $request->documentId);

        $user = User::with([
            'nationalities',
            'maritalStatus',
            'documentType',
            'insurance',
        ])
            ->where('document_number', $documentId)
            ->first();

        if (! $user) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Paciente no encontrado',
            ], 200);
        }

        return new UserInfoResource($user);
    }

    #[OA\Post(
        path: '/api/v1/save-ticket',
        summary: 'Guardar ticket',
        tags: ['Common'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code', 'documentId','locationId', 'insuranceId', 'catalogServiceId', 'doctorId', 'billingType'],
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
            'code' => 'required|string',
            'documentId' => 'required|string|max:20',
            'locationId' => 'required|integer',
            'catalogServiceId' => 'required|integer',
            'insuranceId' => 'nullable|integer',
            'doctorId' => 'required|integer',
            'billingType' => 'required|string',
            'specialTurn' => 'nullable|boolean',
        ]);
        DB::transaction(function () use ($validated, &$data) {

            $lastNumber = QueueManager::where('catalog_services_id', $validated['catalogServiceId'])
                ->whereDate('created_at', now())
                ->lockForUpdate()
                ->max('curr_number');

            $nextNumber = $lastNumber ? $lastNumber + 1 : 1;

            $ticket = $validated['code'].'-'.str_pad($nextNumber, 3, '0', STR_PAD_LEFT);

             QueueManager::create([
                'queue_code' => $validated['code'],
                'curr_number' => $nextNumber,
                'ticket' => $ticket,
                'patient_id' => $validated['documentId'],
                'location' => $validated['locationId'],
                'assign_user_id' => null,
                'billing_type' => $validated['billingType'],
                'insurance_id' => $validated['insuranceId'],
                'catalog_services_id' => $validated['catalogServiceId'],
                'doctor_id' => $validated['doctorId'],
                'special_turn' => $validated['specialTurn'],
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
                Carbon::today()->endOfDay(), ])
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
            'isSuccess' => true, 'message' => '', 'data' => $q], 200);
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
        return response()->json(
            QueueManager::with('user.position')
                ->where('location', $request->locationId)
                ->whereNotNull('assign_user_id')
                ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])
                ->orderBy('id')
                ->get(), 200
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
            QueueManager::with('user.position','doctor.position')
                ->where('location', $request->locationId) 
                ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])
                ->orderBy('id', 'desc')
                ->get(), 200
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
            UserLocations::all(), 200
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
                required: ['ticketId','status'],
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
                if($validated['status'] == 'pending'){
                     $updated = QueueManager::where('id', $validated['ticketId'])
                    ->update(['status' => $validated['status'], 'assign_user_id' => null]);
                }else{
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
            Nationalities::all(), 200
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
    ]);

    DB::beginTransaction();

    try {

        $nextDate = Carbon::now()->addDay()->toDateString();

        // Buscar si ya existe un registro del doctor creado hoy
        $medicalAssistance = MedicalAssistance::where('doctor_id', $validated['doctorId'])
            ->whereDate('created_at', Carbon::today())
            ->first();

        if ($medicalAssistance) {

            // Actualizar
            $medicalAssistance->update([
                'start_time' => $request->input('startTime'),
                'patient_quantity' => $request->input('patientQuantity'),
                'is_active' => $request->input('isActive'),
                'next_date' => $nextDate,
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
        tags: ['Auth'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Datos obtenido correctamente'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    public function getAllDoctors()
    {
          $users = User::with([
            'position',
            'medicalAssistances' => function ($query) {
                $query->whereDate('created_at', Carbon::today());
            },   
            'billingPatients' => function ($query) {
                $query->whereDate('created_at', Carbon::today())
                ->where('status_id', 3);
            },
            'billingPatients.patient',
            'billingPatients.doctor.position',
            'billingPatients.patient.queueManager' => function ($query) {
                $query->whereDate('created_at', Carbon::today())
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
        ->where('user_type_id', 3) ->get();

       return UserListResource::collection($users);
    } 
}
