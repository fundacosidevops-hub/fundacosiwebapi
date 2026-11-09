<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Pruebas de seguridad del API (auditoría de 2026-11).
 *
 * Ejecutar:  php artisan test --filter=SecurityTest
 * Usa SQLite en memoria (phpunit.xml): no toca ninguna base real.
 */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    private const KIOSK_KEY = 'test-kiosk-key-0123456789abcdef';

    private int $serviceId;

    private int $doctorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Role::firstOrCreate(['name' => 'Paciente', 'guard_name' => 'api']);

        DB::table('user_types')->insert([
            ['id' => 1, 'description' => 'Empleado'],
            ['id' => 2, 'description' => 'Paciente'],
            ['id' => 3, 'description' => 'Doctor'],
        ]);
        DB::table('positions')->insert(['id' => 1, 'description' => 'Cajera']);
        DB::table('user_locations')->insert(['id' => 2, 'description' => 'La Carpa']);
        $this->serviceId = DB::table('catalog_services')->insertGetId(['description' => 'PEDIATRIA']);

        config(['kiosk.keys' => [self::KIOSK_KEY], 'kiosk.allowed_ips' => []]);
        RateLimiter::clear('login:caja@x.com|127.0.0.1');
        RateLimiter::clear('login-ip:127.0.0.1');

        $this->doctorId = $this->user('Medico', 'doc@x.com')->id;
    }

    private function user(string $role, string $email, string $password = 'Segura12345', int $type = 1): User
    {
        $u = User::forceCreate([
            'name' => strtoupper(strtok($email, '@')),
            'last_name' => 'TEST',
            'email' => $email,
            'password' => Hash::make($password),
            'position_id' => 1,
            'user_type_id' => $type,
            'user_locations_id' => 2,
            'document_number' => (string) random_int(10000000000, 99999999999),
            'is_active' => true,
        ]);
        $u->assignRole($role);

        return $u;
    }

    private function as(User $u): static
    {
        return $this->withToken(JWTAuth::fromUser($u));
    }

    /**
     * En producción cada petición es un proceso nuevo; en las pruebas la app
     * se reutiliza, así que se olvida el usuario/token de la petición anterior.
     */
    public function withToken(string $token, string $type = 'Bearer'): static
    {
        foreach (['tymon.jwt', 'tymon.jwt.auth', 'tymon.jwt.parser', 'tymon.jwt.manager', 'tymon.jwt.payload.factory'] as $id) {
            app()->forgetInstance($id);
        }
        JWTAuth::clearResolvedInstances();
        app('auth')->forgetGuards();

        return parent::withToken($token, $type);
    }

    /* --- S1/S2: alta de empleados ------------------------------------- */

    public function test_non_admin_cannot_create_users(): void
    {
        $caja = $this->user('Caja', 'caja@x.com');

        $this->as($caja)->postJson('/api/v1/saveUser', [
            'name' => 'Intruso', 'roles' => 'SuperAdmin', 'positionId' => 1, 'email' => 'intruso@x.com',
        ])->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'intruso@x.com']);
    }

    public function test_administrador_cannot_grant_superadmin(): void
    {
        $admin = $this->user('Administrador', 'admin@x.com');

        $this->as($admin)->postJson('/api/v1/saveUser', [
            'name' => 'Nuevo', 'roles' => 'SuperAdmin', 'positionId' => 1, 'email' => 'nuevo@x.com',
        ])->assertStatus(403);
    }

    public function test_new_users_do_not_get_the_old_fixed_password(): void
    {
        $sa = $this->user('SuperAdmin', 'sa@x.com');

        $this->as($sa)->postJson('/api/v1/saveUser', [
            'name' => 'Nuevo', 'roles' => 'Caja', 'positionId' => 1, 'email' => 'nuevo@x.com',
        ])->assertOk();

        $nuevo = User::where('email', 'nuevo@x.com')->firstOrFail();
        $this->assertFalse(Hash::check('TempPass123', $nuevo->password));
        $this->assertTrue($nuevo->hasRole('Caja'));
    }

    public function test_patients_cannot_log_in(): void
    {
        $this->user('Paciente', 'paciente@x.com', 'Segura12345', 2);

        $this->postJson('/api/v1/login', ['email' => 'paciente@x.com', 'password' => 'Segura12345'])
            ->assertStatus(401);
    }

    /* --- S4: permisos en endpoints antiguos ---------------------------- */

    public function test_roles_without_billing_access_are_blocked_from_billing_endpoints(): void
    {
        $nurse = $this->user('Enfermeria', 'nurse@x.com');
        $doctor = User::find($this->doctorId);

        foreach ([$nurse, $doctor] as $u) {
            $this->as($u)->postJson('/api/v1/billing/save-bill', [])->assertStatus(403);
            $this->as($u)->postJson('/api/v1/billing/save-payment', [])->assertStatus(403);
            $this->as($u)->getJson('/api/v1/auth/get-all-system-user')->assertStatus(403);
            $this->as($u)->getJson('/api/v1/common/call-next-queue?userLocationsId=2')->assertStatus(403);
            $this->as($u)->postJson('/api/v1/common/update-assistances-doctor', [])->assertStatus(403);
            $this->as($u)->postJson('/api/v1/save-patient', [])->assertStatus(403);
        }
    }

    public function test_cashier_passes_the_billing_gate(): void
    {
        $caja = $this->user('Caja', 'caja@x.com');

        // Pasa el permiso: la respuesta es de validación (422), no 403.
        $this->as($caja)->postJson('/api/v1/billing/save-bill', [])->assertStatus(422);
        $this->as($caja)->getJson('/api/v1/billing/payment-methods')->assertOk();
    }

    /* --- S3: kiosco ---------------------------------------------------- */

    public function test_kiosk_routes_require_key_or_staff(): void
    {
        $this->getJson('/api/v1/common/patient-info?documentId=001')->assertStatus(401);
        $this->getJson('/api/v1/common/patient-info?documentId=001', ['X-Kiosk-Key' => 'mala'])->assertStatus(401);
        $this->getJson('/api/v1/common/patient-info?documentId=001', ['X-Kiosk-Key' => self::KIOSK_KEY])->assertOk();

        $caja = $this->user('Caja', 'caja@x.com');
        $this->as($caja)->getJson('/api/v1/common/patient-info?documentId=001')->assertOk();

        // Un médico no consulta cédulas por esta vía.
        $this->as(User::find($this->doctorId))->getJson('/api/v1/common/patient-info?documentId=001')->assertStatus(403);
    }

    public function test_kiosk_key_can_be_limited_to_an_ip(): void
    {
        config(['kiosk.allowed_ips' => ['10.0.0.1']]);

        $this->getJson('/api/v1/common/insurance', ['X-Kiosk-Key' => self::KIOSK_KEY])->assertStatus(401);
    }

    public function test_ticket_screen_does_not_expose_patient_documents(): void
    {
        $caja = $this->user('Caja', 'caja@x.com');
        DB::table('queue_managers')->insert([
            'queue_code' => 'PE', 'curr_number' => 1, 'location' => 2, 'ticket' => 'PE-001',
            'patient_id' => '00112345678', 'assign_user_id' => $caja->id, 'status' => 'called',
            'billing_type' => 'private', 'catalog_services_id' => $this->serviceId,
            'doctor_id' => $this->doctorId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->getJson('/api/v1/common/get-ticket-location?locationId=2', ['X-Kiosk-Key' => self::KIOSK_KEY])
            ->assertOk()
            ->assertJsonPath('0.ticket', 'PE-001')
            ->assertJsonPath('0.user.position.description', 'Cajera');

        $this->assertStringNotContainsString('00112345678', $res->getContent());
        $this->assertStringNotContainsString('caja@x.com', $res->getContent());
    }

    public function test_save_ticket_limits_duplicates_and_daily_maximum(): void
    {
        $ticket = fn (array $extra = []) => $this->postJson('/api/v1/common/save-ticket', array_merge([
            'code' => 'PE', 'documentId' => '00199999999', 'locationId' => 2,
            'catalogServiceId' => $this->serviceId, 'doctorId' => $this->doctorId,
            'billingType' => 'private',
        ], $extra), ['X-Kiosk-Key' => self::KIOSK_KEY]);

        $ticket()->assertOk()->assertJsonPath('ticket', 'PE-001');
        // Mismo servicio, misma persona, turno aún pendiente → no.
        $ticket()->assertStatus(422);

        config(['kiosk.max_tickets_per_document' => 1]);
        $otro = DB::table('catalog_services')->insertGetId(['description' => 'CARDIOLOGIA']);
        $ticket(['catalogServiceId' => $otro, 'code' => 'CA'])->assertStatus(429);

        // Código de cola con basura → no.
        config(['kiosk.max_tickets_per_document' => 6]);
        $ticket(['catalogServiceId' => $otro, 'code' => '<b>'])->assertStatus(422);
    }

    /* --- S8: intentos de login ----------------------------------------- */

    public function test_login_is_rate_limited(): void
    {
        $this->user('Caja', 'caja@x.com');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/login', ['email' => 'caja@x.com', 'password' => 'mala'])->assertStatus(401);
        }
        $this->postJson('/api/v1/login', ['email' => 'caja@x.com', 'password' => 'Segura12345'])
            ->assertStatus(429);
    }

    /* --- S2: contraseña débil o por defecto ----------------------------- */

    public function test_weak_password_forces_a_change_before_using_the_system(): void
    {
        $this->user('Caja', 'caja@x.com', 'TempPass123');

        $login = $this->postJson('/api/v1/login', ['email' => 'caja@x.com', 'password' => 'TempPass123'])
            ->assertOk()
            ->assertJsonPath('must_change_password', true);
        $token = $login->json('access_token');

        $this->withToken($token)->getJson('/api/v1/billing/payment-methods')
            ->assertStatus(403)
            ->assertJsonPath('code', 'password_change_required');
        $this->withToken($token)->getJson('/api/v1/profile')->assertOk();

        // La nueva también debe cumplir la regla.
        $this->withToken($token)->postJson('/api/v1/change-password', [
            'currentPassword' => 'TempPass123', 'password' => 'corta', 'password_confirmation' => 'corta',
        ])->assertStatus(422);

        $new = $this->withToken($token)->postJson('/api/v1/change-password', [
            'currentPassword' => 'TempPass123', 'password' => 'NuevaClave2026', 'password_confirmation' => 'NuevaClave2026',
        ])->assertOk()->json('access_token');

        $this->assertNotEmpty($new);
        $this->withToken($new)->getJson('/api/v1/billing/payment-methods')->assertOk();
    }

    /* --- Media 14: tablero sin montos para quien no maneja dinero -------- */

    public function test_dashboard_hides_money_from_clinical_roles(): void
    {
        $this->as(User::find($this->doctorId))->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('restricted', true)
            ->assertJsonMissingPath('trend')
            ->assertJsonMissingPath('cashiers')
            ->assertJsonMissingPath('kpis.gross');

        $caja = $this->user('Caja', 'caja@x.com');
        $this->as($caja)->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('restricted', false)
            ->assertJsonStructure(['trend', 'cashiers', 'kpis' => ['gross']]);
    }

    /* --- CORS ----------------------------------------------------------- */

    public function test_cors_only_allows_configured_origins(): void
    {
        $ok = $this->call('OPTIONS', '/api/v1/login', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:63779',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
        $this->assertSame('http://localhost:63779', $ok->headers->get('Access-Control-Allow-Origin'));

        $bad = $this->call('OPTIONS', '/api/v1/login', [], [], [], [
            'HTTP_ORIGIN' => 'https://sitio-malo.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
        $this->assertNull($bad->headers->get('Access-Control-Allow-Origin'));
    }
}
