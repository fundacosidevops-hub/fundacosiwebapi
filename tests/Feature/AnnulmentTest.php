<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountingService;
use App\Services\AuditLog;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Anulación con nota de crédito (B04), devolución en caja, 607 y bitácora.
 * Ejecutar: php artisan test --filter=AnnulmentTest   (SQLite en memoria)
 */
class AnnulmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $caja;

    private int $patientId;

    private int $doctorId;

    private int $serviceId;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        AuditLog::reset();

        // Igual que la base real: las facturas guardan el NCF en ncf_number
        // (las migraciones del repositorio solo crean invoice_number, ver E10).
        if (! \Illuminate\Support\Facades\Schema::hasColumn('in_invoices', 'ncf_number')) {
            \Illuminate\Support\Facades\Schema::table('in_invoices', fn ($t) => $t->string('ncf_number', 19)->nullable());
        }

        $this->seed(RoleSeeder::class);
        Role::firstOrCreate(['name' => 'Paciente', 'guard_name' => 'api']);

        DB::table('user_types')->insert([['id' => 1, 'description' => 'Empleado'], ['id' => 2, 'description' => 'Paciente'], ['id' => 3, 'description' => 'Doctor']]);
        DB::table('positions')->insert(['id' => 1, 'description' => 'Cajera']);
        DB::table('user_locations')->insert(['id' => 2, 'description' => 'La Carpa']);
        DB::table('document_types')->insert([['id' => 2, 'description' => 'Cedula'], ['id' => 3, 'description' => 'Pasaporte']]);
        DB::table('in_invoice_statuses')->delete();
        DB::table('in_invoice_statuses')->insert([
            ['id' => 1, 'name' => 'Pendiente', 'is_active' => 1],
            ['id' => 2, 'name' => 'Parcial', 'is_active' => 1],
            ['id' => 3, 'name' => 'Pagada', 'is_active' => 1],
            ['id' => 4, 'name' => 'Anulada', 'is_active' => 1],
        ]);
        DB::table('in_payment_methods')->insert([
            ['id' => 1, 'name' => 'Efectivo', 'code' => 'cash', 'is_active' => 1],
            ['id' => 2, 'name' => 'Tarjeta', 'code' => 'credit_card', 'is_active' => 1],
        ]);
        // Efectivo → cuenta de caja (como en Configuración contable).
        $cash = DB::table('acc_account_mappings')->where('key', 'cash.main')->value('account_id');
        DB::table('acc_account_mappings')->updateOrInsert(['key' => 'payment_method.1'], ['label' => 'Efectivo', 'account_id' => $cash]);
        $this->serviceId = DB::table('catalog_services')->insertGetId(['description' => 'PEDIATRIA']);

        $this->admin = $this->user('Administrador', 'admin@x.com');
        $this->caja = $this->user('Caja', 'caja@x.com');
        $this->doctorId = $this->user('Medico', 'doc@x.com', 3)->id;
        $this->patientId = $this->user('Paciente', 'pac@x.com', 2, '00112345678')->id;
    }

    private function user(string $role, string $email, int $type = 1, ?string $doc = null): User
    {
        $u = User::forceCreate([
            'name' => strtoupper(strtok($email, '@')), 'last_name' => 'TEST', 'email' => $email,
            'password' => Hash::make('Segura12345'), 'position_id' => 1, 'user_type_id' => $type,
            'user_locations_id' => 2, 'document_type_id' => $doc ? 2 : null,
            'document_number' => $doc ?? (string) random_int(10000000000, 99999999999), 'is_active' => true,
        ]);
        $u->assignRole($role);

        return $u;
    }

    private function as(User $u): static
    {
        foreach (['tymon.jwt', 'tymon.jwt.auth', 'tymon.jwt.parser', 'tymon.jwt.manager', 'tymon.jwt.payload.factory'] as $id) {
            app()->forgetInstance($id);
        }
        JWTAuth::clearResolvedInstances();
        app('auth')->forgetGuards();

        return $this->withToken(JWTAuth::fromUser($u));
    }

    private function b04(): void
    {
        DB::table('in_ncf_sequences')->insert([
            'type' => 'B04', 'prefix' => 'B04', 'from_number' => 1, 'to_number' => 100,
            'current_number' => null, 'expires_at' => now()->addYear()->toDateString(), 'is_active' => 1,
        ]);
    }

    /** Factura B02 con asiento contable; opcionalmente cobrada en una caja. */
    private function invoice(float $total = 300, float $coverage = 700, ?int $paidMethod = null, ?int $sessionId = null): int
    {
        $this->seq++;
        $id = DB::table('in_invoices')->insertGetId([
            'patient_id' => $this->patientId, 'doctor_id' => $this->doctorId, 'catalog_services_id' => $this->serviceId,
            'billing_type' => 'insured', 'insurance_id' => null, 'invoice_number' => 'INV-' . $this->seq,
            'ncf_number' => 'B02' . str_pad((string) $this->seq, 8, '0', STR_PAD_LEFT),
            'status_id' => $paidMethod ? 3 : 1, 'subtotal' => $total + $coverage, 'discount' => $coverage,
            'total' => $total, 'created_by' => $this->caja->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $inv = \App\Models\Invoices::find($id);
        app(AccountingService::class)->postInvoice($inv);
        if ($paidMethod) {
            $p = \App\Models\Payments::create([
                'invoices_id' => $id, 'payment_method_id' => $paidMethod, 'cash_session_id' => $sessionId,
                'amount' => $total, 'reference' => 'x', 'paid_at' => now(),
            ]);
            app(AccountingService::class)->postPayment($p, $inv);
        }

        return $id;
    }

    private function openSession(User $u, float $fund = 1000): int
    {
        return DB::table('acc_cash_sessions')->insertGetId([
            'user_id' => $u->id, 'location_id' => 2, 'status' => 'open', 'opened_at' => now(),
            'opening_amount' => $fund, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_requires_an_active_b04_sequence(): void
    {
        $id = $this->invoice();
        $this->as($this->admin)->getJson("/api/v1/billing/invoices/{$id}/annul-preview")
            ->assertOk()->assertJsonPath('can_annul', false);
        $this->as($this->admin)->postJson("/api/v1/billing/invoices/{$id}/annul", ['reason' => 'Motivo de prueba largo'])
            ->assertStatus(422);
        $this->assertDatabaseCount('in_credit_notes', 0);
    }

    public function test_cashier_cannot_annul(): void
    {
        $this->b04();
        $id = $this->invoice();
        $this->as($this->caja)->postJson("/api/v1/billing/invoices/{$id}/annul", ['reason' => 'Motivo de prueba largo'])
            ->assertStatus(403);
    }

    public function test_annul_unpaid_invoice_issues_b04_and_reverses_entry(): void
    {
        $this->b04();
        $id = $this->invoice(300, 700);

        $this->as($this->admin)->postJson("/api/v1/billing/invoices/{$id}/annul", ['reason' => 'Se facturó al paciente equivocado'])
            ->assertOk()->assertJsonPath('credit_note.ncf', 'B0400000001')->assertJsonPath('credit_note.refund_amount', 0);

        $this->assertDatabaseHas('in_invoices', ['id' => $id, 'status_id' => 4, 'canceled_by' => $this->admin->id]);
        $this->assertDatabaseHas('in_credit_notes', ['invoice_id' => $id, 'amount' => 1000]);
        $this->assertDatabaseHas('acc_journal_entries', ['source_type' => 'invoice', 'source_id' => $id, 'status' => 'reversed']);
        $this->assertDatabaseHas('acc_journal_entries', ['source_type' => 'credit_note', 'total' => 1000]);
        $this->assertDatabaseHas('sys_audit_logs', ['action' => 'invoice.annulled', 'entity_id' => $id]);

        // Las cuentas del paciente y la ARS quedan en cero.
        $net = DB::table('acc_journal_lines')->selectRaw('account_id, SUM(debit) - SUM(credit) as n')->groupBy('account_id')->pluck('n', 'account_id');
        foreach ($net as $n) {
            $this->assertEqualsWithDelta(0, (float) $n, 0.001);
        }

        $this->as($this->admin)->postJson("/api/v1/billing/invoices/{$id}/annul", ['reason' => 'Otra vez el mismo motivo'])
            ->assertStatus(422);
        $this->as($this->caja)->postJson('/api/v1/billing/save-payment', ['invoiceId' => $id, 'paymentMethodId' => 1, 'total' => 300])
            ->assertStatus(422);
    }

    public function test_cash_refund_comes_out_of_the_chosen_cash_session(): void
    {
        $this->b04();
        $session = $this->openSession($this->caja, 1000);
        $id = $this->invoice(300, 0, 1, $session);

        // Efectivo: hay que elegir caja; sin caja abierta propia del admin no adivina.
        $this->as($this->admin)->postJson("/api/v1/billing/invoices/{$id}/annul", ['reason' => 'El paciente se fue sin atenderse'])
            ->assertStatus(422);

        $this->as($this->admin)->postJson("/api/v1/billing/invoices/{$id}/annul", ['reason' => 'El paciente se fue sin atenderse', 'cash_session_id' => $session])
            ->assertOk()->assertJsonPath('credit_note.refund_amount', 300);

        $live = $this->as($this->caja)->getJson('/api/v1/cash/current')->assertOk()->json('data.live');
        $this->assertEquals(300, $live['cash_sales']);
        $this->assertEquals(300, $live['cash_refunds']);
        $this->assertEquals(1000, $live['expected_cash']);
        $this->assertDatabaseHas('acc_journal_entries', ['source_type' => 'refund', 'total' => 300]);
    }

    public function test_invoice_in_an_ars_claim_cannot_be_annulled(): void
    {
        $this->b04();
        $id = $this->invoice();
        $ins = DB::table('insurances')->insertGetId(['description' => 'ARS PRUEBA']);
        $claim = DB::table('acc_ars_claims')->insertGetId(['number' => 'RC-1', 'insurance_id' => $ins, 'period_from' => now()->startOfMonth()->toDateString(), 'period_to' => now()->toDateString(), 'status' => 'draft', 'total_claimed' => 700, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('acc_ars_claim_items')->insert(['claim_id' => $claim, 'invoice_id' => $id, 'amount_claimed' => 700, 'created_at' => now(), 'updated_at' => now()]);

        $this->as($this->admin)->getJson("/api/v1/billing/invoices/{$id}/annul-preview")
            ->assertOk()->assertJsonPath('can_annul', false);
    }

    public function test_607_file_has_official_layout_and_credit_notes(): void
    {
        $this->b04();
        $id = $this->invoice(300, 700, 1, $this->openSession($this->caja));
        $this->as($this->admin)->postJson("/api/v1/billing/invoices/{$id}/annul", ['reason' => 'Error en la cobertura del seguro', 'cash_session_id' => DB::table('acc_cash_sessions')->value('id')])->assertOk();

        $period = now()->format('Ym');
        $txt = $this->as($this->admin)->get("/api/v1/dgii/607/download?period={$period}&mode=all")->assertOk()->getContent();
        $lines = explode("\r\n", $txt);

        $this->assertSame('607|' . config('dgii.rnc') . "|{$period}|2", $lines[0]);
        $this->assertCount(3, $lines);
        foreach (array_slice($lines, 1) as $l) {
            $this->assertCount(23, explode('|', $l));
        }
        $inv = explode('|', $lines[1]);
        $this->assertSame(['00112345678', '2', 'B0200000001', '', '1'], array_slice($inv, 0, 5));
        $this->assertSame('1000.00', $inv[7]);
        $this->assertSame('300.00', $inv[16]);   // efectivo
        $this->assertSame('700.00', $inv[19]);   // a crédito (ARS)
        $nc = explode('|', $lines[2]);
        $this->assertSame(['B0400000001', 'B0200000001'], [$nc[2], $nc[3]]);
        $this->assertSame('', implode('', array_slice($nc, 16)));  // sin formas de pago

        // Con el mínimo, la B02 pequeña sale del detalle y va al resumen.
        $this->as($this->admin)->getJson("/api/v1/dgii/607?period={$period}&mode=threshold&min=250000")
            ->assertOk()->assertJsonPath('excluded.count', 1)->assertJsonPath('totals.credit_notes', 1);
        $this->as($this->caja)->getJson("/api/v1/dgii/607?period={$period}")->assertStatus(403);
    }

    public function test_dashboard_and_list_ignore_annulled_invoices(): void
    {
        $this->b04();
        $this->invoice(100, 0);
        $id = $this->invoice(300, 0);
        $this->as($this->admin)->postJson("/api/v1/billing/invoices/{$id}/annul", ['reason' => 'Factura duplicada por error'])->assertOk();

        $this->as($this->admin)->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('kpis.invoices', 1);
        $this->as($this->admin)->getJson('/api/v1/billing/invoices')->assertOk()
            ->assertJsonPath('totals.count', 1)->assertJsonPath('totals.annulled', 1);
    }

    public function test_audit_log_is_admin_only_and_records_doctor_moves(): void
    {
        $this->as($this->admin)->putJson("/api/v1/settings/doctors/{$this->doctorId}/service", ['serviceId' => $this->serviceId])->assertOk();
        $this->as($this->admin)->getJson('/api/v1/settings/audit?group=doctor')->assertOk()
            ->assertJsonPath('data.0.action', 'doctor.service_changed');
        $this->as($this->caja)->getJson('/api/v1/settings/audit')->assertStatus(403);
    }
}
