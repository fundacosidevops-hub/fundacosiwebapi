<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\Account;
use App\Models\Accounting\ArsClaim;
use App\Models\Accounting\ArsClaimItem;
use App\Models\Accounting\ArsPayment;
use App\Models\Invoices;
use App\Services\AccountingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Cuentas por cobrar a ARS: antigüedad, reclamaciones y liquidaciones.
 * La cobertura de cada factura asegurada (campo discount) queda por cobrar
 * a la ARS desde el día de la factura.
 */
class ArsController extends Controller
{
    use Concerns;

    /** Facturas aseguradas con cobertura. */
    private function insuredInvoices()
    {
        return Invoices::query()
            ->where('billing_type', 'insured')
            ->whereNotNull('insurance_id')
            ->where('discount', '>', 0);
    }

    /** Antigüedad de saldos por ARS (sin reclamar + reclamado pendiente). */
    public function receivables(Request $request)
    {
        $this->authorizePermission('accounting.view');
        $date = Carbon::parse($request->input('date', now()->toDateString()))->endOfDay();

        $rows = DB::table('in_invoices as f')
            ->leftJoin('acc_ars_claim_items as ci', 'ci.invoice_id', '=', 'f.id')
            ->leftJoin('insurances as i', 'i.id', '=', 'f.insurance_id')
            ->where('f.billing_type', 'insured')
            ->whereNotNull('f.insurance_id')
            ->where('f.discount', '>', 0)
            ->where('f.created_at', '<=', $date)
            ->get(['f.id', 'f.insurance_id', 'i.description as insurance', 'f.created_at', 'f.discount',
                'ci.id as item_id', 'ci.amount_paid', 'ci.amount_glosa']);

        $out = [];
        foreach ($rows as $r) {
            $pending = round((float) $r->discount - (float) ($r->amount_paid ?? 0) - (float) ($r->amount_glosa ?? 0), 2);
            if ($pending <= 0) {
                continue;
            }
            $key = $r->insurance_id;
            $out[$key] ??= [
                'insurance_id' => $r->insurance_id, 'insurance' => $r->insurance,
                'unclaimed' => 0, 'claimed' => 0, 'd0_30' => 0, 'd31_60' => 0, 'd61_90' => 0, 'd91' => 0,
                'total' => 0, 'invoices' => 0,
            ];
            $days = Carbon::parse($r->created_at)->startOfDay()->diffInDays($date->copy()->startOfDay());
            $bucket = $days <= 30 ? 'd0_30' : ($days <= 60 ? 'd31_60' : ($days <= 90 ? 'd61_90' : 'd91'));
            $out[$key][$bucket] = round($out[$key][$bucket] + $pending, 2);
            $out[$key][$r->item_id ? 'claimed' : 'unclaimed'] = round($out[$key][$r->item_id ? 'claimed' : 'unclaimed'] + $pending, 2);
            $out[$key]['total'] = round($out[$key]['total'] + $pending, 2);
            $out[$key]['invoices']++;
        }
        $list = collect($out)->sortByDesc('total')->values();

        // Cuadre contra el auxiliar contable (saldo de la cuenta CxC ARS).
        $ledger = DB::table('acc_journal_lines as l')
            ->join('acc_journal_entries as e', 'e.id', '=', 'l.entry_id')
            ->where('l.account_id', app(AccountingService::class)->mappedAccount('ar.ars'))
            ->where('e.date', '<=', $date->toDateString())
            ->selectRaw('COALESCE(SUM(l.debit),0) - COALESCE(SUM(l.credit),0) as b')->value('b');

        return response()->json([
            'date' => $date->toDateString(),
            'rows' => $list,
            'total' => round($list->sum('total'), 2),
            'ledger_balance' => round((float) $ledger, 2),
        ]);
    }

    /** Facturas de una ARS que todavía no están en ninguna reclamación. */
    public function pendingInvoices(Request $request)
    {
        $this->authorizePermission('accounting.view');
        $v = $request->validate([
            'insurance_id' => 'required|integer|exists:insurances,id',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);
        $q = $this->insuredInvoices()
            ->with('patient:id,name,last_name,document_number,policy')
            ->where('insurance_id', $v['insurance_id'])
            ->whereNotIn('id', ArsClaimItem::select('invoice_id'))
            ->orderBy('created_at');
        if (! empty($v['from'])) {
            $q->where('created_at', '>=', $v['from'] . ' 00:00:00');
        }
        if (! empty($v['to'])) {
            $q->where('created_at', '<=', $v['to'] . ' 23:59:59');
        }

        return response()->json($q->get()->map(fn ($f) => $this->invoiceRow($f)));
    }

    public function claims(Request $request)
    {
        $this->authorizePermission('accounting.view');
        $q = ArsClaim::with('insurance:id,description')->withCount('items')->orderByDesc('id');
        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }
        if ($request->filled('insurance_id')) {
            $q->where('insurance_id', $request->input('insurance_id'));
        }

        return response()->json($q->paginate((int) $request->input('per_page', 25)));
    }

    public function showClaim(int $id)
    {
        $this->authorizePermission('accounting.view');
        $claim = ArsClaim::with([
            'insurance:id,description,rnc',
            'items.invoice.patient:id,name,last_name,document_number,policy',
            'payments.bankAccount:id,code,name',
            'payments.creator:id,name,last_name',
            'creator:id,name,last_name',
        ])->findOrFail($id);

        $data = $claim->toArray();
        $data['items'] = $claim->items->map(fn ($it) => [
            'id' => $it->id,
            'amount_claimed' => (float) $it->amount_claimed,
            'amount_paid' => (float) $it->amount_paid,
            'amount_glosa' => (float) $it->amount_glosa,
            'pending' => $it->pending(),
            'glosa_reason' => $it->glosa_reason,
            'authorization_number' => $it->authorization_number,
        ] + $this->invoiceRow($it->invoice))->values();

        return response()->json($data);
    }

    public function storeClaim(Request $request)
    {
        $this->authorizePermission('accounting.manage');
        $v = $request->validate([
            'insurance_id' => 'required|integer|exists:insurances,id',
            'period_from' => 'required|date',
            'period_to' => 'required|date|after_or_equal:period_from',
            'invoice_ids' => 'nullable|array',
            'invoice_ids.*' => 'integer',
            'notes' => 'nullable|string|max:500',
        ]);

        $q = $this->insuredInvoices()
            ->where('insurance_id', $v['insurance_id'])
            ->whereNotIn('id', ArsClaimItem::select('invoice_id'))
            ->whereBetween('created_at', [$v['period_from'] . ' 00:00:00', $v['period_to'] . ' 23:59:59']);
        if (! empty($v['invoice_ids'])) {
            $q->whereIn('id', $v['invoice_ids']);
        }
        $invoices = $q->get();
        if ($invoices->isEmpty()) {
            return response()->json(['isSuccess' => false, 'message' => 'No hay facturas pendientes de reclamar para esa ARS en ese período.'], 422);
        }

        $claim = DB::transaction(function () use ($v, $invoices) {
            $year = now()->year;
            $last = ArsClaim::where('number', 'like', "RC-{$year}-%")->lockForUpdate()->max('number');
            $next = $last ? ((int) substr($last, -4)) + 1 : 1;
            $claim = ArsClaim::create([
                'number' => sprintf('RC-%d-%04d', $year, $next),
                'insurance_id' => $v['insurance_id'],
                'period_from' => $v['period_from'],
                'period_to' => $v['period_to'],
                'status' => 'draft',
                'notes' => $v['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);
            foreach ($invoices as $f) {
                $claim->items()->create([
                    'invoice_id' => $f->id,
                    'authorization_number' => $f->authorization_number,
                    'amount_claimed' => $f->discount,
                ]);
            }
            $this->refreshTotals($claim);

            return $claim;
        });

        return $this->showClaim($claim->id);
    }

    public function removeItem(int $id, int $itemId)
    {
        $this->authorizePermission('accounting.manage');
        $claim = ArsClaim::findOrFail($id);
        if ($claim->status !== 'draft') {
            return response()->json(['isSuccess' => false, 'message' => 'Solo se quitan facturas de una reclamación en preparación.'], 422);
        }
        $claim->items()->where('id', $itemId)->delete();
        $this->refreshTotals($claim);

        return $this->showClaim($claim->id);
    }

    public function destroyClaim(int $id)
    {
        $this->authorizePermission('accounting.manage');
        $claim = ArsClaim::findOrFail($id);
        if ($claim->status !== 'draft') {
            return response()->json(['isSuccess' => false, 'message' => 'Solo se elimina una reclamación en preparación.'], 422);
        }
        $claim->delete();

        return response()->json(['isSuccess' => true]);
    }

    public function sendClaim(Request $request, int $id)
    {
        $this->authorizePermission('accounting.manage');
        $v = $request->validate(['ars_reference' => 'nullable|string|max:60']);
        $claim = ArsClaim::findOrFail($id);
        if ($claim->status !== 'draft') {
            return response()->json(['isSuccess' => false, 'message' => 'La reclamación ya fue enviada.'], 422);
        }
        if (! $claim->items()->exists()) {
            return response()->json(['isSuccess' => false, 'message' => 'La reclamación no tiene facturas.'], 422);
        }
        $claim->update(['status' => 'sent', 'sent_at' => now(), 'ars_reference' => $v['ars_reference'] ?? $claim->ars_reference]);

        return $this->showClaim($claim->id);
    }

    /** Registra lo que pagó la ARS (y lo que glosó) en una reclamación. */
    public function storePayment(Request $request, int $id)
    {
        $this->authorizePermission('accounting.manage');
        $claim = ArsClaim::with('items')->findOrFail($id);
        $v = $request->validate([
            'date' => 'required|date',
            'bank_account_id' => ['required', 'integer', Rule::exists('acc_accounts', 'id')->where('is_postable', true)],
            'reference' => 'nullable|string|max:60',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|integer',
            'items.*.paid' => 'nullable|numeric|min:0',
            'items.*.glosa' => 'nullable|numeric|min:0',
            'items.*.glosa_reason' => 'nullable|string|max:255',
        ]);

        if (! in_array($claim->status, ['sent', 'partial'], true)) {
            return response()->json(['isSuccess' => false, 'message' => 'Primero marca la reclamación como enviada.'], 422);
        }
        if (Account::find($v['bank_account_id'])->type !== 'asset') {
            return response()->json(['isSuccess' => false, 'message' => 'El pago debe entrar a una cuenta de activo (banco o caja).'], 422);
        }

        $items = $claim->items->keyBy('id');
        $paid = 0.0;
        $glosa = 0.0;
        $applied = [];
        foreach ($v['items'] as $row) {
            $item = $items->get($row['item_id']);
            if (! $item) {
                return response()->json(['isSuccess' => false, 'message' => 'Una de las facturas no pertenece a esta reclamación.'], 422);
            }
            $p = round((float) ($row['paid'] ?? 0), 2);
            $g = round((float) ($row['glosa'] ?? 0), 2);
            if ($p + $g <= 0) {
                continue;
            }
            if ($p + $g > $item->pending() + 0.004) {
                $f = $item->invoice;

                return response()->json(['isSuccess' => false, 'message' => sprintf(
                    'La factura %s solo tiene %s pendiente.',
                    $f?->ncf_number ?? $f?->invoice_number ?? ('#' . $item->invoice_id),
                    number_format($item->pending(), 2)
                )], 422);
            }
            if ($g > 0 && empty($row['glosa_reason'])) {
                return response()->json(['isSuccess' => false, 'message' => 'Indica el motivo de cada glosa.'], 422);
            }
            $paid += $p;
            $glosa += $g;
            $applied[] = ['item_id' => $item->id, 'paid' => $p, 'glosa' => $g, 'glosa_reason' => $row['glosa_reason'] ?? null];
        }
        if (! $applied) {
            return response()->json(['isSuccess' => false, 'message' => 'Indica al menos un monto pagado o glosado.'], 422);
        }

        return $this->attempt(function () use ($claim, $v, $applied, $paid, $glosa, $items) {
            DB::transaction(function () use ($claim, $v, $applied, $paid, $glosa, $items) {
                $payment = ArsPayment::create([
                    'claim_id' => $claim->id,
                    'date' => $v['date'],
                    'amount_paid' => round($paid, 2),
                    'amount_glosa' => round($glosa, 2),
                    'bank_account_id' => $v['bank_account_id'],
                    'reference' => $v['reference'] ?? null,
                    'items' => $applied,
                    'created_by' => auth()->id(),
                ]);
                foreach ($applied as $a) {
                    $item = $items->get($a['item_id']);
                    $item->update([
                        'amount_paid' => round((float) $item->amount_paid + $a['paid'], 2),
                        'amount_glosa' => round((float) $item->amount_glosa + $a['glosa'], 2),
                        'glosa_reason' => $a['glosa_reason'] ?: $item->glosa_reason,
                    ]);
                }
                $this->refreshTotals($claim);
                app(AccountingService::class)->postArsPayment($payment);
            });

            return $this->showClaim($claim->id);
        });
    }

    private function refreshTotals(ArsClaim $claim): void
    {
        $t = $claim->items()->selectRaw('COALESCE(SUM(amount_claimed),0) c, COALESCE(SUM(amount_paid),0) p, COALESCE(SUM(amount_glosa),0) g')->first();
        $status = $claim->status;
        if (in_array($status, ['sent', 'partial'], true) && ((float) $t->p + (float) $t->g) > 0) {
            $status = round((float) $t->c - (float) $t->p - (float) $t->g, 2) <= 0 ? 'paid' : 'partial';
        }
        $claim->update([
            'total_claimed' => round((float) $t->c, 2),
            'total_paid' => round((float) $t->p, 2),
            'total_glosa' => round((float) $t->g, 2),
            'status' => $status,
        ]);
    }

    private function invoiceRow(?Invoices $f): array
    {
        if (! $f) {
            return [];
        }

        return [
            'invoice_id' => $f->id,
            'ncf' => $f->ncf_number ?? $f->invoice_number,
            'date' => optional($f->created_at)->toDateString(),
            'patient' => trim(($f->patient?->name ?? '') . ' ' . ($f->patient?->last_name ?? '')),
            'document' => $f->patient?->document_number,
            'policy' => $f->patient?->policy,
            'authorization_number' => $f->authorization_number,
            'subtotal' => (float) $f->subtotal,
            'coverage' => (float) $f->discount,
            'patient_amount' => (float) $f->total,
        ];
    }
}
