<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Rutas del chatbot del sitio web (/v1/web/*) y bandeja de solicitudes de cita.
 * Ejecutar:  php artisan test --filter=WebChatTest
 */
class WebChatTest extends TestCase
{
    use RefreshDatabase;

    private const WEB_KEY = 'test-web-key-0123456789abcdefgh';

    private const KIOSK_KEY = 'test-kiosk-key-0123456789abcdef';

    private int $cardio;

    private int $cafeteria;

    private User $doctor;

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

        $this->cardio = DB::table('catalog_services')->insertGetId(['description' => 'CARDIOLOGIA']);
        $this->cafeteria = DB::table('catalog_services')->insertGetId(['description' => 'CAFETERIA']);
        DB::table('insurances')->insert([
            ['rnc' => '1', 'description' => 'ARS SENASA', 'abbreviation' => 'SENASA', 'is_active' => true],
            ['rnc' => '2', 'description' => 'ARS VIEJA', 'abbreviation' => 'VIEJA', 'is_active' => false],
        ]);

        config([
            'webchat.keys' => [self::WEB_KEY], 'webchat.allowed_ips' => [],
            'kiosk.keys' => [self::KIOSK_KEY], 'kiosk.allowed_ips' => [],
        ]);

        $this->doctor = $this->user('Medico', 'doc@x.com', 3, ['name' => 'ANA', 'last_name' => 'PEREZ', 'phone' => '8095550000']);
        DB::table('medical_catalog_services')->insert(['catalog_services_id' => $this->cardio, 'users_id' => $this->doctor->id]);
    }

    private function user(string $role, string $email, int $type = 1, array $extra = []): User
    {
        $u = User::forceCreate(array_merge([
            'name' => strtoupper(strtok($email, '@')),
            'last_name' => 'TEST',
            'email' => $email,
            'password' => Hash::make('Segura12345'),
            'position_id' => 1,
            'user_type_id' => $type,
            'user_locations_id' => 2,
            'document_number' => (string) random_int(10000000000, 99999999999),
            'is_active' => true,
        ], $extra));
        $u->assignRole($role);

        return $u;
    }

    private function web(): static
    {
        return $this->withHeader('X-Web-Key', self::WEB_KEY);
    }

    public function withToken(string $token, string $type = 'Bearer'): static
    {
        foreach (['tymon.jwt', 'tymon.jwt.auth', 'tymon.jwt.parser', 'tymon.jwt.manager', 'tymon.jwt.payload.factory'] as $id) {
            app()->forgetInstance($id);
        }
        JWTAuth::clearResolvedInstances();
        app('auth')->forgetGuards();

        return parent::withToken($token, $type);
    }

    /* --- Acceso ------------------------------------------------------------ */

    public function test_web_routes_require_the_web_key(): void
    {
        $this->getJson('/api/v1/web/info')->assertStatus(401);
        $this->withHeader('X-Web-Key', 'mala')->getJson('/api/v1/web/info')->assertStatus(401);
        // La clave del kiosco no abre las rutas del sitio web...
        $this->withHeader('X-Web-Key', self::KIOSK_KEY)->getJson('/api/v1/web/info')->assertStatus(401);
    }

    public function test_web_key_does_not_open_kiosk_or_patient_routes(): void
    {
        $this->withHeader('X-Kiosk-Key', self::WEB_KEY)
            ->getJson('/api/v1/common/patient-info?document_id=00100000001')->assertStatus(401);
        $this->web()->getJson('/api/v1/common/patient-info?document_id=00100000001')->assertStatus(401);
        $this->web()->getJson('/api/v1/appointment-requests')->assertStatus(401);
    }

    /* --- Información pública --------------------------------------------- */

    public function test_info_lists_public_services_doctors_and_active_insurances_only(): void
    {
        $res = $this->web()->getJson('/api/v1/web/info')->assertOk()->json();

        $names = collect($res['services'])->pluck('name');
        $this->assertContains('CARDIOLOGIA', $names);
        $this->assertNotContains('CAFETERIA', $names);

        $cardio = collect($res['services'])->firstWhere('name', 'CARDIOLOGIA');
        $this->assertSame([['id' => $this->doctor->id, 'name' => 'ANA PEREZ']], $cardio['doctors']);

        $this->assertSame(['ARS SENASA'], collect($res['insurances'])->pluck('name')->all());

        // Ningún dato personal del médico.
        $json = json_encode($res);
        $this->assertStringNotContainsString('doc@x.com', $json);
        $this->assertStringNotContainsString('8095550000', $json);
        $this->assertStringNotContainsString($this->doctor->document_number, $json);
    }

    public function test_schedule_shows_doctors_of_the_day_with_available_quota(): void
    {
        DB::table('medical_assistances')->insert([
            'doctor_id' => $this->doctor->id, 'start_time' => '08:00:00', 'patient_quantity' => 10,
            'is_active' => true, 'next_date' => Carbon::today()->toDateString(), 'created_at' => Carbon::yesterday(),
        ]);

        $res = $this->web()->getJson('/api/v1/web/schedule')->assertOk()->json();
        $this->assertSame(Carbon::today()->toDateString(), $res['date']);
        $this->assertCount(1, $res['doctors']);
        $this->assertSame('ANA PEREZ', $res['doctors'][0]['name']);
        $this->assertSame('CARDIOLOGIA', $res['doctors'][0]['service']);
        $this->assertSame('08:00', $res['doctors'][0]['start_time']);
        $this->assertSame(10, $res['doctors'][0]['available']);

        $this->web()->getJson('/api/v1/web/schedule?date=tomorrow')->assertOk()->assertJsonCount(0, 'doctors');
        $this->web()->getJson('/api/v1/web/schedule?date=2020-01-01')->assertStatus(422);
    }

    /* --- Solicitudes de cita ------------------------------------------------ */

    public function test_appointment_request_is_created_and_validated(): void
    {
        $res = $this->web()->postJson('/api/v1/web/appointment-requests', [
            'patient_name' => 'Juan Rodríguez',
            'phone' => '809-555-1234',
            'service_id' => $this->cardio,
            'doctor_id' => $this->doctor->id,
            'preferred_date' => 'el lunes',
            'preferred_time' => 'mañana',
            'reason' => '<b>Chequeo</b>',
        ])->assertCreated()->json();

        $this->assertMatchesRegularExpression('/^C-\d{6}-[A-Z0-9]{4}$/', $res['code']);
        $this->assertDatabaseHas('appointment_requests', [
            'code' => $res['code'], 'phone' => '8095551234', 'service_name' => 'CARDIOLOGIA',
            'doctor_name' => 'ANA PEREZ', 'reason' => 'Chequeo', 'status' => 'pending',
        ]);

        $this->web()->postJson('/api/v1/web/appointment-requests', [
            'patient_name' => 'X', 'phone' => '123', 'preferred_date' => '',
        ])->assertStatus(422);
    }

    public function test_doctor_from_another_service_is_ignored(): void
    {
        $other = $this->user('Medico', 'otro@x.com', 3);

        $this->web()->postJson('/api/v1/web/appointment-requests', [
            'patient_name' => 'Juan Rodríguez', 'phone' => '8095551234',
            'service_id' => $this->cardio, 'doctor_id' => $other->id, 'preferred_date' => 'mañana',
        ])->assertCreated();

        $this->assertDatabaseHas('appointment_requests', ['phone' => '8095551234', 'doctor_id' => null]);
    }

    public function test_requests_per_phone_are_limited(): void
    {
        config(['webchat.max_requests_per_phone' => 2]);
        $body = ['patient_name' => 'Juan Rodríguez', 'phone' => '8095551234', 'service_id' => $this->cardio, 'preferred_date' => 'hoy'];

        $this->web()->postJson('/api/v1/web/appointment-requests', $body)->assertCreated();
        $this->web()->postJson('/api/v1/web/appointment-requests', $body)->assertCreated();
        $this->web()->postJson('/api/v1/web/appointment-requests', $body)->assertStatus(429);
    }

    /* --- Bandeja del personal ------------------------------------------------ */

    public function test_billing_staff_can_list_and_update_requests_others_cannot(): void
    {
        $code = $this->web()->postJson('/api/v1/web/appointment-requests', [
            'patient_name' => 'Juan Rodríguez', 'phone' => '8095551234', 'service_id' => $this->cardio, 'preferred_date' => 'hoy',
        ])->json('code');
        $id = DB::table('appointment_requests')->where('code', $code)->value('id');

        $caja = $this->user('Caja', 'caja@x.com');
        $this->withToken(JWTAuth::fromUser($caja))->getJson('/api/v1/appointment-requests?status=pending')
            ->assertOk()->assertJsonPath('pending', 1)->assertJsonPath('data.data.0.code', $code);

        $this->withToken(JWTAuth::fromUser($caja))->putJson("/api/v1/appointment-requests/{$id}", [
            'status' => 'scheduled', 'staff_notes' => 'Cita el lunes 8:00',
        ])->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->assertDatabaseHas('appointment_requests', ['id' => $id, 'status' => 'scheduled', 'handled_by' => $caja->id]);

        $nurse = $this->user('Enfermeria', 'nurse@x.com');
        $this->withToken(JWTAuth::fromUser($nurse))->getJson('/api/v1/appointment-requests')->assertStatus(403);
    }
}
