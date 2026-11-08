<?php

namespace App\Http\Controllers;

use App\Models\Accounting\CashSession;
use App\Models\Accounting\JournalEntry;
use App\Models\Invoices;
use App\Models\Payments;
use App\Models\User;
use App\Services\AccountingService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finanza → Facturas: consulta de las facturas emitidas (solo lectura).
 */
class InvoiceController extends Controller
{
    private const PAID = 3;

    public function index(Request $request)
    {
        $this->authorizeView();
        $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'status' => 'nullable|in:all,paid,pending',
            'type' => 'nullable|in:all,insured,private',
            'cashier' => 'nullable|integer',
            'insurance_id' => 'nullable|integer',
            'per_page' => 'nullable|integer|min:5|max:200',
        ], [
            'from.date_format' => 'La fecha «desde» no es válida.',
            'to.date_format' => 'La fecha «hasta» no es válida.',
        ]);

        $q = $this->filtered($request);

        // Totales del filtro completo (no solo de la página).
        $totals = (clone $q)->toBase()->selectRaw(
            'COUNT(*) as count, COALESCE(SUM(subtotal),0) as subtotal, COALESCE(SUM(discount),0) as discount, COALESCE(SUM(total),0) as total'
        )->first();
        $paid = (float) Payments::whereIn('invoices_id', (clone $q)->select('in_invoices.id'))->sum('amount');

        $with = [
            'patient:id,name,last_name,document_number',
            'doctor:id,name,last_name',
            'insurance:id,description',
            'catalogServices:id,description',
            'creator:id,name,last_name',
            'payments.paymentMethod:id,name',
        ];

        if ($request->boolean('export')) {
            $rows = (clone $q)->with($with)->orderByDesc('created_at')->limit(5000)->get();

            return response()->json(['data' => $rows->map(fn ($i) => $this->row($i))]);
        }

        $page = (clone $q)->with($with)->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($request->integer('per_page', 25));

        return response()->json([
            'data' => collect($page->items())->map(fn ($i) => $this->row($i)),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'totals' => [
                'count' => (int) $totals->count,
                'subtotal' => round((float) $totals->subtotal, 2),
                'coverage' => round((float) $totals->discount, 2),
                'total' => round((float) $totals->total, 2),
                'paid' => round($paid, 2),
                'pending' => round(max(0, (float) $totals->total - $paid), 2),
            ],
        ]);
    }

    public function show($id)
    {
        $this->authorizeView();
        $i = Invoices::with([
            'patient:id,name,last_name,document_number,birth_date,policy,phone',
            'doctor:id,name,last_name',
            'insurance:id,description',
            'catalogServices:id,description',
            'creator:id,name,last_name',
            'status:id,name',
            'items.medicalStudy:id,description',
            'payments.paymentMethod:id,name',
        ])->findOrFail($id);

        $sessions = [];
        if (AccountingService::enabled()) {
            $sessionIds = $i->payments->pluck('cash_session_id')->filter()->unique();
            $sessions = CashSession::with('user:id,name,last_name')->whereIn('id', $sessionIds)->get()
                ->mapWithKeys(fn ($c) => [$c->id => trim(($c->user?->name ?? '') . ' ' . ($c->user?->last_name ?? ''))])
                ->all();
        }

        $entries = [];
        if (AccountingService::enabled()) {
            $entries = JournalEntry::where(fn ($w) => $w->where('source_type', 'invoice')->where('source_id', $i->id))
                ->orWhere(fn ($w) => $w->where('source_type', 'payment')->whereIn('source_id', $i->payments->pluck('id')))
                ->orderBy('id')->get(['id', 'number', 'date', 'source_type', 'total', 'status'])
                ->map(fn ($e) => [
                    'id' => $e->id,
                    'number' => $e->number,
                    'date' => $e->date?->format('Y-m-d'),
                    'kind' => $e->source_type === 'invoice' ? 'Factura' : 'Cobro',
                    'total' => (float) $e->total,
                ]);
        }

        $claim = null;
        if (Schema::hasTable('acc_ars_claim_items')) {
            $claim = DB::table('acc_ars_claim_items')->join('acc_ars_claims', 'acc_ars_claims.id', '=', 'acc_ars_claim_items.claim_id')
                ->where('acc_ars_claim_items.invoice_id', $i->id)
                ->first(['acc_ars_claims.id', 'acc_ars_claims.number', 'acc_ars_claims.status']);
        }

        return response()->json($this->row($i) + [
            'authorization_number' => $i->authorization_number,
            'patient_birth_date' => $i->patient?->birth_date,
            'patient_age' => $i->patient?->birth_date ? Carbon::parse($i->patient->birth_date)->age : null,
            'patient_policy' => $i->patient?->policy,
            'status_name' => $i->status?->name,
            'items' => $i->items->map(function ($it) {
                $gross = round((float) $it->unit_price * (int) $it->quantity, 2);
                // Facturación guarda en insurance_coverage el porcentaje que
                // cubre el seguro (0–100).
                $pct = (float) $it->insurance_coverage;
                $covered = $pct <= 100 ? round($gross * $pct / 100, 2) : min($gross, $pct);

                return [
                    'description' => $it->description ?: ($it->medicalStudy?->description ?? 'Estudio'),
                    'quantity' => (int) $it->quantity,
                    'unit_price' => (float) $it->unit_price,
                    'coverage_pct' => $pct <= 100 ? $pct : null,
                    'coverage' => $covered,
                    'patient' => round($gross - $covered, 2),
                ];
            }),
            'payments' => $i->payments->map(fn ($p) => [
                'id' => $p->id,
                'method' => $p->paymentMethod?->name,
                'amount' => (float) $p->amount,
                'paid_at' => $p->paid_at,
                'cashier' => $sessions[$p->cash_session_id] ?? null,
            ]),
            'entries' => $entries,
            'ars_claim' => $claim ? ['id' => $claim->id, 'number' => $claim->number, 'status' => $claim->status] : null,
        ]);
    }

    /** Usuarios que han emitido facturas (para el filtro «Cajero»). */
    public function cashiers()
    {
        $this->authorizeView();
        $ids = Invoices::query()->whereNotNull('created_by')->distinct()->pluck('created_by');

        return response()->json(
            User::whereIn('id', $ids)->orderBy('name')->get(['id', 'name', 'last_name'])
        );
    }

    /* ------------------------------------------------------------------ */

    private function filtered(Request $request): Builder
    {
        $q = Invoices::query();
        if ($from = $request->input('from')) {
            $q->where('created_at', '>=', $from . ' 00:00:00');
        }
        if ($to = $request->input('to')) {
            $q->where('created_at', '<=', $to . ' 23:59:59');
        }
        $status = $request->input('status', 'all');
        if ($status === 'paid') {
            $q->where('status_id', self::PAID);
        } elseif ($status === 'pending') {
            $q->where(fn ($w) => $w->where('status_id', '!=', self::PAID)->orWhereNull('status_id'));
        }
        $type = $request->input('type', 'all');
        if ($type !== 'all') {
            $q->where('billing_type', $type);
        }
        if ($cashier = $request->integer('cashier')) {
            $q->where('created_by', $cashier);
        }
        if ($ins = $request->integer('insurance_id')) {
            $q->where('insurance_id', $ins);
        }
        if ($search = trim((string) $request->input('search'))) {
            $q->where(function ($w) use ($search) {
                $w->where('ncf_number', 'like', "%{$search}%")
                    ->orWhereHas('patient', function ($p) use ($search) {
                        $p->where('name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('document_number', 'like', "%{$search}%");
                    });
                if (ctype_digit($search)) {
                    $w->orWhere('in_invoices.id', (int) $search)->orWhere('patient_id', (int) $search);
                }
            });
        }

        return $q;
    }

    private function row(Invoices $i): array
    {
        $paidAmount = (float) $i->payments->sum('amount');
        $last = $i->payments->sortByDesc('paid_at')->first();

        return [
            'id' => $i->id,
            'ncf' => $i->ncf_number,
            'created_at' => $i->created_at,
            'patient_id' => $i->patient_id,
            'patient' => trim(($i->patient?->name ?? '') . ' ' . ($i->patient?->last_name ?? '')),
            'document' => $i->patient?->document_number,
            'service' => $i->catalogServices?->description,
            'doctor' => trim(($i->doctor?->name ?? '') . ' ' . ($i->doctor?->last_name ?? '')),
            'insurance' => $i->insurance?->description,
            'billing_type' => $i->billing_type,
            'subtotal' => (float) $i->subtotal,
            'coverage' => (float) $i->discount,
            'total' => (float) $i->total,
            'paid' => (int) $i->status_id === self::PAID,
            'paid_amount' => $paidAmount,
            'payment_method' => $last?->paymentMethod?->name,
            'paid_at' => $last?->paid_at,
            'cashier' => trim(($i->creator?->name ?? '') . ' ' . ($i->creator?->last_name ?? '')),
        ];
    }

    private function authorizeView(): void
    {
        $user = auth()->user();
        abort_unless(
            $user && ($user->can('billing.view') || $user->can('accounting.view') || $user->hasAnyRole(['SuperAdmin', 'Administrador'])),
            403,
            'No tienes permiso para ver las facturas.'
        );
    }
}
