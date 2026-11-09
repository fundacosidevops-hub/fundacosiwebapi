<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\CashSession;
use App\Models\Payments;
use App\Services\AccountingService;
use App\Services\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuadre de caja. Cada cajero abre su caja con un fondo, cobra, y al final
 * cuenta el efectivo. La diferencia contra lo esperado se contabiliza.
 */
class CashSessionController extends Controller
{
    use Concerns;

    /** Caja abierta del usuario actual (o null). */
    public function current()
    {
        $session = CashSession::where('user_id', auth()->id())->where('status', 'open')->first();

        // Envuelto en "data": Laravel convierte un null suelto en {}.
        return response()->json(['data' => $session ? $this->withTotals($session) : null]);
    }

    public function open(Request $request)
    {
        $v = $request->validate([
            'opening_amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        if (CashSession::where('user_id', auth()->id())->where('status', 'open')->exists()) {
            return response()->json(['isSuccess' => false, 'message' => 'Ya tienes una caja abierta.'], 422);
        }

        $session = CashSession::create([
            'user_id' => auth()->id(),
            'location_id' => auth()->user()->user_locations_id,
            'status' => 'open',
            'opened_at' => now(),
            'opening_amount' => $v['opening_amount'],
            'notes' => $v['notes'] ?? null,
        ]);
        AuditLog::record('cash.opened', 'cash_session', $session->id,
            sprintf('Abrió la caja #%d con fondo de %s', $session->id, number_format((float) $session->opening_amount, 2)),
            null, ['opening_amount' => (float) $session->opening_amount]);

        return response()->json($this->withTotals($session), 201);
    }

    /** Cierra la caja propia; con accounting.manage se puede cerrar la de otro. */
    public function close(Request $request, ?int $id = null)
    {
        $v = $request->validate([
            'counted_cash' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $session = $id
            ? CashSession::findOrFail($id)
            : CashSession::where('user_id', auth()->id())->where('status', 'open')->first();

        if (! $session) {
            return response()->json(['isSuccess' => false, 'message' => 'No tienes una caja abierta.'], 422);
        }
        if ($session->user_id !== auth()->id()) {
            $this->authorizePermission('accounting.manage');
        }
        if ($session->status !== 'open') {
            return response()->json(['isSuccess' => false, 'message' => 'Esta caja ya está cerrada.'], 422);
        }

        return $this->attempt(function () use ($session, $v) {
            DB::transaction(function () use ($session, $v) {
                $totals = $this->totals($session);
                $session->update([
                    'status' => 'closed',
                    'closed_at' => now(),
                    'closed_by' => auth()->id(),
                    'expected_cash' => $totals['expected_cash'],
                    'counted_cash' => $v['counted_cash'],
                    'difference' => round($v['counted_cash'] - $totals['expected_cash'], 2),
                    'totals_by_method' => $totals['by_method'],
                    'payments_count' => $totals['count'],
                    'notes' => trim(($session->notes ? $session->notes . "\n" : '') . ($v['notes'] ?? '')) ?: null,
                ]);
                app(AccountingService::class)->postCashDifference($session->fresh('user'));
            });
            $s = $session->fresh('user');
            AuditLog::record('cash.closed', 'cash_session', $s->id, sprintf(
                'Cerró la caja #%d%s: esperado %s, contado %s, diferencia %s',
                $s->id,
                (int) $s->user_id !== (int) auth()->id() ? ' de ' . trim(($s->user?->name ?? '') . ' ' . ($s->user?->last_name ?? '')) : '',
                number_format((float) $s->expected_cash, 2),
                number_format((float) $s->counted_cash, 2),
                number_format((float) $s->difference, 2)
            ), null, ['expected' => (float) $s->expected_cash, 'counted' => (float) $s->counted_cash, 'difference' => (float) $s->difference]);

            return response()->json($this->withTotals($session->fresh()));
        });
    }

    public function index(Request $request)
    {
        $this->authorizePermission('accounting.view');
        $q = CashSession::with(['user:id,name,last_name', 'closer:id,name,last_name'])->orderByDesc('opened_at');
        if ($request->filled('from')) {
            $q->where('opened_at', '>=', $request->input('from') . ' 00:00:00');
        }
        if ($request->filled('to')) {
            $q->where('opened_at', '<=', $request->input('to') . ' 23:59:59');
        }
        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }

        return response()->json($q->paginate((int) $request->input('per_page', 25)));
    }

    public function show(int $id)
    {
        $session = CashSession::with(['user:id,name,last_name', 'closer:id,name,last_name'])->findOrFail($id);
        if ($session->user_id !== auth()->id()) {
            $this->authorizePermission('accounting.view');
        }
        $payments = Payments::with(['paymentMethod:id,name,code', 'invoices.patient:id,name,last_name'])
            ->where('cash_session_id', $session->id)->orderBy('paid_at')->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'paid_at' => $p->paid_at,
                'amount' => (float) $p->amount,
                'method' => $p->paymentMethod?->name,
                'invoice_id' => $p->invoices_id,
                'ncf' => $p->invoices?->ncf_number ?? $p->invoices?->invoice_number,
                'patient' => trim(($p->invoices?->patient?->name ?? '') . ' ' . ($p->invoices?->patient?->last_name ?? '')),
            ]);

        return response()->json($this->withTotals($session) + ['payments' => $payments]);
    }

    /** Totales de la caja: por forma de pago y efectivo esperado. */
    private function totals(CashSession $session): array
    {
        $svc = app(AccountingService::class);
        $cashAccount = $svc->mappedAccount('cash.main');
        $rows = DB::table('in_payments as p')
            ->join('in_payment_methods as m', 'm.id', '=', 'p.payment_method_id')
            ->where('p.cash_session_id', $session->id)
            ->groupBy('m.id', 'm.name')
            ->selectRaw('m.id, m.name, COUNT(*) as count, SUM(p.amount) as total')
            ->get();

        $byMethod = [];
        $cash = 0.0;
        $count = 0;
        foreach ($rows as $r) {
            $isCash = $svc->paymentMethodAccount((int) $r->id) === $cashAccount;
            $byMethod[] = ['method_id' => $r->id, 'method' => $r->name, 'count' => (int) $r->count, 'total' => round((float) $r->total, 2), 'is_cash' => $isCash];
            $count += (int) $r->count;
            if ($isCash) {
                $cash += (float) $r->total;
            }
        }

        // Devoluciones por anulación (notas de crédito) hechas desde esta caja:
        // el efectivo devuelto sale de la caja y se resta del esperado.
        $refunds = [];
        $cashRefunds = 0.0;
        if (Schema::hasTable('in_credit_notes')) {
            $refunds = DB::table('in_credit_notes as n')
                ->join('in_invoices as i', 'i.id', '=', 'n.invoice_id')
                ->leftJoin('in_payment_methods as m', 'm.id', '=', 'n.refund_method_id')
                ->where('n.cash_session_id', $session->id)
                ->where('n.refund_amount', '>', 0)
                ->orderBy('n.refunded_at')
                ->get(['n.id', 'n.ncf_number', 'n.refund_amount', 'n.refund_method_id', 'n.refunded_at', 'i.ncf_number as invoice_ncf', 'm.name as method'])
                ->map(function ($r) use ($svc, $cashAccount, &$cashRefunds) {
                    $isCash = $r->refund_method_id && $svc->paymentMethodAccount((int) $r->refund_method_id) === $cashAccount;
                    if ($isCash) {
                        $cashRefunds += (float) $r->refund_amount;
                    }

                    return [
                        'credit_note' => $r->ncf_number,
                        'invoice_ncf' => $r->invoice_ncf,
                        'amount' => round((float) $r->refund_amount, 2),
                        'method' => $r->method,
                        'is_cash' => $isCash,
                        'refunded_at' => $r->refunded_at,
                    ];
                })->values()->all();
        }

        return [
            'by_method' => $byMethod,
            'count' => $count,
            'cash_sales' => round($cash, 2),
            'total_sales' => round(array_sum(array_column($byMethod, 'total')), 2),
            'refunds' => $refunds,
            'cash_refunds' => round($cashRefunds, 2),
            'expected_cash' => round((float) $session->opening_amount + $cash - $cashRefunds, 2),
        ];
    }

    private function withTotals(CashSession $session): array
    {
        $session->loadMissing(['user:id,name,last_name']);
        $live = $this->totals($session);

        return $session->toArray() + ['live' => $live];
    }
}
