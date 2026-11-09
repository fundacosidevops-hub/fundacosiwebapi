<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserInfoResource;
use App\Http\Resources\UserListResource;
use App\Models\DocumentType;
use App\Models\MedicalCatalogServices;
use App\Models\Positions;
use App\Models\User;
use App\Services\AuditLog;
use App\Support\PasswordPolicy;
use Illuminate\Support\Facades\RateLimiter;
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
        $credentials = [
            'email' => (string) $request->input('email', ''),
            'password' => (string) $request->input('password', ''),
        ];

        // Límite de intentos: 5 por minuto por correo+IP y 20 por minuto por IP.
        $userKey = 'login:' . Str::lower($credentials['email']) . '|' . $request->ip();
        $ipKey = 'login-ip:' . $request->ip();
        foreach ([[$userKey, 5], [$ipKey, 20]] as [$key, $max]) {
            $seconds = $this->limiter(fn () => RateLimiter::tooManyAttempts($key, $max)
                ? RateLimiter::availableIn($key)
                : null);
            if ($seconds !== null) {
                AuditLog::record('auth.blocked', null, null, 'Login bloqueado por demasiados intentos: ' . mb_substr($credentials['email'], 0, 120));

                return response()->json([
                    'error' => "Demasiados intentos. Espera {$seconds} segundos e inténtalo de nuevo.",
                ], 429)->header('Retry-After', (string) $seconds);
            }
        }

        if ($credentials['email'] === '' || $credentials['password'] === ''
            || ! $token = Auth::guard('api')->attempt($credentials)) {
            $this->limiter(function () use ($userKey, $ipKey) {
                RateLimiter::hit($userKey, 60);
                RateLimiter::hit($ipKey, 60);
            });
            AuditLog::record('auth.login_failed', null, null, 'Intento de entrada fallido con el correo ' . mb_substr($credentials['email'], 0, 120));

            return response()->json(['error' => 'Credenciales inválidas'], 401);
        }

        $user = Auth::guard('api')->user();

        // Un usuario desactivado no puede entrar aunque la contraseña sea correcta.
        if ($user?->is_active === false) {
            Auth::guard('api')->invalidate();

            return response()->json(['error' => 'Tu usuario está desactivado. Habla con un administrador.'], 403);
        }

        // Los pacientes no usan el sistema: su registro no sirve para entrar.
        if ((int) $user?->user_type_id === 2) {
            // El token recién creado no se entrega a nadie: se descarta.
            $this->limiter(fn () => RateLimiter::hit($userKey, 60));

            return response()->json(['error' => 'Credenciales inválidas'], 401);
        }

        $this->limiter(fn () => RateLimiter::clear($userKey));

        // Contraseña débil o por defecto: entra, pero solo puede cambiarla.
        $mustChange = PasswordPolicy::isWeak($credentials['password']);
        if ($mustChange) {
            // Se reemplaza por un token marcado; el primero nunca sale del servidor.
            $token = Auth::guard('api')->claims(['pwd_change' => true])->login($user);
        }

        AuditLog::record('auth.login', 'user', $user->id,
            'Inició sesión' . ($mustChange ? ' (con contraseña débil: debe cambiarla)' : ''), null, null, $user);

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => JWTAuth::factory()->getTTL() * 60,
            'must_change_password' => $mustChange,
        ]);
    }

    /**
     * El límite de intentos usa el caché de Laravel (CACHE_STORE). Si el caché
     * falla (tabla «cache» inexistente, carpeta sin permisos…), se registra el
     * error en el log y el login sigue funcionando sin límite, en vez de
     * responder 500.
     */
    private function limiter(\Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            report($e);
            \Illuminate\Support\Facades\Log::warning('Límite de intentos de login desactivado: el caché no funciona. Revisa CACHE_STORE y la tabla «cache».');

            return null;
        }
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
        // Solo administradores crean o editan empleados (antes cualquier
        // usuario podía crear un SuperAdmin desde aquí).
        $actor = $request->user();
        if (! $actor || ! $actor->hasAnyRole(['SuperAdmin', 'Administrador'])) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Solo los administradores pueden gestionar usuarios.',
            ], 403);
        }

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

        // El rol debe existir; Paciente no es un rol de empleado y solo un
        // SuperAdmin puede dar (o quitar) el rol SuperAdmin.
        $role = Role::where('name', $validated['roles'])->where('guard_name', 'api')->first();
        if (! $role || $role->name === 'Paciente') {
            return response()->json(['isSuccess' => false, 'message' => 'Rol no válido.'], 422);
        }

        $user = User::where('email', $validated['email'])->first();

        $touchesSuperAdmin = $role->name === 'SuperAdmin' || ($user && $user->hasRole('SuperAdmin'));
        if ($touchesSuperAdmin && ! $actor->hasRole('SuperAdmin')) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Solo un SuperAdmin puede asignar o modificar a un SuperAdmin.',
            ], 403);
        }
        if ($user && (int) $user->user_type_id === 2) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Ese correo pertenece a un paciente.',
            ], 422);
        }

        if (! $user) {
            // Sin contraseña conocida: el administrador le asigna una en
            // Ajustes → Usuarios del sistema → Cambiar contraseña.
            $data['password'] = Hash::make(PasswordPolicy::random());
        }

        $user = User::updateOrCreate(
            ['email' => $validated['email']],
            $data
        );

        $user->syncRoles([$role->name]);
        AuditLog::record($user->wasRecentlyCreated ? 'user.created' : 'user.updated', 'user', $user->id,
            ($user->wasRecentlyCreated ? 'Creó' : 'Editó') . " el empleado {$user->name} ({$user->email}) con rol {$role->name}");

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

        // Desde facturación solo se registran y editan pacientes. El tipo y el
        // rol no se aceptan del navegador: un paciente siempre es «Paciente».
        if ($existing && (int) $existing->user_type_id !== 2) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Este usuario es del sistema. Edítalo en Ajustes → Usuarios del sistema.',
            ], 422);
        }
        $validated['userTypeId'] = 2;
        $validated['roleId'] = 'Paciente';

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
            // Al editar se respeta si el usuario está desactivado; uno nuevo nace activo.
            'is_active' => $existing ? $existing->is_active : 1,
        ];

        $user = User::where('id', $validated['id'])
            ->where('user_type_id', $validated['userTypeId'])
            ->first();

        if (! $user) {
            // Los pacientes no inician sesión: contraseña aleatoria, nadie la conoce.
            $data['password'] = Hash::make(PasswordPolicy::random());
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
                'password' => PasswordPolicy::rules(),
            ],
            [
                'currentPassword.required' => 'La contraseña actual es requerida.',
                'password.required' => 'La nueva contraseña es requerida.',
                'password.min' => 'La nueva contraseña debe tener al menos ' . PasswordPolicy::MIN . ' caracteres.',
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

        if (hash_equals((string) $validated['currentPassword'], (string) $validated['password'])) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'La nueva contraseña debe ser distinta a la actual.',
                'errors' => ['password' => ['La nueva contraseña debe ser distinta a la actual.']],
            ], 422);
        }

        $user->password = Hash::make($validated['password']);
        $user->save();
        AuditLog::record('auth.password_changed', 'user', $user->id, 'Cambió su contraseña');

        // Token nuevo sin la marca de «cambiar contraseña»; el anterior deja de servir.
        try {
            auth('api')->invalidate();
        } catch (\Throwable) {
            // Sin token que invalidar.
        }
        $token = auth('api')->claims(['pwd_change' => false])->login($user);

        return response()->json([
            'isSuccess' => true,
            'message' => 'Contraseña actualizada correctamente.',
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => JWTAuth::factory()->getTTL() * 60,
        ], 200);
    }
}
