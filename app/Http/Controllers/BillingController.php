<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserInfoResource;
use App\Models\CatalogServices;
use App\Models\Insurances;
use App\Models\InsurancesRate;
use App\Models\MedicalCatalogServices;
use App\Models\User;
use Illuminate\Http\Request;
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
}
