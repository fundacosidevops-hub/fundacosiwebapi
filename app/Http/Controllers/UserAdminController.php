<?php

namespace App\Http\Controllers;

use App\Models\CatalogServices;
use App\Models\DocumentType;
use App\Models\Insurances;
use App\Models\MaritalStatuses;
use App\Models\MedicalCatalogServices;
use App\Models\Nationalities;
use App\Models\Positions;
use App\Models\User;
use App\Models\UserLocations;
use App\Models\UserType;
use App\Services\AuditLog;
use App\Support\PasswordPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * Ajustes → Cuenta (el usuario en sesión) y Ajustes → Usuarios del sistema.
 *
 * - Cualquier usuario puede editar sus datos personales, pero no su rol,
 *   tipo, posición, ubicación ni servicio.
 * - Solo SuperAdmin y Administrador ven y editan a los demás usuarios y les
 *   cambian la contraseña. Un Administrador no puede tocar a un SuperAdmin
 *   ni asignar ese rol.
 */
class UserAdminController extends Controller
{
    private const ADMIN_ROLES = ['SuperAdmin', 'Administrador'];

    private const MESSAGES = [
        'name.required' => 'El nombre es requerido.',
        'lastName.required' => 'El apellido es requerido.',
        'gender.required' => 'El género es requerido.',
        'gender.in' => 'El género no es válido.',
        'email.required' => 'El correo es requerido.',
        'email.email' => 'El correo no es válido.',
        'email.unique' => 'Ese correo ya lo usa otro usuario (puede ser su registro de paciente). El correo es con el que se entra al sistema y no puede repetirse.',
        'birthDate.date_format' => 'La fecha de nacimiento no es válida.',
        'birthDate.before_or_equal' => 'La fecha de nacimiento no puede ser futura.',
        'documentId.max' => 'El documento es demasiado largo.',
        'phone.max' => 'El teléfono es demasiado largo.',
        'roleId.required' => 'El rol es requerido.',
        'roleId.exists' => 'El rol no existe.',
        'userTypeId.in' => 'El tipo de usuario no es válido.',
        'serviceId.required_if' => 'Elige el servicio del doctor.',
        'password.required' => 'La nueva contraseña es requerida.',
        'password.min' => 'La contraseña debe tener al menos ' . PasswordPolicy::MIN . ' caracteres.',
        'password.confirmed' => 'La confirmación no coincide con la contraseña.',
        'documentTypeId.exists' => 'El tipo de documento no existe.',
        'civilStatusId.exists' => 'El estado civil no existe.',
        'nationalitieId.exists' => 'La nacionalidad no existe.',
        'insuranceId.exists' => 'El seguro no existe.',
        'positionId.required' => 'Elige la posición del usuario.',
        'positionId.exists' => 'La posición no existe.',
        'locationId.exists' => 'La ubicación no existe.',
        'serviceId.exists' => 'El servicio no existe.',
    ];

    /* ------------------------------------------------------------------ */
    /* Catálogos para los formularios                                      */
    /* ------------------------------------------------------------------ */

    public function catalogs()
    {
        $pick = fn ($rows) => $rows->map(fn ($r) => ['id' => $r->id, 'description' => $r->description])->values();

        return response()->json([
            'documentTypes' => $pick(DocumentType::orderBy('id')->get()),
            'maritalStatuses' => $pick(MaritalStatuses::orderBy('id')->get()),
            'nationalities' => $pick(Nationalities::orderBy('description')->get()),
            'insurances' => $pick(Insurances::orderBy('description')->get()),
            'positions' => $pick(Positions::orderBy('description')->get()),
            'locations' => $pick(UserLocations::orderBy('id')->get()),
            'userTypes' => $pick(UserType::orderBy('id')->get()),
            'services' => $pick(CatalogServices::orderBy('description')->get()),
            'roles' => Role::where('guard_name', 'api')->orderBy('name')->pluck('name')
                ->reject(fn ($r) => $r === 'SuperAdmin' && ! $this->isSuperAdmin())
                ->values(),
            'isAdmin' => $this->isAdmin(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Mi cuenta                                                           */
    /* ------------------------------------------------------------------ */

    public function me()
    {
        return response()->json($this->toForm(auth()->user()));
    }

    public function updateMe(Request $request)
    {
        $user = auth()->user();
        $v = $request->validate($this->personalRules($user), self::MESSAGES);

        if ($error = $this->documentConflict($v, $user->id, $user->user_type_id ? (int) $user->user_type_id : null)) {
            return $this->fail($error);
        }

        $user->update($this->personalData($v, $user));

        return response()->json([
            'isSuccess' => true,
            'message' => 'Tus datos se guardaron.',
            'user' => $this->toForm($user->fresh()),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Usuarios del sistema (solo administradores)                         */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $this->authorizeAdmin();
        $request->validate([
            'type' => 'nullable|in:staff,all,1,2,3',
            'status' => 'nullable|in:all,active,inactive',
            'per_page' => 'nullable|integer|min:5|max:100',
        ]);

        $q = User::query()->with(['roles:id,name', 'userType:id,description', 'position:id,description', 'userLocations:id,description']);

        $type = $request->input('type', 'staff');
        if ($type === 'staff') {
            // Usuarios del sistema: empleados y doctores (no pacientes).
            $q->where(fn ($w) => $w->where('user_type_id', '!=', 2)->orWhereNull('user_type_id'));
        } elseif ($type !== 'all') {
            $q->where('user_type_id', (int) $type);
        }

        $status = $request->input('status', 'all');
        if ($status !== 'all') {
            $q->where('is_active', $status === 'active');
        }

        if ($role = $request->input('role')) {
            $q->role($role, 'api');
        }

        $fullName = DB::getDriverName() === 'sqlite' ? "(name || ' ' || last_name)" : "CONCAT(name, ' ', last_name)";
        if ($search = trim((string) $request->input('search'))) {
            $q->where(function ($w) use ($search, $fullName) {
                $w->where('name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhereRaw($fullName . ' like ?', ["%{$search}%"])
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('document_number', 'like', "%{$search}%");
                if (ctype_digit($search)) {
                    $w->orWhere('id', (int) $search);
                }
            });
        }

        $page = $q->orderBy('name')->orderBy('last_name')->paginate($request->integer('per_page', 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'last_name' => $u->last_name,
                'email' => $u->email,
                'document_number' => $u->document_number,
                'phone' => $u->phone,
                'role' => $u->roles->pluck('name')->first(),
                'user_type' => $u->userType?->description,
                'user_type_id' => $u->user_type_id,
                'position' => $u->position?->description,
                'location' => $u->userLocations?->description,
                'is_active' => (bool) $u->is_active,
                'avatar' => $this->avatarPath($u),
                'created_at' => $u->created_at,
                'can_manage' => $this->canManage($u),
            ]),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show($id)
    {
        $this->authorizeAdmin();
        $user = User::findOrFail($id);

        return response()->json($this->toForm($user) + ['can_manage' => $this->canManage($user)]);
    }

    /** Crear un usuario del sistema (empleado o doctor) con su contraseña inicial. */
    public function store(Request $request)
    {
        $this->authorizeAdmin();
        $blank = new User();

        $v = $request->validate($this->personalRules($blank) + [
            'userTypeId' => ['required', 'integer', Rule::in([1, 3])],
            'roleId' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', 'api')],
            'positionId' => 'required|integer|exists:positions,id',
            'locationId' => 'required|integer|exists:user_locations,id',
            'serviceId' => 'nullable|integer|exists:catalog_services,id|required_if:userTypeId,3',
            'isActive' => 'sometimes|boolean',
            'password' => PasswordPolicy::rules(),
        ], self::MESSAGES + [
            'userTypeId.required' => 'Elige el tipo de usuario.',
            'locationId.required' => 'Elige la ubicación.',
        ]);

        if ($v['roleId'] === 'SuperAdmin' && ! $this->isSuperAdmin()) {
            return $this->fail('Solo un SuperAdmin puede asignar el rol SuperAdmin.', 403);
        }
        if ($error = $this->documentConflict($v, 0, (int) $v['userTypeId'])) {
            return $this->fail($error);
        }

        $user = DB::transaction(function () use ($v, $blank) {
            $user = User::create($this->personalData($v, $blank) + [
                'avatar' => 'avatar.jpg',
                'password' => Hash::make($v['password']),
                'user_type_id' => $v['userTypeId'],
                'position_id' => $v['positionId'],
                'user_locations_id' => $v['locationId'],
                'is_active' => $v['isActive'] ?? true,
            ]);
            $user->syncRoles([$v['roleId']]);

            if ((int) $v['userTypeId'] === 3 && ! empty($v['serviceId'])) {
                MedicalCatalogServices::updateOrCreate(
                    ['users_id' => $user->id],
                    ['catalog_services_id' => $v['serviceId']]
                );
            }

            return $user;
        });

        AuditLog::record('user.created', 'user', $user->id, sprintf(
            'Creó el usuario %s (%s) con rol %s',
            trim($user->name . ' ' . $user->last_name),
            $user->email,
            $user->roles()->pluck('name')->first() ?? 'sin rol'
        ));

        return response()->json([
            'isSuccess' => true,
            'message' => 'Usuario creado: ' . trim($user->name . ' ' . $user->last_name) . '. Ya puede entrar con su correo y la contraseña inicial.',
            'user' => $this->toForm($user->fresh()),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeAdmin();
        $user = User::findOrFail($id);
        if (! $this->canManage($user)) {
            return $this->fail('Solo un SuperAdmin puede modificar a otro SuperAdmin.', 403);
        }

        $rules = $this->personalRules($user) + [
            'userTypeId' => ['required', 'integer', Rule::in([1, 2, 3])],
            'roleId' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', 'api')],
            'positionId' => 'required|integer|exists:positions,id',
            'locationId' => 'nullable|integer|exists:user_locations,id',
            'serviceId' => 'nullable|integer|exists:catalog_services,id|required_if:userTypeId,3',
            'isActive' => 'sometimes|boolean',
        ];
        $v = $request->validate($rules, self::MESSAGES);

        if ($v['roleId'] === 'SuperAdmin' && ! $this->isSuperAdmin()) {
            return $this->fail('Solo un SuperAdmin puede asignar el rol SuperAdmin.', 403);
        }
        $isSelf = (int) $user->id === (int) auth()->id();
        if ($isSelf && array_key_exists('isActive', $v) && ! $v['isActive']) {
            return $this->fail('No puedes desactivar tu propio usuario.');
        }
        if ($isSelf && $v['roleId'] !== $user->roles->pluck('name')->first()) {
            return $this->fail('No puedes cambiar tu propio rol. Pídeselo a otro administrador.');
        }
        if ($error = $this->documentConflict($v, $user->id, (int) $v['userTypeId'])) {
            return $this->fail($error);
        }

        DB::transaction(function () use ($user, $v) {
            $user->update($this->personalData($v, $user) + [
                'user_type_id' => $v['userTypeId'],
                'position_id' => $v['positionId'],
                'user_locations_id' => $v['locationId'] ?? $user->user_locations_id,
                'is_active' => $v['isActive'] ?? $user->is_active,
            ]);
            $user->syncRoles([$v['roleId']]);

            if ((int) $v['userTypeId'] === 3 && ! empty($v['serviceId'])) {
                MedicalCatalogServices::updateOrCreate(
                    ['users_id' => $user->id],
                    ['catalog_services_id' => $v['serviceId']]
                );
            }
        });

        $fresh = $user->fresh(['position']);
        AuditLog::record('user.updated', 'user', $user->id, sprintf(
            'Editó el usuario %s: rol %s, posición %s',
            trim($fresh->name . ' ' . $fresh->last_name),
            $fresh->roles()->pluck('name')->first() ?? 'sin rol',
            $fresh->position?->description ?? '—'
        ));

        return response()->json([
            'isSuccess' => true,
            'message' => 'Usuario actualizado.',
            'user' => $this->toForm($user->fresh()),
        ]);
    }

    public function resetPassword(Request $request, $id)
    {
        $this->authorizeAdmin();
        $user = User::findOrFail($id);
        if ((int) $user->id === (int) auth()->id()) {
            return $this->fail('Para cambiar tu propia contraseña usa la pestaña Seguridad.');
        }
        if (! $this->canManage($user)) {
            return $this->fail('Solo un SuperAdmin puede cambiar la contraseña de otro SuperAdmin.', 403);
        }

        $v = $request->validate([
            'password' => PasswordPolicy::rules(),
        ], self::MESSAGES);

        $user->password = Hash::make($v['password']);
        $user->save();
        AuditLog::record('user.password_reset', 'user', $user->id, 'Asignó una contraseña nueva a ' . trim($user->name . ' ' . $user->last_name));

        return response()->json([
            'isSuccess' => true,
            'message' => 'Contraseña actualizada para ' . trim($user->name . ' ' . $user->last_name) . '.',
        ]);
    }

    /** Activar o desactivar un usuario. Uno desactivado no puede entrar. */
    public function setActive(Request $request, $id)
    {
        $this->authorizeAdmin();
        $user = User::findOrFail($id);
        $v = $request->validate(['active' => 'required|boolean']);

        if ((int) $user->id === (int) auth()->id()) {
            return $this->fail('No puedes desactivar tu propio usuario.');
        }
        if (! $this->canManage($user)) {
            return $this->fail('Solo un SuperAdmin puede activar o desactivar a otro SuperAdmin.', 403);
        }

        $user->is_active = $v['active'];
        $user->save();
        $name = trim($user->name . ' ' . $user->last_name);
        AuditLog::record($v['active'] ? 'user.activated' : 'user.deactivated', 'user', $user->id,
            ($v['active'] ? 'Activó' : 'Desactivó') . " al usuario {$name}");

        return response()->json([
            'isSuccess' => true,
            'is_active' => (bool) $user->is_active,
            'message' => $v['active'] ? "{$name} quedó activo." : "{$name} quedó desactivado y ya no puede entrar.",
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Ayudas                                                              */
    /* ------------------------------------------------------------------ */

    private function personalRules(User $user): array
    {
        return [
            'name' => 'required|string|max:100',
            'lastName' => 'required|string|max:100',
            'gender' => 'required|in:Masculino,Femenino',
            'email' => ['required', 'email', 'max:100', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => 'nullable|string|max:20',
            'birthDate' => 'nullable|date_format:Y-m-d|before_or_equal:today',
            'documentTypeId' => 'nullable|integer|exists:document_types,id',
            'documentId' => 'nullable|string|max:25',
            'civilStatusId' => 'nullable|integer|exists:marital_statuses,id',
            'nationalitieId' => 'nullable|integer|exists:nationalities,id',
            'insuranceId' => 'nullable|integer|exists:insurances,id',
            'policy' => 'nullable|string|max:50',
        ];
    }

    /**
     * Datos personales a guardar. Un campo que no viene en la petición
     * conserva su valor actual (no se borra).
     */
    private function personalData(array $v, User $user): array
    {
        $has = fn (string $k) => array_key_exists($k, $v);
        $doc = $has('documentId') ? preg_replace('/\s+/', '', (string) $v['documentId']) : null;

        return [
            'name' => mb_strtoupper(trim($v['name']), 'UTF-8'),
            'last_name' => mb_strtoupper(trim($v['lastName']), 'UTF-8'),
            'gender' => $v['gender'],
            'email' => mb_strtolower(trim($v['email']), 'UTF-8'),
            'phone' => $has('phone') ? $v['phone'] : $user->phone,
            'birth_date' => $has('birthDate') ? ($v['birthDate'] ?? $user->birth_date) : $user->birth_date,
            'document_type_id' => $v['documentTypeId'] ?? $user->document_type_id,
            'document_number' => $has('documentId') ? ($doc !== '' ? $doc : null) : $user->document_number,
            'marital_status_id' => $v['civilStatusId'] ?? $user->marital_status_id,
            'nationalities_id' => $v['nationalitieId'] ?? $user->nationalities_id,
            'insurance_id' => $has('insuranceId') ? $v['insuranceId'] : $user->insurance_id,
            'policy' => $has('policy') ? $v['policy'] : $user->policy,
        ];
    }

    /** Evita que dos personas queden con el mismo documento. */
    /**
     * La cédula se discrimina por user_type_id: una misma persona puede tener
     * un registro por cada tipo (por ejemplo, Empleado, Doctor y Paciente)
     * con la misma cédula. Solo se bloquea repetirla dentro del mismo tipo.
     */
    private function documentConflict(array $v, int $userId, ?int $userTypeId): ?string
    {
        $doc = isset($v['documentId']) ? preg_replace('/\s+/', '', (string) $v['documentId']) : '';
        if ($doc === '') {
            return null;
        }
        $other = User::with('userType:id,description')
            ->where('document_number', $doc)
            ->where('id', '!=', $userId)
            ->when(
                $userTypeId,
                fn ($q) => $q->where('user_type_id', $userTypeId),
                fn ($q) => $q->whereNull('user_type_id')
            )
            ->first(['id', 'user_type_id']);

        if (! $other) {
            return null;
        }
        $type = mb_strtolower($other->userType?->description ?? 'usuario sin tipo', 'UTF-8');

        // Se indica el registro que choca (número y tipo, sin el nombre).
        return "Ese número de documento ya pertenece a otro {$type} (Reg. #{$other->id}).";
    }

    private function toForm(User $u): array
    {
        $u->loadMissing(['roles:id,name', 'userType:id,description', 'position:id,description', 'userLocations:id,description']);
        $service = MedicalCatalogServices::where('users_id', $u->id)->value('catalog_services_id');

        return [
            'id' => $u->id,
            'name' => $u->name,
            'lastName' => $u->last_name,
            'gender' => $u->gender,
            'email' => $u->email,
            'phone' => $u->phone,
            'birthDate' => $u->birth_date ? substr((string) $u->birth_date, 0, 10) : null,
            'documentTypeId' => $u->document_type_id,
            'documentId' => $u->document_number,
            'civilStatusId' => $u->marital_status_id,
            'nationalitieId' => $u->nationalities_id,
            'insuranceId' => $u->insurance_id,
            'policy' => $u->policy,
            'userTypeId' => $u->user_type_id,
            'userType' => $u->userType?->description,
            'roleId' => $u->roles->pluck('name')->first(),
            'positionId' => $u->position_id,
            'position' => $u->position?->description,
            'locationId' => $u->user_locations_id,
            'location' => $u->userLocations?->description,
            'serviceId' => $service,
            'isActive' => (bool) $u->is_active,
            'avatar' => $this->avatarPath($u),
            'createdAt' => $u->created_at,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Foto de perfil                                                      */
    /* ------------------------------------------------------------------ */

    private const AVATAR_DIR = 'avatars';
    private const AVATAR_SIZE = 256;

    public function uploadMyAvatar(Request $request)
    {
        return $this->saveAvatar($request, auth()->user());
    }

    public function removeMyAvatar()
    {
        return $this->clearAvatar(auth()->user());
    }

    public function uploadAvatar(Request $request, $id)
    {
        $this->authorizeAdmin();
        $user = User::findOrFail($id);
        if (! $this->canManage($user)) {
            return $this->fail('Solo un SuperAdmin puede cambiar la foto de otro SuperAdmin.', 403);
        }

        return $this->saveAvatar($request, $user);
    }

    public function removeAvatar($id)
    {
        $this->authorizeAdmin();
        $user = User::findOrFail($id);
        if (! $this->canManage($user)) {
            return $this->fail('Solo un SuperAdmin puede cambiar la foto de otro SuperAdmin.', 403);
        }

        return $this->clearAvatar($user);
    }

    /**
     * Muestra una foto. Es pública para que funcione en <img>, pero el nombre
     * del archivo es aleatorio (40 caracteres) y no se puede adivinar.
     */
    public function showAvatar(string $file)
    {
        abort_unless(preg_match('/^[a-z0-9]{40}\.(webp|jpg)$/', $file), 404);
        $path = self::AVATAR_DIR . '/' . $file;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    private function saveAvatar(Request $request, User $user)
    {
        $request->validate([
            'photo' => 'required|file|mimes:jpeg,jpg,png,webp|max:1024',
        ], [
            'photo.required' => 'Elige una foto.',
            'photo.mimes' => 'La foto debe ser JPG, PNG o WEBP.',
            'photo.max' => 'La foto no puede pesar más de 1 MB.',
            'photo.uploaded' => 'La foto no puede pesar más de 1 MB.',
        ]);

        $bytes = file_get_contents($request->file('photo')->getRealPath());
        [$data, $ext] = $this->shrinkAvatar($bytes);
        if ($data === null) {
            return $this->fail('No se pudo leer la imagen. Prueba con otra foto.');
        }

        $path = self::AVATAR_DIR . '/' . Str::lower(Str::random(40)) . '.' . $ext;
        Storage::disk('local')->put($path, $data);

        $old = $this->avatarPath($user);
        $user->avatar = $path;
        $user->save();
        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return response()->json([
            'isSuccess' => true,
            'message' => 'Foto actualizada.',
            'avatar' => $path,
            'size' => strlen($data),
        ]);
    }

    private function clearAvatar(User $user)
    {
        $old = $this->avatarPath($user);
        $user->avatar = null;
        $user->save();
        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return response()->json(['isSuccess' => true, 'message' => 'Foto quitada.', 'avatar' => null]);
    }

    /**
     * Recorta al centro, reduce a 256×256 y guarda en WEBP (o JPG si el
     * servidor no tiene WEBP). Así cada foto pesa unos pocos KB y se eliminan
     * los datos ocultos del archivo original (ubicación GPS, etc.).
     *
     * @return array{0: ?string, 1: string}
     */
    private function shrinkAvatar(string $bytes): array
    {
        if (! function_exists('imagecreatefromstring')) {
            // Sin GD: se guarda tal cual (ya validado: imagen y máximo 1 MB).
            $info = @getimagesizefromstring($bytes);

            return $info ? [$bytes, 'jpg'] : [null, 'jpg'];
        }
        $src = @imagecreatefromstring($bytes);
        if (! $src) {
            return [null, 'jpg'];
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $size = min(self::AVATAR_SIZE, $side);
        $dst = imagecreatetruecolor($size, $size);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2), $size, $size, $side, $side);

        ob_start();
        if (function_exists('imagewebp')) {
            imagewebp($dst, null, 80);
            $ext = 'webp';
        } else {
            imagejpeg($dst, null, 82);
            $ext = 'jpg';
        }
        $out = ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        return [$out ?: null, $ext];
    }

    /** Ruta de la foto si es una subida por el sistema (no los valores viejos como «avatar.jpg»). */
    private function avatarPath(User $u): ?string
    {
        $a = (string) $u->avatar;

        return str_starts_with($a, self::AVATAR_DIR . '/') ? $a : null;
    }

    private function isAdmin(): bool
    {
        return (bool) auth()->user()?->hasAnyRole(self::ADMIN_ROLES);
    }

    private function isSuperAdmin(): bool
    {
        return (bool) auth()->user()?->hasRole('SuperAdmin');
    }

    private function canManage(User $target): bool
    {
        return $this->isSuperAdmin() || ! $target->hasRole('SuperAdmin');
    }

    private function authorizeAdmin(): void
    {
        abort_unless($this->isAdmin(), 403, 'Solo los administradores pueden gestionar usuarios.');
    }

    private function fail(string $message, int $status = 422)
    {
        return response()->json(['isSuccess' => false, 'message' => $message], $status);
    }
}
