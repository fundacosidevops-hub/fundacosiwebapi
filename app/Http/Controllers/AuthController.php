<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Tymon\JWTAuth\Facades\JWTAuth;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: "Auth",
    description: "Endpoints de autenticación"
)]
class AuthController extends Controller
{
    #[OA\Post(
        path: "/api/v1/register",
        summary: "Registrar nuevo usuario",
        tags: ["Auth"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["name", "email", "password"],
                properties: [
                    new OA\Property(property: "name", type: "string", example: "Juan"),
                    new OA\Property(property: "email", type: "string", example: "juan@email.com"),
                    new OA\Property(property: "password", type: "string", example: "123456"),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: "Usuario creado correctamente",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "name", type: "string"),
                        new OA\Property(property: "email", type: "string"),
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: "Error de validación",
                content: new OA\JsonContent()
            ),
        ]
    )]
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:6',
            'role' => 'required|exists:roles,name'
        ]);

        $user = User::create([
            'avatar' => $request->avatar,
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
            'is_active' => $request->is_active
        ]);

        $user->assignRole($request->role);
        return response()->json($user, 201);
    }

    #[OA\Post(
        path: "/api/v1/login",
        summary: "Login usuario",
        tags: ["Auth"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["email", "password"],
                properties: [
                    new OA\Property(property: "email", type: "string", example: "juan@email.com"),
                    new OA\Property(property: "password", type: "string", example: "123456"),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Login exitoso"),
            new OA\Response(response: 401, description: "Credenciales inválidas"),
        ]
    )]
    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');

        if (!$token = Auth::guard('api')->attempt($credentials)) {
            return response()->json(['error' => 'Credenciales inválidas'], 401);
        }

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => JWTAuth::factory()->getTTL() * 60
        ]);
    }

    #[OA\Get(
        path: "/api/v1/profile",
        summary: "Obtener perfil del usuario autenticado",
        tags: ["Auth"],
        security: [["bearerAuth" => []]],
        responses: [
            new OA\Response(response: 200, description: "Perfil obtenido correctamente"),
            new OA\Response(response: 401, description: "No autorizado"),
        ]
    )]
    public function profile()
    {
        $user = auth()->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatar' => $user->avatar,
            'roles' => $user->getRoleNames(),  
            'permissions' => $user->getAllPermissions()->pluck('name'),  
        ]);
    }

    #[OA\Post(
        path: "/api/v1/logout",
        summary: "Cerrar sesión",
        tags: ["Auth"],
        security: [["bearerAuth" => []]],
        responses: [
            new OA\Response(response: 200, description: "Sesión cerrada correctamente"),
            new OA\Response(response: 401, description: "No autorizado"),
        ]
    )]
    public function logout()
    {
        JWTAuth::invalidate(JWTAuth::getToken());

        return response()->json([
            'message' => 'Sesión cerrada correctamente'
        ]);
    }
}
