<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserInfoResource;
use App\Models\CatalogServices;
use App\Models\Insurances;
use App\Models\InsurancesRate;
use App\Models\MedicalCatalogServices;
use App\Models\QueueManager;
use App\Models\User;
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
        summary: 'Obtener todos los estudios a facturar',
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
                ->whereHas('medicalStudies', function ($query) use ($request) {
                    $query->where('catalog_services_id', $request->service_id);
                })
                ->get()
        );
    }

    #[OA\Get(
        path: '/api/v1/common/catalog-services',
        summary: 'Obtener todos los catalogos de servicios a facturar',
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
        summary: 'Obtener todos los doctores por ID del servicio a facturar',
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
        return response()->json(
            MedicalCatalogServices::with('users')
                ->where('catalog_services_id', $request->service_id)
                ->whereHas('users', function ($q) {
                    $q->where('is_active', true);
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
                'success' => false,
                'message' => 'Paciente no encontrado',
            ], 200);
        }

        return new UserInfoResource($user);
    }

    #[OA\Post(
        path: '/api/v1/save-ticket',
        summary: 'Guardar ticket',
        tags: ['Auth'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code', 'documentId', 'insuranceId', 'catalogServiceId', 'doctorId', 'billingType'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', example: 'AA1'),
                    new OA\Property(property: 'documentId', type: 'string', example: '00118479953'),
                    new OA\Property(property: 'insuranceId', type: 'integer', example: 1),
                    new OA\Property(property: 'catalogServiceId', type: 'integer', example: 1),
                    new OA\Property(property: 'doctorId', type: 'integer', example: 1),
                    new OA\Property(property: 'billingType', type: 'string', example: 'private'),
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
            'catalogServiceId' => 'required|integer',
            'insuranceId' => 'nullable|integer',
            'doctorId' => 'required|integer',
            'billingType' => 'required|string',
        ]);
        DB::transaction(function () use ($validated, &$data) {

            $lastNumber = QueueManager::where('catalog_services_id', $validated['catalogServiceId'])
                ->whereDate('created_at', now())
                ->lockForUpdate()
                ->max('curr_number');

            $nextNumber = $lastNumber ? $lastNumber + 1 : 1;

            $ticket = $validated['code'].'-'.str_pad($nextNumber, 3, '0', STR_PAD_LEFT);

            $data = QueueManager::create([
                'queue_code' => $validated['code'],
                'curr_number' => $nextNumber,
                'ticket' => $ticket,
                'patient_id' => $validated['documentId'],
                'assign_user_id' => null,
                'billing_type' => $validated['billingType'],
                'insurance_id' => $validated['insuranceId'],
                'catalog_services_id' => $validated['catalogServiceId'],
                'doctor_id' => $validated['doctorId'],
            ]);

        });

        return response()->json($data, 200);
    }
}
