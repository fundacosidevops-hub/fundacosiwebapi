<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserInfoResource;
use App\Http\Resources\UserListResource;
use App\Models\DocumentType;
use App\Models\MedicalCatalogServices;
use App\Models\Positions;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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
        summary: 'Crear nuevo usuario.',
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
            'id' => 'required|integer',
            'documentId' => 'nullable|string|max:50',
            'noDocument' => 'nullable|boolean',
            'holderId' => 'nullable|integer|exists:users,id',
            'relationshipTypeId' => 'nullable|integer|exists:relationship_types,id',
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
            'userTypeId' => 'required|integer',
            'locationId' => 'required|integer',
            'positionId' => 'required|integer',
            'roleId' => 'required|string',
            'serviceId' => 'nullable|integer|required_if:userTypeId,3',
        ]);

        // Menor sin documento: se identifica por su registro y su tutor.
        $noDocument = (bool) ($validated['noDocument'] ?? false);
        $documentId = $noDocument ? null : trim((string) ($validated['documentId'] ?? ''));
        $documentTypeId = $validated['documentTypeId'];

        if (! $noDocument && $documentId === '') {
            return response()->json([
                'isSuccess' => false,
                'message' => 'El número de documento es requerido.',
            ], 422);
        }

        $existing = User::find($validated['id']);

        if ($noDocument) {
            $hasHolder = ! empty($validated['holderId']) || ($existing && $existing->dependent_of);
            if (! $hasHolder) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Un menor sin documento debe registrarse desde el perfil de su tutor.',
                ], 422);
            }
            $documentTypeId = DocumentType::where('description', 'Menor sin documento')->value('id') ?? $documentTypeId;
        }

        if (! empty($validated['holderId']) && (int) $validated['holderId'] === (int) $validated['id']) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Un usuario no puede ser dependiente de sí mismo.',
            ], 422);
        }

        if (! empty($validated['holderId']) && User::whereKey($validated['holderId'])->whereNotNull('dependent_of')->exists()) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'El tutor seleccionado es dependiente de otra persona.',
            ], 422);
        }

        $data = [
            'document_number' => $documentId,
            'avatar' => 'avatar.jpg',
            'name' => mb_strtoupper($validated['name'], 'UTF-8'),
            'last_name' => mb_strtoupper($validated['lastName'], 'UTF-8'),
            'gender' => $validated['gender'],
            'marital_status_id' => $validated['civilStatusId'],
            'birth_date' => $validated['birthDate'],
            'position_id' => $validated['positionId'],
            'document_type_id' => $documentTypeId,
            'nationalities_id' => $validated['nationalitieId'],
            'insurance_id' => $validated['insuranceId'] ?? null,
            'email' => $validated['email'] ?? $existing?->email ?? (($documentId ?: 'menor-' . Str::lower(Str::random(10))) . '@funsacosixxi.com'),
            'phone' => $validated['phone'] ?? null,
            'policy' => $validated['policy'] ?? null,
            'user_type_id' => $validated['userTypeId'],
            'user_locations_id' => $validated['locationId'],
            'is_active' => 1,
        ];

        $user = User::where('id', $validated['id'])
            ->where('user_type_id', $validated['userTypeId'])
            ->first();

        if (! $user) {
            $data['password'] = Hash::make('TempPass123');
        }
        $user = User::updateOrCreate(
            [
                'id' => $validated['id'],
                'user_type_id' => $validated['userTypeId']
            ],
            $data
        );


        $user->syncRoles([$validated['roleId']]);

        // Creado desde el perfil del tutor: queda vinculado como dependiente.
        if (! empty($validated['holderId'])) {
            $user->update([
                'dependent_of' => $validated['holderId'],
                'relationship_type_id' => $validated['relationshipTypeId'] ?? $user->relationship_type_id,
            ]);
        }

        if ((int) $validated['userTypeId'] === 3) {
            MedicalCatalogServices::updateOrCreate(
                ['users_id' => $user->id],
                ['catalog_services_id' => $validated['serviceId']]
            );
        }
        return response()->json($user, 200);
    }
    #[OA\Get(
        path: '/api/v1/auth/get-all-system-user',
        summary: 'Obtener todos los usuarios',
        tags: ['Auth'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Datos obtenido correctamente'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    public function getAllSystemUser(Request $request)
    {
        $query = User::query()
            ->with([
                'position',  
                'billingPatients.patient',
                'billingPatients.doctor.position', 
                'nationalities',
                'maritalStatus',
                'documentType',
                'insurance',
                'userLocations',
                'userType',
            ]);

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('id', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('document_number', 'like', "%{$search}%");
            });
        }

        $users = $query
            ->orderByDesc('users.created_at')
            ->paginate(
                $request->integer('per_page', 50)
            );

        return UserListResource::collection($users);
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
    #[OA\Post(
        path: '/api/v1/change-password',
        summary: 'Cambiar la contraseña del usuario autenticado',
        tags: ['Auth'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['currentPassword', 'password', 'password_confirmation'],
                properties: [
                    new OA\Property(property: 'currentPassword', type: 'string', example: 'actual123'),
                    new OA\Property(property: 'password', type: 'string', example: 'nueva1234'),
                    new OA\Property(property: 'password_confirmation', type: 'string', example: 'nueva1234'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Contraseña actualizada correctamente'),
            new OA\Response(response: 401, description: 'No autorizado'),
            new OA\Response(response: 422, description: 'Datos inválidos o contraseña actual incorrecta'),
        ]
    )]
    public function changePassword(Request $request)
    {
        $validated = $request->validate(
            [
                'currentPassword' => 'required|string',
                'password' => 'required|string|min:8|confirmed',
            ],
            [
                'currentPassword.required' => 'La contraseña actual es requerida.',
                'password.required' => 'La nueva contraseña es requerida.',
                'password.min' => 'La nueva contraseña debe tener al menos 8 caracteres.',
                'password.confirmed' => 'La confirmación no coincide con la nueva contraseña.',
            ]
        );

        $user = auth()->user();

        if (! Hash::check($validated['currentPassword'], $user->password)) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'La contraseña actual es incorrecta.',
                'errors' => ['currentPassword' => ['La contraseña actual es incorrecta.']],
            ], 422);
        }

        $user->password = Hash::make($validated['password']);
        $user->save();

        return response()->json([
            'isSuccess' => true,
            'message' => 'Contraseña actualizada correctamente.',
        ], 200);
    }
}
