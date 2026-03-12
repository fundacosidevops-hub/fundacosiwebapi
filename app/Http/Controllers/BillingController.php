<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserInfoResource;
use App\Models\Insurances;
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
}
