<?php

namespace App\Http\Controllers;

use App\Exceptions\AccountingException;
use App\Http\Middleware\RequireAccess;
use App\Models\Accounting\CashSession;
use App\Models\CreditNote;
use App\Models\Invoices;
use App\Models\NcfSequences;
use App\Services\AccountingService;
use App\Services\AuditLog;
use App\Services\NcfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Anulación total de una factura con nota de crédito (NCF B04).
 *
 * Quién: SuperAdmin, Administrador o quien tenga accounting.manage (Contabilidad).
 * Qué hace, todo o nada:
 *  1. Genera el NCF B04 y guarda la nota (in_credit_notes).
 *  2. Marca la factura «Anulada» con motivo y usuario.
 *  3. Contabilidad: invierte el asiento de la factura y, si estaba cobrada,
 *     registra la devolución (Dr CxC pacientes / Cr caja o banco).
 *  4. Si se devolvió efectivo, sale de la caja abierta elegida (resta en su cuadre).
 *  5. Deja constancia en la bitácora.
 */
class CreditNoteController extends Controller
{
    public function preview(int $id)
    {
        $this->authorizeAnnul();
        $invoice = Invoices::with(['payments.paymentMethod:id,name,code'])->findOrFail($id);

        return response()->json($this->analyze($invoice));
    }

    public function annul(Request $request, int $id)
    {
        $this->authorizeAnnul();
        $v = $request->validate([
            'reason' => 'required|string|min:10|max:500',
            'cash_session_id' => 'nullable|integer',
        ], [
            'reason.required' => 'Escribe el motivo de la anulación.',
            'reason.min' => 'Explica el motivo con al menos 10 caracteres.',
            'reason.max' => 'El motivo admite hasta 500 caracteres.',
        ]);

        try {
            $note = DB::transaction(function () use ($id, $v) {
                $invoice = Invoices::with(['payments.paymentMethod:id,name,code'])->lockForUpdate()->findOrFail($id);
                $check = $this->analyze($invoice);
                if (! $check['can_annul']) {
                    throw new AccountingException($check['blocked_reason']);
                }

                // Devolución: misma forma de pago del cobro.
                $refund = $check['paid'];
                $sessionId = null;
                if ($refund && $refund['is_cash']) {
                    $sessionId = (int) ($v['cash_session_id'] ?? 0)
                        ?: optional(collect($check['open_sessions'])->firstWhere('mine', true))['id'];
                    $session = $sessionId ? CashSession::where('id', $sessionId)->lockForUpdate()->first() : null;
                    if (! $session || $session->status !== 'open') {
                        throw new AccountingException('Elige una caja abierta de donde saldrá el efectivo de la devolución.');
                    }
                }

                app(AccountingService::class)->assertPeriodOpen(now()->toDateString());

                $note = CreditNote::create([
                    'invoice_id' => $invoice->id,
                    'ncf_number' => NcfService::generate('B04'),
                    'reason' => trim($v['reason']),
                    'amount' => $check['amount'],
                    'patient_amount' => $check['patient_amount'],
                    'coverage' => $check['coverage'],
                    'refund_amount' => $refund['amount'] ?? 0,
                    'refund_method_id' => $refund['method_id'] ?? null,
                    'cash_session_id' => $sessionId,
                    'refunded_at' => $refund ? now() : null,
                    'created_by' => auth()->id(),
                ]);

                // canceled_by y cancel_reason no están en $fillable del modelo.
                $invoice->forceFill([
                    'status_id' => $this->annulledStatusId(),
                    'canceled_by' => auth()->id(),
                    'cancel_reason' => trim($v['reason']),
                ])->save();

                $acc = app(AccountingService::class);
                $acc->postCreditNote($note, $invoice);
                $acc->postRefund($note, $invoice);

                AuditLog::record(
                    'invoice.annulled',
                    'invoice',
                    $invoice->id,
                    "Anuló la factura {$invoice->ncf_number} con la nota de crédito {$note->ncf_number}"
                        . ($refund ? sprintf(' y devolvió %s (%s)', number_format($refund['amount'], 2), $refund['method']) : '')
                        . '. Motivo: ' . trim($v['reason']),
                    ['status' => $check['status_name']],
                    [
                        'credit_note' => $note->ncf_number,
                        'amount' => $check['amount'],
                        'refund' => $refund['amount'] ?? 0,
                        'refund_method' => $refund['method'] ?? null,
                        'cash_session_id' => $sessionId,
                    ]
                );

                return $note;
            });
        } catch (AccountingException $e) {
            return response()->json(['isSuccess' => false, 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            // NcfService lanza Exception con mensajes claros (sin secuencia, vencida, agotada).
            if (str_contains($e->getMessage(), 'B04')) {
                return response()->json(['isSuccess' => false, 'message' => $e->getMessage() . '. Regístrala en Ajustes → Comprobantes NCF.'], 422);
            }
            throw $e;
        }

        return response()->json([
            'isSuccess' => true,
            'message' => "Factura anulada con la nota de crédito {$note->ncf_number}.",
            'credit_note' => $this->noteRow($note->fresh(['refundMethod:id,name', 'creator:id,name,last_name'])),
        ]);
    }

    /* ------------------------------------------------------------------ */

    /** Revisa si se puede anular y qué pasará (montos, devolución, cajas). */
    private function analyze(Invoices $invoice): array
    {
        $out = [
            'enabled' => self::enabled(),
            'can_annul' => false,
            'blocked_reason' => null,
            'ncf' => $invoice->ncf_number,
            'status_name' => optional($invoice->status)->name,
            'amount' => round((float) $invoice->total + (float) $invoice->discount, 2),
            'patient_amount' => round((float) $invoice->total, 2),
            'coverage' => round((float) $invoice->discount, 2),
            'paid' => null,
            'open_sessions' => [],
            'b04' => $this->b04Status(),
        ];

        $payment = $invoice->payments->sortByDesc('id')->first();
        if ($payment && (float) $payment->amount > 0) {
            $isCash = AccountingService::enabled()
                ? app(AccountingService::class)->isCashMethod((int) $payment->payment_method_id)
                : ($payment->paymentMethod?->code === 'cash');
            $out['paid'] = [
                'amount' => round((float) $invoice->payments->sum('amount'), 2),
                'method_id' => (int) $payment->payment_method_id,
                'method' => $payment->paymentMethod?->name,
                'is_cash' => $isCash,
            ];
        }

        if (AccountingService::enabled()) {
            $out['open_sessions'] = CashSession::with('user:id,name,last_name')->where('status', 'open')->orderBy('opened_at')->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'user' => trim(($c->user?->name ?? '') . ' ' . ($c->user?->last_name ?? '')),
                    'opened_at' => $c->opened_at,
                    'mine' => (int) $c->user_id === (int) auth()->id(),
                ])->values()->all();
        }

        $block = fn (string $why) => ['can_annul' => false, 'blocked_reason' => $why] + $out;

        if (! self::enabled()) {
            return $block('Falta instalar las anulaciones: corre la migración 2026_11_10_000001.');
        }
        if (CreditNote::where('invoice_id', $invoice->id)->exists() || (int) $invoice->status_id === $this->annulledStatusId()) {
            return $block('Esta factura ya está anulada.');
        }
        if (! $invoice->ncf_number) {
            return $block('La factura no tiene NCF; no lleva nota de crédito.');
        }
        if (! $out['b04']['available']) {
            return $block($out['b04']['message']);
        }
        if (Schema::hasTable('acc_ars_claim_items')) {
            $claim = DB::table('acc_ars_claim_items')->join('acc_ars_claims', 'acc_ars_claims.id', '=', 'acc_ars_claim_items.claim_id')
                ->where('acc_ars_claim_items.invoice_id', $invoice->id)
                ->first(['acc_ars_claims.number', 'acc_ars_claims.status']);
            if ($claim) {
                return $block($claim->status === 'draft'
                    ? "Está en la reclamación {$claim->number} (borrador). Quítala de la reclamación antes de anular."
                    : "Ya fue reclamada a la ARS (reclamación {$claim->number}). Se corrige como glosa con la ARS.");
            }
        }
        if ($out['paid'] && $out['paid']['is_cash'] && ! $out['open_sessions']) {
            return $block('La factura se cobró en efectivo y no hay ninguna caja abierta para hacer la devolución.');
        }

        return ['can_annul' => true] + $out;
    }

    private function b04Status(): array
    {
        $seq = NcfSequences::where('type', 'B04')->where('is_active', true)->first();
        if (! $seq) {
            return ['available' => false, 'message' => 'No hay secuencia B04 (notas de crédito) activa. Regístrala en Ajustes → Comprobantes NCF.'];
        }
        if ($seq->expires_at && now()->gt($seq->expires_at)) {
            return ['available' => false, 'message' => 'La secuencia B04 está vencida. Registra una nueva en Ajustes → Comprobantes NCF.'];
        }
        $next = $seq->current_number ? $seq->current_number + 1 : $seq->from_number;
        if ($next > $seq->to_number) {
            return ['available' => false, 'message' => 'La secuencia B04 está agotada. Registra una nueva en Ajustes → Comprobantes NCF.'];
        }

        return [
            'available' => true,
            'next' => $seq->prefix . str_pad((string) $next, 8, '0', STR_PAD_LEFT),
            'remaining' => (int) $seq->to_number - $next + 1,
            'message' => null,
        ];
    }

    public static function enabled(): bool
    {
        return Schema::hasTable('in_credit_notes');
    }

    private function annulledStatusId(): int
    {
        return (int) DB::table('in_invoice_statuses')->where('name', 'Anulada')->value('id');
    }

    public static function noteRow(?CreditNote $n): ?array
    {
        if (! $n) {
            return null;
        }

        return [
            'id' => $n->id,
            'ncf' => $n->ncf_number,
            'reason' => $n->reason,
            'amount' => $n->amount,
            'refund_amount' => $n->refund_amount,
            'refund_method' => $n->refundMethod?->name,
            'cash_session_id' => $n->cash_session_id,
            'created_at' => $n->created_at,
            'created_by' => $n->creator ? trim($n->creator->name . ' ' . $n->creator->last_name) : null,
        ];
    }

    private function authorizeAnnul(): void
    {
        abort_unless(
            RequireAccess::allows(auth()->user(), ['accounting.manage']),
            403,
            'Solo administración y Contabilidad pueden anular facturas.'
        );
    }
}
