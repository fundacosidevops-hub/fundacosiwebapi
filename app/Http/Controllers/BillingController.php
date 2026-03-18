<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserInfoResource;
use App\Models\CatalogServices;
use App\Models\Insurances;
use App\Models\InsurancesRate;
use App\Models\InvoiceItems;
use App\Models\Invoices;
use App\Models\MedicalCatalogServices;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Billing',
    description: 'Endpoints de facturas'
)]
class BillingController extends Controller
{
    #[OA\Get(
        path: '/api/v1/billing/user-info',
        summary: 'Obtener datos del usuario a facturar',
        tags: ['Billing'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'document',
                in: 'query',
                required: true,
                description: 'Documento del usuario',
                schema: new OA\Schema(type: 'string'),
                example: '00107508525'
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datos obtenido correctamente'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    public function getUserBillingInfo(Request $request)
    {
        $document = str_replace(' ', '', $request->document);

        $user = User::with([
            'position',
            'nationalities',
            'maritalStatus',
            'documentType',
            'insurance',
            'userType',
            'roles',
        ])
            ->where('document_number', $document)
            ->first();

        if (! $user) {
            return response()->json([
                'message' => 'Usuario no encontrado',
            ], 404);
        }

        return new UserInfoResource($user);
    }

    #[OA\Get(
        path: '/api/v1/billing/insurance',
        summary: 'Obtener todos los seguros a facturar',
        tags: ['Billing'],
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
        path: '/api/v1/billing/medical-studies',
        summary: 'Obtener todos los estudios a facturar',
        tags: ['Billing'],
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
        path: '/api/v1/billing/catalog-services',
        summary: 'Obtener todos los catalogos de servicios a facturar',
        tags: ['Billing'],
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
        path: '/api/v1/billing/catalog-services-doctor',
        summary: 'Obtener todos los doctores por ID del servicio a facturar',
        tags: ['Billing'],
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

    #[OA\Post(
        path: '/api/v1/save-bill',
        summary: 'Guardar factura',
        tags: ['Billing'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['patientId', 'doctorId', 'billingType', 'subtotal', 'total', 'items'],
                properties: [
                    new OA\Property(property: 'patientId', type: 'string', example: '123456789'),
                    new OA\Property(property: 'doctorId', type: 'integer', example: 1),
                    new OA\Property(property: 'insuranceId', type: 'integer', example: 2),
                    new OA\Property(property: 'billingType', type: 'string', example: 'insured'),
                    new OA\Property(property: 'authorizationNumber', type: 'integer', example: 12345),
                    new OA\Property(property: 'catalogServiceId', type: 'integer', example: 1),
                    new OA\Property(property: 'subtotal', type: 'number', example: 1000),
                    new OA\Property(property: 'discount', type: 'number', example: 200),
                    new OA\Property(property: 'total', type: 'number', example: 800),

                    new OA\Property(
                        property: 'items',
                        type: 'array',
                        items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'medicalStudiesId', type: 'integer', example: 1),
                                new OA\Property(property: 'quantity', type: 'integer', example: 1),
                                new OA\Property(property: 'unitPrice', type: 'number', example: 500),
                                new OA\Property(property: 'discount', type: 'number', example: 0),
                                new OA\Property(property: 'insuranceCoverage', type: 'number', example: 50),
                                new OA\Property(property: 'patientAmount', type: 'number', example: 250),
                                new OA\Property(property: 'total', type: 'number', example: 500),
                            ]
                        )
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Factura creada correctamente'),
            new OA\Response(response: 400, description: 'Datos inválidos'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    public function save(Request $request)
    {
        $validated = $request->validate([
            'patientId' => 'required|string',
            'doctorId' => 'required|integer',
            'insuranceId' => 'nullable|integer',
            'billingType' => 'required|in:private,insured',
            'authorizationNumber' => 'nullable|integer',
            'catalogServiceId' => 'required|integer',

            'subtotal' => 'required|numeric',
            'discount' => 'required|numeric',
            'total' => 'required|numeric',

            'items' => 'required|array|min:1',

            'items.*.medicalStudiesId' => 'required',
            'items.*.insuranceCoverage' => 'numeric',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unitPrice' => 'required|numeric',
            'items.*.total' => 'required|numeric',
        ]);

        DB::beginTransaction();

        try {

            // buscaa el paciente por documento
            $patient = User::where('document_number', $validated['patientId'])->first();

            if (! $patient) {
                return response()->json([
                    'message' => 'Paciente no encontrado',
                ], 404);
            }

            // crear factura
            $invoice = Invoices::create([
                'patient_id' => $patient->id,
                'doctor_id' => $validated['doctorId'],
                'insurance_id' => $validated['insuranceId'] ?? null,
                'catalog_services_id' => $validated['catalogServiceId'] ?? null,
                'status_id' => 1,
                'authorization_number' => $validated['authorizationNumber'] ?? null,
                'billing_type' => $validated['billingType'],
                'invoice_number' => 'B20569988',
                'subtotal' => $validated['subtotal'],
                'discount' => $validated['discount'],
                'total' => $validated['total'],
                'created_by' => auth()->id(),
            ]);

            // Guardar items
            foreach ($validated['items'] as $item) {
                InvoiceItems::create([
                    'invoice_id' => $invoice->id,
                    'medical_study_id' => $item['medicalStudiesId'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unitPrice'],
                    'discount' => $item['discount'] ?? 0,
                    'insurance_coverage' => $item['insuranceCoverage'],
                    'patient_amount' => $item['patientAmount'] ?? 0,
                    'total' => $item['total'],
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Factura creada correctamente',
                'data' => $invoice,
            ], 201);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'message' => 'Error al guardar la factura',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
