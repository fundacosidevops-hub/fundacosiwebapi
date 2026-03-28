<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserInfoResource;
use App\Models\InvoiceItems;
use App\Models\Invoices;
use App\Models\PaymentMethods;
use App\Models\Payments;
use App\Models\User;
use App\Services\NcfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        ],
        responses: [
            new OA\Response(response: 200, description: 'Datos obtenido correctamente'),
            new OA\Response(response: 401, description: 'No autorizado'),
        ]
    )]
    public function getUserBillingInfo(Request $request)
    {
        $document = str_replace(' ', '', $request->document);

        $user = User::with([
            'position',
            'nationalities',
            'maritalStatus',
            'documentType',
            'insurance',
            'userType',
            'roles',
        ])
            ->where('document_number', $document)
            ->first();

        return response()->json([
            'message' => $user ? 'Usuario encontrado' : 'Usuario no encontrado',
            'isSuccess' => (bool) $user,
            'data' => $user ? new UserInfoResource($user) : null,
        ], 200);

    }

    public function generateNextInvoice($lastInvoice)
    {
        $prefix = substr($lastInvoice, 0, 1); // B
        $number = substr($lastInvoice, 1);    // 20569988

        $nextNumber = (int) $number + 1;

        return $prefix.str_pad($nextNumber, strlen($number), '0', STR_PAD_LEFT);
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
            'patientId' => 'required|string',
            'doctorId' => 'required|integer',
            'insuranceId' => 'nullable|integer',
            'billingType' => 'required|in:private,insured',
            'authorizationNumber' => 'nullable|integer',
            'catalogServiceId' => 'required|integer',

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

        DB::beginTransaction();

        try {

            // buscaa el paciente por documento
            $patient = User::where('document_number', $validated['patientId'])->first();

            if (! $patient) {
                return response()->json([
                    'message' => 'No se puede facturar el paciente porque no esta creado en el sistema.',
                    'isSuccess' => false,
                ], 404);
            }

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

            DB::commit();
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

        DB::beginTransaction();

        try {
            // crear pago a factura
            $invoice = Payments::create([
                'invoices_id' => $validated['invoiceId'],
                'payment_method_id' => $validated['paymentMethodId'],
                'amount' => $validated['total'],
                'reference' => 'Pago a factura No.'.$validated['invoiceId'],
                'paid_at' => NOW(),
            ]);

            DB::commit();
            // cambiar estado a la factura a pagado
            Invoices::where('id', $validated['invoiceId'])->update([
                'status_id' => 3,
            ]);

            return response()->json([
                'message' => 'Pago creado correctamente',
                'data' => $invoice,
            ], 200);

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
}
