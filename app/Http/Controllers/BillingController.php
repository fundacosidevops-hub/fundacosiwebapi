<?php

namespace App\Http\Controllers;

use App\Exceptions\AccountingException;
use App\Http\Resources\UserInfoResource;
use App\Models\Accounting\CashSession;
use App\Services\AccountingService;
use App\Services\AuditLog;
use App\Models\InvoiceItems;
use App\Models\Invoices;
use App\Models\PaymentMethods;
use App\Models\Payments;
use App\Models\QueueManager;
use App\Models\User;
use App\Services\NcfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            new OA\Parameter(
                name: 'userTypeId',
                in: 'query',
                required: true,
                description: 'Tipo de usuario (1: Empleado 2: Paciente, 3: Doctor )',
                schema: new OA\Schema(type: 'integer'),
                example: '1'
            ),
            new OA\Parameter(
                name: 'searchType',
                in: 'query',
                required: true,
                description: 'Tipo de busqueda (1: Cedula, 2: No. registro)',
                schema: new OA\Schema(type: 'integer'),
                example: '1'
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datos obtenido correctamente'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    public function getUserBillingInfo(Request $request)
    {
        $document = str_replace(' ', '', (string) $request->document);
        // Facturación solo trabaja con pacientes: empleados y doctores no se
        // consultan aquí (se gestionan en Ajustes → Usuarios del sistema).
        $userTypeId = 2;
        $searchType = $request->searchType;

        $user = User::with([
            'position',
            'nationalities',
            'maritalStatus',
            'documentType',
            'insurance',
            'userType',
            'roles',
            'medicalCatalogServicesByUser',
            'relationShipDependent',
            'relationShipDependent.relationShipType',
            'relationShipType',
            'holder.insurance',
            'holder.relationShipDependent.relationShipType',
        ])->where('user_type_id', $userTypeId);

        // 1 = cédula, 3 = pasaporte (ambos se guardan en document_number),
        // 2 = número de registro (ID interno). Cualquier otro valor no busca
        // nada: antes devolvía el primer paciente de la tabla.
        if ($document === '') {
            return response()->json(null, 200);
        }
        if (in_array((int) $searchType, [1, 3], true)) {
            $user = $user->where('document_number', $document);
        } elseif ((int) $searchType === 2 && ctype_digit($document)) {
            $user = $user->where('id', (int) $document);
        } else {
            return response()->json(null, 200);
        }

        $user = $user->first();
        return response()->json($user ? new UserInfoResource($user) : null, 200);
    }

    /**
     * El turno guarda el paciente exacto (patient_user_id). Los turnos viejos,
     * sin ese dato, se comparan por documento: el del paciente o, si es un
     * dependiente, el de su tutor.
     */
    private function ticketBelongsToPatient(QueueManager $ticket, User $patient): bool
    {
        if ($ticket->patient_user_id) {
            return (int) $ticket->patient_user_id === $patient->id;
        }

        $document = (string) $ticket->patient_id;
        if ($document !== '' && $document === (string) $patient->document_number) {
            return true;
        }

        return $patient->dependent_of
            && User::whereKey($patient->dependent_of)->where('document_number', $document)->exists();
    }

    public function generateNextInvoice(string $lastInvoice): string
    {
        $prefix = substr($lastInvoice, 0, 1); // B
        $number = substr($lastInvoice, 1);    // 20569988

        $nextNumber = (int) $number + 1;

        return $prefix . str_pad($nextNumber, strlen($number), '0', STR_PAD_LEFT);
    }

    #[OA\Post(
        path: '/api/v1/save-bill',
        summary: 'Guardar factura',
        tags: ['Billing'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['patientId', 'doctorId', 'billingType', 'subtotal', 'total', 'items'],
                properties: [
                    new OA\Property(property: 'patientId', type: 'string', example: '123456789'),
                    new OA\Property(property: 'doctorId', type: 'integer', example: 1),
                    new OA\Property(property: 'insuranceId', type: 'integer', example: 2),
                    new OA\Property(property: 'ticketId', type: 'integer', example: 2),
                    new OA\Property(property: 'billingType', type: 'string', example: 'insured'),
                    new OA\Property(property: 'authorizationNumber', type: 'integer', example: 12345),
                    new OA\Property(property: 'catalogServiceId', type: 'integer', example: 1),
                    new OA\Property(property: 'subtotal', type: 'number', example: 1000),
                    new OA\Property(property: 'discount', type: 'number', example: 200),
                    new OA\Property(property: 'total', type: 'number', example: 800),

                    new OA\Property(
                        property: 'items',
                        type: 'array',
                        items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'medicalStudiesId', type: 'integer', example: 1),
                                new OA\Property(property: 'quantity', type: 'integer', example: 1),
                                new OA\Property(property: 'unitPrice', type: 'number', example: 500),
                                new OA\Property(property: 'discount', type: 'number', example: 0),
                                new OA\Property(property: 'insuranceCoverage', type: 'number', example: 50),
                                new OA\Property(property: 'patientAmount', type: 'number', example: 250),
                                new OA\Property(property: 'total', type: 'number', example: 500),
                            ]
                        )
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Factura creada correctamente'),
            new OA\Response(response: 400, description: 'Datos inválidos'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    public function save(Request $request)
    {
        $validated = $request->validate([
            'id' => 'required|integer',
            // Puede venir vacío para un menor sin documento; se factura por id.
            'patientId' => 'nullable|string',
            'doctorId' => 'required|integer',
            'insuranceId' => 'nullable|integer',
            'billingType' => 'required|in:private,insured',
            'authorizationNumber' => 'nullable|integer',
            'catalogServiceId' => 'required|integer',
            'ticketId' => 'nullable|integer',
            'subtotal' => 'required|numeric',
            'discount' => 'required|numeric',
            'total' => 'required|numeric',

            'items' => 'required|array|min:1',

            'items.*.medicalStudiesId' => 'required',
            'items.*.insuranceCoverage' => 'numeric',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unitPrice' => 'required|numeric',
            'items.*.total' => 'required|numeric',
        ]);

        // Toda factura se emite dentro de una caja abierta del cajero (para que
        // cada NCF quede en un turno de caja y en su cuadre).
        if (AccountingService::enabled()
            && ! CashSession::where('user_id', auth()->id())->where('status', 'open')->exists()) {
            return response()->json([
                'message' => 'Abre tu caja antes de facturar.',
                'isSuccess' => false,
                'code' => 'cash_session_required',
            ], 422);
        }

        if (empty($validated['ticketId'])) {
            return response()->json([
                'message' => 'Este paciente no tiene turno. Llama su turno (F2) antes de facturar.',
                'isSuccess' => false,
            ], 422);
        }

        // buscaa el paciente por documento
        $patient = User::where('id', $validated['id'])->first();

        if (! $patient) {
            return response()->json([
                'message' => 'No se puede facturar el paciente porque no esta creado en el sistema.',
                'isSuccess' => false,
            ], 404);
        }

        // Toda factura sale de un turno, y el turno debe ser de este paciente
        // (cada persona saca su propio turno, aunque sea de la misma familia).
        $ticket = QueueManager::find($validated['ticketId']);

        if (! $ticket) {
            return response()->json([
                'message' => 'El turno no existe. Llama el turno del paciente antes de facturar.',
                'isSuccess' => false,
            ], 422);
        }

        if (! $this->ticketBelongsToPatient($ticket, $patient)) {
            return response()->json([
                'message' => "El turno {$ticket->ticket} es de otra persona. Llama el turno de este paciente para facturarlo.",
                'isSuccess' => false,
            ], 422);
        }

        DB::beginTransaction();

        try {

            // crear factura
            $invoice = Invoices::create([
                'patient_id' => $patient->id,
                'doctor_id' => $validated['doctorId'],
                'insurance_id' => $validated['insuranceId'] ?? null,
                'catalog_services_id' => $validated['catalogServiceId'] ?? null,
                'status_id' => 1,
                'authorization_number' => $validated['authorizationNumber'] ?? null,
                'billing_type' => $validated['billingType'],
                'ncf_number' => NcfService::generate('B02'),
                'subtotal' => $validated['subtotal'],
                'discount' => $validated['discount'],
                'total' => $validated['total'],
                'created_by' => auth()->id(),
            ]);

            // Guardar items
            foreach ($validated['items'] as $item) {
                InvoiceItems::create([
                    'invoices_id' => $invoice->id,
                    'medical_study_id' => $item['medicalStudiesId'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unitPrice'],
                    'discount' => $item['discount'] ?? 0,
                    'insurance_coverage' => $item['insuranceCoverage'],
                    'patient_amount' => $item['patientAmount'] ?? 0,
                    'total' => $item['total'],
                ]);
            }
            $ticket->update(['status' => 'done']);
            // Turno del que salió la factura (Historial clínico lo muestra y ordena por él).
            if (Schema::hasColumn('in_invoices', 'queue_manager_id')) {
                $invoice->forceFill(['queue_manager_id' => $ticket->id])->save();
            }

            // Asiento contable de la factura (CxC paciente / CxC ARS / ingresos).
            app(AccountingService::class)->postInvoice($invoice);

            DB::commit();
            AuditLog::record('invoice.created', 'invoice', $invoice->id, sprintf(
                'Facturó %s a %s: %s de servicios, %s a pagar por el paciente',
                $invoice->ncf_number,
                trim(($patient->name ?? '') . ' ' . ($patient->last_name ?? '')),
                number_format((float) $invoice->total + (float) $invoice->discount, 2),
                number_format((float) $invoice->total, 2)
            ), null, [
                'ncf' => $invoice->ncf_number,
                'subtotal' => (float) $invoice->subtotal,
                'coverage' => (float) $invoice->discount,
                'total' => (float) $invoice->total,
                'billing_type' => $invoice->billing_type,
                'insurance_id' => $invoice->insurance_id,
            ]);
            $invoice->load([
                'patient',
                'doctor',
                'insurance',
                'catalogServices',
                'status',
                'items.medicalStudy',
                'payments',
                'creator',
            ]);

            return response()->json($invoice, 200);
        } catch (AccountingException $e) {
            DB::rollBack();

            return response()->json([
                'message' => $e->getMessage(),
                'isSuccess' => false,
            ], 422);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'message' => 'Error al guardar la factura',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    #[OA\Post(
        path: '/api/v1/save-payment',
        summary: 'Guardar pago a factura',
        tags: ['Billing'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['invoiceId', 'paymentMethodId', 'total'],
                properties: [
                    new OA\Property(property: 'invoiceId', type: 'integer', example: 2),
                    new OA\Property(property: 'paymentMethodId', type: 'integer', example: 1),
                    new OA\Property(property: 'total', type: 'integer', example: 2),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Factura creada correctamente'),
            new OA\Response(response: 400, description: 'Datos inválidos'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    public function savePayment(Request $request)
    {
        $validated = $request->validate([
            'invoiceId' => 'required|integer',
            'paymentMethodId' => 'required|integer',
            'total' => 'required|numeric',
        ]);

        $invoice = Invoices::find($validated['invoiceId']);
        if (! $invoice) {
            return response()->json(['message' => 'La factura no existe.', 'isSuccess' => false], 404);
        }
        if (Payments::where('invoices_id', $invoice->id)->exists()) {
            return response()->json(['message' => 'Esta factura ya fue cobrada.', 'isSuccess' => false], 422);
        }
        if ((int) $invoice->status_id === (int) DB::table('in_invoice_statuses')->where('name', 'Anulada')->value('id')) {
            return response()->json(['message' => 'Esta factura está anulada; no se puede cobrar.', 'isSuccess' => false], 422);
        }

        // Todo cobro entra en la caja abierta del cajero (para el cuadre).
        $session = null;
        if (AccountingService::enabled()) {
            $session = CashSession::where('user_id', auth()->id())->where('status', 'open')->first();
            if (! $session) {
                return response()->json([
                    'message' => 'Abre tu caja antes de cobrar.',
                    'isSuccess' => false,
                    'code' => 'cash_session_required',
                ], 422);
            }
        }

        DB::beginTransaction();

        try {
            // crear pago a factura
            $payment = Payments::create([
                'invoices_id' => $invoice->id,
                'payment_method_id' => $validated['paymentMethodId'],
                'cash_session_id' => $session?->id,
                'amount' => $validated['total'],
                'reference' => 'Pago a factura No.' . $invoice->id,
                'paid_at' => now(),
            ]);

            // cambiar estado a la factura a pagado
            $invoice->update(['status_id' => 3]);

            // Asiento contable del cobro (caja/banco contra CxC paciente).
            app(AccountingService::class)->postPayment($payment, $invoice);

            DB::commit();
            AuditLog::record('payment.created', 'payment', $payment->id, sprintf(
                'Cobró %s de la factura %s (%s)',
                number_format((float) $payment->amount, 2),
                $invoice->ncf_number,
                PaymentMethods::where('id', $payment->payment_method_id)->value('name') ?? 'forma de pago #' . $payment->payment_method_id
            ), null, ['invoice_id' => $invoice->id, 'amount' => (float) $payment->amount, 'cash_session_id' => $session?->id]);

            return response()->json([
                'message' => 'Pago creado correctamente',
                'data' => $payment,
            ], 200);
        } catch (AccountingException $e) {
            DB::rollBack();

            return response()->json(['message' => $e->getMessage(), 'isSuccess' => false], 422);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'message' => 'Error al guardar pago de factura',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    #[OA\Get(
        path: '/api/v1/billing/payment-methods',
        summary: 'Obtener todos los metodo de pago a facturar',
        tags: ['Billing'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Datos obtenido correctamente'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    public function getPaymentMethods()
    {
        return response()->json(
            PaymentMethods::where('is_active', true)->get()
        );
    }
    #[OA\Get(
        path: '/api/v1/billing/get-all-cashier-list',
        summary: 'Cajeros a los que se puede asignar un turno',
        tags: ['Billing'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'locationId',
                description: 'Sede; si no se envía, la del usuario que consulta',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datos obtenido correctamente'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    public function getAllCashierList(Request $request)
    {
        $request->validate(['locationId' => 'nullable|integer']);
        $locationId = (int) ($request->input('locationId') ?: auth()->user()?->user_locations_id);

        return response()->json(self::eligibleCashiers($locationId)->values());
    }

    /**
     * Buscar estudio → editar: guarda en el catálogo el precio del estudio
     * (medical_studies, aplica a todas las ARS) y, si se factura con ARS, su
     * % de cobertura para esa ARS (insurances_rates). Quien factura puede
     * hacerlo (ver rutas); queda en la bitácora.
     */
    public function updateStudyRate(Request $request, int $id)
    {
        $v = $request->validate([
            'price' => 'required|numeric|min:0|max:9999999',
            'insuranceId' => 'nullable|integer|exists:insurances,id',
            'percentage' => 'nullable|numeric|min:0|max:100|required_with:insuranceId',
        ], [
            'price.required' => 'Escribe el precio.',
            'price.min' => 'El precio no puede ser negativo.',
            'price.max' => 'El precio es demasiado alto.',
            'percentage.min' => 'La cobertura no puede ser menor que 0 %.',
            'percentage.max' => 'La cobertura no puede pasar de 100 %.',
            'percentage.required_with' => 'Escribe el % de cobertura.',
        ]);

        $study = DB::table('medical_studies')->where('id', $id)->first(['id', 'description', 'price']);
        if (! $study) {
            return response()->json(['isSuccess' => false, 'message' => 'El estudio no existe.'], 404);
        }

        $rate = null;
        if (! empty($v['insuranceId'])) {
            $rate = DB::table('insurances_rates')
                ->where('medical_studies_id', $id)
                ->where('insurances_id', $v['insuranceId'])
                ->first(['id', 'percentage']);
            if (! $rate) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Ese estudio no tiene tarifa para esta ARS.',
                ], 422);
            }
        }

        $price = round((float) $v['price'], 2);
        $percentage = $rate ? round((float) $v['percentage'], 2) : null;

        DB::transaction(function () use ($id, $price, $rate, $percentage) {
            DB::table('medical_studies')->where('id', $id)->update(['price' => $price, 'updated_at' => now()]);
            if ($rate) {
                DB::table('insurances_rates')->where('id', $rate->id)->update(['percentage' => $percentage, 'updated_at' => now()]);
            }
        });

        $insurance = $rate ? DB::table('insurances')->where('id', $v['insuranceId'])->value('description') : null;
        $parts = [];
        if ((float) $study->price !== $price) {
            $parts[] = sprintf('precio %s → %s', number_format((float) $study->price, 2), number_format($price, 2));
        }
        if ($rate && (float) $rate->percentage !== $percentage) {
            $parts[] = sprintf('cobertura %s %% → %s %% en %s', rtrim(rtrim(number_format((float) $rate->percentage, 2), '0'), '.'), rtrim(rtrim(number_format($percentage, 2), '0'), '.'), $insurance);
        }
        if ($parts) {
            AuditLog::record('study.rate_changed', 'medical_study', $id,
                sprintf('Estudio «%s»: %s', $study->description, implode('; ', $parts)),
                ['price' => (float) $study->price, 'percentage' => $rate ? (float) $rate->percentage : null, 'insurance' => $insurance],
                ['price' => $price, 'percentage' => $percentage, 'insurance' => $insurance]);
        }

        return response()->json([
            'isSuccess' => true,
            'message' => $parts ? 'Estudio actualizado en el catálogo.' : 'Sin cambios.',
            'data' => ['id' => $id, 'price' => $price, 'percentage' => $percentage],
        ]);
    }

    /** Roles que pueden facturar y recibir turnos. */
    public const CASHIER_ROLES = ['Caja', 'Administrador', 'SuperAdmin'];

    /**
     * Cajeros a los que se puede asignar un turno de la sede: usuarios activos
     * con rol Caja, Administrador o SuperAdmin, de esa sede y con su caja
     * abierta. (Si la base aún no tiene el módulo de caja, no se exige caja.)
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public static function eligibleCashiers(int $locationId)
    {
        $query = User::query()
            ->where('is_active', true)
            ->where('user_locations_id', $locationId)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', self::CASHIER_ROLES)->where('guard_name', 'api'));

        if (Schema::hasTable('acc_cash_sessions')) {
            $query->whereIn('id', CashSession::where('status', 'open')->select('user_id'));
        }

        return $query->orderBy('name')->orderBy('last_name')
            ->get(['id', 'name', 'last_name', 'email', 'avatar', 'is_active', 'user_locations_id']);
    }
}
