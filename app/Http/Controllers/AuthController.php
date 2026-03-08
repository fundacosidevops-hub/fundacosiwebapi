<?php

namespace App\Http\Controllers;

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

        $user = User::updateOrCreate(
            ['email' => $validated['email']], // condición para buscar
            [
                'avatar' => 'avatar.jpg',
                'name' => $validated['name'],
                'position_id' => $validated['positionId'],
                'phone' => $validated['phone'] ?? null,
                'is_active' => 1,
            ]
        );

        // solo asignar password si el usuario es nuevo
        if ($user->wasRecentlyCreated) {
            $user->password = Hash::make('TempPass123');
            $user->save();
        }

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
        $user = auth()->user()->load('position');

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'positionId' => $user->position?->id,
            'phone' => $user->phone,
            'avatar' => $user->avatar,
            'roles' => $user->getRoleNames()[0],
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]);
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
