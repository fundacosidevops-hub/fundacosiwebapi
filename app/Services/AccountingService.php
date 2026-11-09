<?php

namespace App\Services;

use App\Exceptions\AccountingException;
use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\AccountMapping;
use App\Models\Accounting\ArsPayment;
use App\Models\Accounting\CashSession;
use App\Models\Accounting\JournalEntry;
use App\Models\Invoices;
use App\Models\Payments;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Asientos contables de partida doble.
 *
 * Asientos automáticos:
 *  - Factura:      Dr CxC pacientes (lo que paga el paciente)
 *                  Dr CxC ARS (cobertura del seguro)  |  Dr Descuentos (privado)
 *                  Cr Ingresos por servicios
 *  - Cobro:        Dr Caja / Banco / Tarjetas (según forma de pago)
 *                  Cr CxC pacientes
 *  - Pago de ARS:  Dr Banco (pagado) + Dr Glosas (rechazado)
 *                  Cr CxC ARS
 *  - Cierre caja:  faltante → Dr Faltantes / Cr Caja; sobrante → Dr Caja / Cr Sobrantes
 */
class AccountingService
{
    private static ?bool $enabled = null;

    private array $mappingCache = [];

    /** La contabilidad funciona solo si ya se corrieron las migraciones. */
    public static function enabled(): bool
    {
        if (self::$enabled === null) {
            self::$enabled = Schema::hasTable('acc_journal_entries') && Schema::hasTable('acc_cash_sessions');
        }

        return self::$enabled;
    }

    /** Cuenta configurada para un asiento automático. */
    public function mappedAccount(string $key, ?string $fallbackKey = null): int
    {
        if (! array_key_exists($key, $this->mappingCache)) {
            $this->mappingCache[$key] = AccountMapping::where('key', $key)->value('account_id');
        }
        $id = $this->mappingCache[$key];
        if (! $id && $fallbackKey) {
            return $this->mappedAccount($fallbackKey);
        }
        if (! $id) {
            throw new AccountingException("Falta configurar la cuenta para «{$key}» en Contabilidad → Configuración.");
        }

        return (int) $id;
    }

    public function paymentMethodAccount(int $paymentMethodId): int
    {
        return $this->mappedAccount('payment_method.' . $paymentMethodId, 'payment.default');
    }

    /**
     * Registra un asiento. Si el documento ya tiene asiento, devuelve el existente.
     *
     * @param  array{date:string, description:string, source_type:string, source_id?:int|null,
     *               created_by?:int|null, reversal_of_id?:int|null, lines:array}  $data
     */
    public function post(array $data): JournalEntry
    {
        $sourceId = $data['source_id'] ?? null;
        if ($sourceId !== null) {
            $existing = JournalEntry::where('source_type', $data['source_type'])->where('source_id', $sourceId)->first();
            if ($existing) {
                return $existing;
            }
        }

        $lines = [];
        foreach ($data['lines'] as $line) {
            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);
            if ($debit < 0 || $credit < 0) {
                throw new AccountingException('Los montos de un asiento no pueden ser negativos.');
            }
            if ($debit == 0 && $credit == 0) {
                continue;
            }
            if ($debit > 0 && $credit > 0) {
                throw new AccountingException('Una línea no puede tener débito y crédito a la vez.');
            }
            $lines[] = array_merge($line, ['debit' => $debit, 'credit' => $credit]);
        }
        if (count($lines) < 2) {
            throw new AccountingException('Un asiento necesita al menos dos líneas con monto.');
        }

        $totalDebit = round(array_sum(array_column($lines, 'debit')), 2);
        $totalCredit = round(array_sum(array_column($lines, 'credit')), 2);
        if (abs($totalDebit - $totalCredit) > 0.004) {
            throw new AccountingException(sprintf(
                'El asiento no cuadra: débitos %s y créditos %s.',
                number_format($totalDebit, 2),
                number_format($totalCredit, 2)
            ));
        }

        $accountIds = array_unique(array_map(fn ($l) => (int) $l['account_id'], $lines));
        $accounts = Account::whereIn('id', $accountIds)->get()->keyBy('id');
        foreach ($accountIds as $id) {
            $account = $accounts->get($id);
            if (! $account) {
                throw new AccountingException("La cuenta {$id} no existe.");
            }
            if (! $account->is_postable) {
                throw new AccountingException("La cuenta {$account->code} {$account->name} es de grupo y no recibe movimientos.");
            }
            if (! $account->is_active) {
                throw new AccountingException("La cuenta {$account->code} {$account->name} está inactiva.");
            }
        }

        $date = Carbon::parse($data['date'])->toDateString();
        $this->assertPeriodOpen($date);

        return DB::transaction(function () use ($data, $lines, $date, $totalDebit, $sourceId) {
            $year = (int) substr($date, 0, 4);
            $sequence = (int) JournalEntry::where('year', $year)->lockForUpdate()->max('sequence') + 1;

            $entry = JournalEntry::create([
                'year' => $year,
                'sequence' => $sequence,
                'number' => sprintf('AS-%d-%06d', $year, $sequence),
                'date' => $date,
                'description' => mb_substr($data['description'], 0, 255),
                'source_type' => $data['source_type'],
                'source_id' => $sourceId,
                'status' => 'posted',
                'reversal_of_id' => $data['reversal_of_id'] ?? null,
                'total' => $totalDebit,
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);

            foreach ($lines as $line) {
                $entry->lines()->create([
                    'account_id' => $line['account_id'],
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'description' => isset($line['description']) ? mb_substr($line['description'], 0, 255) : null,
                    'insurance_id' => $line['insurance_id'] ?? null,
                    'patient_id' => $line['patient_id'] ?? null,
                    'catalog_services_id' => $line['catalog_services_id'] ?? null,
                    'payment_method_id' => $line['payment_method_id'] ?? null,
                ]);
            }

            return $entry->load('lines');
        });
    }

    public function assertPeriodOpen(string $date): void
    {
        $d = Carbon::parse($date);
        $period = AccountingPeriod::firstOrCreate(
            ['year' => $d->year, 'month' => $d->month],
            ['status' => 'open']
        );
        if ($period->status === 'closed') {
            throw new AccountingException(sprintf(
                'El mes %02d/%d está cerrado en contabilidad. Reábrelo para registrar movimientos con esa fecha.',
                $d->month,
                $d->year
            ));
        }
    }

    /** Asiento de una factura (al guardarla). */
    public function postInvoice(Invoices $invoice): ?JournalEntry
    {
        if (! self::enabled()) {
            Log::warning('Contabilidad sin migrar: factura sin asiento', ['invoice' => $invoice->id]);

            return null;
        }

        $total = round((float) $invoice->total, 2);       // lo que paga el paciente
        $coverage = round((float) $invoice->discount, 2); // cobertura del seguro (o descuento)
        $income = round($total + $coverage, 2);
        if ($income <= 0) {
            return null;
        }

        $insured = $invoice->billing_type === 'insured' && $invoice->insurance_id;
        $lines = [
            [
                'account_id' => $this->mappedAccount('ar.patients'),
                'debit' => $total,
                'patient_id' => $invoice->patient_id,
                'description' => 'Parte del paciente',
            ],
        ];
        if ($coverage > 0) {
            $lines[] = $insured
                ? [
                    'account_id' => $this->mappedAccount('ar.ars'),
                    'debit' => $coverage,
                    'insurance_id' => $invoice->insurance_id,
                    'patient_id' => $invoice->patient_id,
                    'description' => 'Cobertura del seguro',
                ]
                : [
                    'account_id' => $this->mappedAccount('income.discounts'),
                    'debit' => $coverage,
                    'patient_id' => $invoice->patient_id,
                    'description' => 'Descuento',
                ];
        }
        $lines[] = [
            'account_id' => $this->mappedAccount('income.services'),
            'credit' => $income,
            'catalog_services_id' => $invoice->catalog_services_id,
            'patient_id' => $invoice->patient_id,
            'insurance_id' => $insured ? $invoice->insurance_id : null,
            'description' => 'Servicios facturados',
        ];

        $ncf = $invoice->ncf_number ?? $invoice->invoice_number ?? ('#' . $invoice->id);

        return $this->post([
            'date' => optional($invoice->created_at)->toDateString() ?? now()->toDateString(),
            'description' => "Factura {$ncf}",
            'source_type' => 'invoice',
            'source_id' => $invoice->id,
            'created_by' => $invoice->created_by,
            'lines' => $lines,
        ]);
    }

    /** Asiento de un cobro en caja. */
    public function postPayment(Payments $payment, ?Invoices $invoice = null): ?JournalEntry
    {
        if (! self::enabled()) {
            Log::warning('Contabilidad sin migrar: cobro sin asiento', ['payment' => $payment->id]);

            return null;
        }
        $amount = round((float) $payment->amount, 2);
        if ($amount <= 0) {
            return null;
        }
        $invoice ??= Invoices::find($payment->invoices_id);
        $ncf = $invoice?->ncf_number ?? $invoice?->invoice_number ?? ('#' . $payment->invoices_id);

        return $this->post([
            'date' => optional($payment->paid_at ?? $payment->created_at)->toDateString() ?? now()->toDateString(),
            'description' => "Cobro factura {$ncf}",
            'source_type' => 'payment',
            'source_id' => $payment->id,
            'lines' => [
                [
                    'account_id' => $this->paymentMethodAccount((int) $payment->payment_method_id),
                    'debit' => $amount,
                    'payment_method_id' => $payment->payment_method_id,
                    'patient_id' => $invoice?->patient_id,
                ],
                [
                    'account_id' => $this->mappedAccount('ar.patients'),
                    'credit' => $amount,
                    'patient_id' => $invoice?->patient_id,
                ],
            ],
        ]);
    }

    /** Asiento de una liquidación (pago) de una ARS. */
    public function postArsPayment(ArsPayment $payment): JournalEntry
    {
        $claim = $payment->claim()->with('insurance')->first();
        $paid = round((float) $payment->amount_paid, 2);
        $glosa = round((float) $payment->amount_glosa, 2);

        return $this->post([
            'date' => $payment->date->toDateString(),
            'description' => "Pago de {$claim->insurance?->description} · reclamación {$claim->number}"
                . ($payment->reference ? " · ref. {$payment->reference}" : ''),
            'source_type' => 'ars_payment',
            'source_id' => $payment->id,
            'created_by' => $payment->created_by,
            'lines' => [
                ['account_id' => $payment->bank_account_id, 'debit' => $paid, 'insurance_id' => $claim->insurance_id, 'description' => 'Pagado por la ARS'],
                ['account_id' => $this->mappedAccount('ars.glosa'), 'debit' => $glosa, 'insurance_id' => $claim->insurance_id, 'description' => 'Glosas (no reconocido)'],
                ['account_id' => $this->mappedAccount('ar.ars'), 'credit' => round($paid + $glosa, 2), 'insurance_id' => $claim->insurance_id],
            ],
        ]);
    }

    /** Asiento por faltante o sobrante al cerrar una caja. */
    public function postCashDifference(CashSession $session): ?JournalEntry
    {
        $diff = round((float) $session->difference, 2);
        if (abs($diff) < 0.005) {
            return null;
        }
        $cash = $this->mappedAccount('cash.main');
        $who = $session->user?->name;
        $lines = $diff < 0
            ? [
                ['account_id' => $this->mappedAccount('cash.short'), 'debit' => abs($diff), 'description' => 'Faltante de caja'],
                ['account_id' => $cash, 'credit' => abs($diff)],
            ]
            : [
                ['account_id' => $cash, 'debit' => $diff],
                ['account_id' => $this->mappedAccount('cash.over'), 'credit' => $diff, 'description' => 'Sobrante de caja'],
            ];

        return $this->post([
            'date' => $session->closed_at->toDateString(),
            'description' => ($diff < 0 ? 'Faltante' : 'Sobrante') . " al cerrar caja #{$session->id}" . ($who ? " ({$who})" : ''),
            'source_type' => 'cash_close',
            'source_id' => $session->id,
            'created_by' => $session->closed_by,
            'lines' => $lines,
        ]);
    }

    /**
     * Asiento de una nota de crédito (anulación total): invierte el asiento de
     * la factura. Si la factura no tenía asiento, se arma con los mismos
     * criterios que postInvoice() pero al revés. El asiento original queda
     * marcado como «reversed».
     */
    public function postCreditNote(\App\Models\CreditNote $note, Invoices $invoice): ?JournalEntry
    {
        if (! self::enabled()) {
            Log::warning('Contabilidad sin migrar: nota de crédito sin asiento', ['credit_note' => $note->id]);

            return null;
        }

        $original = JournalEntry::with('lines')->where('source_type', 'invoice')->where('source_id', $invoice->id)->first();
        $lines = $original
            ? $original->lines->map(fn ($l) => [
                'account_id' => $l->account_id,
                'debit' => $l->credit,
                'credit' => $l->debit,
                'description' => $l->description,
                'insurance_id' => $l->insurance_id,
                'patient_id' => $l->patient_id,
                'catalog_services_id' => $l->catalog_services_id,
                'payment_method_id' => $l->payment_method_id,
            ])->all()
            : $this->invoiceLinesReversed($invoice);

        if (! $lines) {
            return null;
        }

        $ncf = $invoice->ncf_number ?? $invoice->invoice_number ?? ('#' . $invoice->id);

        return DB::transaction(function () use ($note, $lines, $original, $ncf) {
            $entry = $this->post([
                'date' => optional($note->created_at)->toDateString() ?? now()->toDateString(),
                'description' => "Nota de crédito {$note->ncf_number} · anula factura {$ncf}",
                'source_type' => 'credit_note',
                'source_id' => $note->id,
                'reversal_of_id' => $original?->id,
                'created_by' => $note->created_by,
                'lines' => $lines,
            ]);
            $original?->update(['status' => 'reversed']);

            return $entry;
        });
    }

    /** Asiento de la devolución al paciente: Dr CxC pacientes / Cr Caja o banco. */
    public function postRefund(\App\Models\CreditNote $note, Invoices $invoice): ?JournalEntry
    {
        if (! self::enabled()) {
            return null;
        }
        $amount = round((float) $note->refund_amount, 2);
        if ($amount <= 0 || ! $note->refund_method_id) {
            return null;
        }

        return $this->post([
            'date' => optional($note->refunded_at ?? $note->created_at)->toDateString() ?? now()->toDateString(),
            'description' => "Devolución al paciente · nota de crédito {$note->ncf_number}",
            'source_type' => 'refund',
            'source_id' => $note->id,
            'created_by' => $note->created_by,
            'lines' => [
                [
                    'account_id' => $this->mappedAccount('ar.patients'),
                    'debit' => $amount,
                    'patient_id' => $invoice->patient_id,
                    'description' => 'Devolución por anulación',
                ],
                [
                    'account_id' => $this->paymentMethodAccount((int) $note->refund_method_id),
                    'credit' => $amount,
                    'payment_method_id' => $note->refund_method_id,
                    'patient_id' => $invoice->patient_id,
                ],
            ],
        ]);
    }

    /** ¿La forma de pago va a la cuenta de caja (efectivo)? */
    public function isCashMethod(int $paymentMethodId): bool
    {
        return $this->paymentMethodAccount($paymentMethodId) === $this->mappedAccount('cash.main');
    }

    /** Líneas de postInvoice() invertidas (factura sin asiento previo). */
    private function invoiceLinesReversed(Invoices $invoice): array
    {
        $total = round((float) $invoice->total, 2);
        $coverage = round((float) $invoice->discount, 2);
        $income = round($total + $coverage, 2);
        if ($income <= 0) {
            return [];
        }
        $insured = $invoice->billing_type === 'insured' && $invoice->insurance_id;
        $lines = [[
            'account_id' => $this->mappedAccount('income.services'),
            'debit' => $income,
            'catalog_services_id' => $invoice->catalog_services_id,
            'patient_id' => $invoice->patient_id,
            'insurance_id' => $insured ? $invoice->insurance_id : null,
            'description' => 'Servicios anulados',
        ], [
            'account_id' => $this->mappedAccount('ar.patients'),
            'credit' => $total,
            'patient_id' => $invoice->patient_id,
            'description' => 'Parte del paciente',
        ]];
        if ($coverage > 0) {
            $lines[] = [
                'account_id' => $this->mappedAccount($insured ? 'ar.ars' : 'income.discounts'),
                'credit' => $coverage,
                'insurance_id' => $insured ? $invoice->insurance_id : null,
                'patient_id' => $invoice->patient_id,
                'description' => $insured ? 'Cobertura del seguro' : 'Descuento',
            ];
        }

        return $lines;
    }

    /** Reversa un asiento manual con otro asiento de signos invertidos. */
    public function reverse(JournalEntry $entry, string $date, ?string $reason = null): JournalEntry
    {
        if ($entry->status === 'reversed') {
            throw new AccountingException("El asiento {$entry->number} ya fue reversado.");
        }
        if ($entry->source_type !== 'manual') {
            throw new AccountingException('Solo se reversan asientos manuales. Los automáticos se corrigen desde su documento (factura, cobro, caja o ARS).');
        }

        return DB::transaction(function () use ($entry, $date, $reason) {
            $reversal = $this->post([
                'date' => $date,
                'description' => "Reverso de {$entry->number}" . ($reason ? ": {$reason}" : ''),
                'source_type' => 'reversal',
                'source_id' => $entry->id,
                'reversal_of_id' => $entry->id,
                'lines' => $entry->lines->map(fn ($l) => [
                    'account_id' => $l->account_id,
                    'debit' => $l->credit,
                    'credit' => $l->debit,
                    'description' => $l->description,
                    'insurance_id' => $l->insurance_id,
                    'patient_id' => $l->patient_id,
                    'catalog_services_id' => $l->catalog_services_id,
                    'payment_method_id' => $l->payment_method_id,
                ])->all(),
            ]);
            $entry->update(['status' => 'reversed']);

            return $reversal;
        });
    }
}
