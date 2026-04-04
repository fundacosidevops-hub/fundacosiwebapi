<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserInfoResource;
use App\Models\Positions;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use OpenApi\Attributes as OA;
use Spatie\Permission\Models\Role;
use Tymon\JWTAuth\Facades\JWTAuth;

#[OA\Tag(
    name: 'Auth',
    description: 'Endpoints de usuarios'
)]
class AuthController extends Controller
{
    #[OA\Post(
        path: '/api/v1/login',
        summary: 'Login usuario',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', example: 'test@fundacosixxi.com'),
                    new OA\Property(property: 'password', type: 'string', example: '123456'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Login exitoso'),
            new OA\Response(response: 401, description: 'Credenciales inválidas'),
        ]
    )]
    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');

        if (! $token = Auth::guard('api')->attempt($credentials)) {
            return response()->json(['error' => 'Credenciales inválidas'], 401);
        }

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => JWTAuth::factory()->getTTL() * 60,
        ]);
    }

    #[OA\Post(
        path: '/api/v1/saveUser',
        summary: 'Guardar usuario',
        tags: ['Auth'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'role', 'positionsId', 'email'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Juan Perez'),
                    new OA\Property(property: 'role', type: 'string', example: 'Administrador'),
                    new OA\Property(property: 'positionsId', type: 'integer', example: 1),
                    new OA\Property(property: 'email', type: 'string', example: 'juan@fundacosixxi.com'),
                    new OA\Property(property: 'phone', type: 'string', example: '8095551234'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Usuario creado correctamente'),
            new OA\Response(response: 400, description: 'Datos inválidos'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    public function saveUser(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:50',
            'roles' => 'required|string',
            'positionId' => 'required|integer',
            'email' => 'required|email',
            'phone' => 'nullable|string|max:12',
        ]);

        $data = [
            'avatar' => 'avatar.jpg',
            'name' => $validated['name'],
            'last_name' => 'Capellan',
            'position_id' => $validated['positionId'],
            'phone' => $validated['phone'] ?? null,
            'is_active' => 1,
        ];

        $user = User::where('email', $validated['email'])->first();

        if (! $user) {
            $data['password'] = Hash::make('TempPass123');
        }

        $user = User::updateOrCreate(
            ['email' => $validated['email']],
            $data
        );

        $user->syncRoles([$validated['roles']]);

        return response()->json([
            'message' => $user->wasRecentlyCreated
                ? 'Usuario creado correctamente'
                : 'Usuario actualizado correctamente',
            'data' => $user,
        ], 200);
    }

    #[OA\Get(
        path: '/api/v1/profile',
        summary: 'Obtener perfil del usuario autenticado',
        tags: ['Auth'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Perfil obtenido correctamente'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    public function profile()
    {
        $user = auth()->user()->load([
            'position',
            'nationalities',
            'maritalStatus',
            'documentType',
            'insurance',
            'userLocations',
            'userType',
            'roles',
        ]);

        return new UserInfoResource($user);
    }

    #[OA\Get(
        path: '/api/v1/roles',
        summary: 'Obtener roles',
        tags: ['Auth'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Roles obtenidos correctamente'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    public function roles()
    {
        $resp = Role::all();

        return response()->json([
            'message' => 'Roles obtenidos correctamente',
            'data' => $resp,
        ]);
    }

    #[OA\Get(
        path: '/api/v1/positions',
        summary: 'Obtener posiciones',
        tags: ['Auth'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Roles obtenidos correctamente'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    public function positions()
    {
        $resp = Positions::all();

        return response()->json([
            'message' => 'Posiciones obtenidas correctamente',
            'data' => $resp,
        ]);
    }

    #[OA\Post(
        path: '/api/v1/save-patient',
        summary: 'Crear nuevo paciente.',
        tags: ['Auth'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Sesión cerrada correctamente'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    public function savePatient(Request $request)
    {
        $validated = $request->validate([
            'documentId' => 'required|string|max:50',
            'name' => 'required|string',
            'lastName' => 'required|string',
            'gender' => 'required|string',
            'nationalitieId' => 'required|integer',
            'insuranceId' => 'nullable|integer',
            'civilStatusId' => 'required|integer',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:20',
            'policy' => 'nullable|string',
            'documentTypeId' => 'required|integer',
            'birthDate' => 'required|string',
        ]);
        $data = [
            'document_number' => $validated['documentId'],
            'avatar' => 'avatar.jpg',
            'name' => mb_strtoupper($validated['name'], 'UTF-8'),
            'last_name' => mb_strtoupper($validated['lastName'], 'UTF-8'),
            'gender' => $validated['gender'],
            'marital_status_id' => $validated['civilStatusId'],
            'birth_date' => $validated['birthDate'],
            'position_id' => 6,
            'document_type_id' => $validated['documentTypeId'],
            'nationalities_id' => $validated['nationalitieId'],
            'insurance_id' => $validated['insuranceId'],
            'email' => $validated['email'] ?? $validated['documentId'].'@funsacosixxi.com',
            'phone' => $validated['phone'],
            'policy' => $validated['policy'],
            'user_type_id' => 2,
            'is_active' => 1,
        ];

        $user = User::where('document_number', $validated['documentId'])->first();

        if (! $user) {
            $data['password'] = Hash::make('TempPass123');
        }

        $user = User::updateOrCreate(
            [
                'document_number' => $validated['documentId'],
                'user_type_id' => 2,
            ],
            $data
        );

        return response()->json($user, 200);
    }

    #[OA\Post(
        path: '/api/v1/logout',
        summary: 'Cerrar sesión',
        tags: ['Auth'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Sesión cerrada correctamente'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    public function logout()
    {
        JWTAuth::invalidate(JWTAuth::getToken());

        return response()->json([
            'message' => 'Sesión cerrada correctamente',
        ]);
    }
}
