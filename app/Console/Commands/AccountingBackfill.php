<?php

namespace App\Console\Commands;

use App\Models\Accounting\JournalEntry;
use App\Models\Invoices;
use App\Models\Payments;
use App\Services\AccountingService;
use Illuminate\Console\Command;

/**
 * Crea los asientos de las facturas y cobros que se hicieron antes de activar
 * la contabilidad. Se puede correr varias veces: no duplica asientos.
 *
 *   php artisan accounting:backfill --dry-run   (solo cuenta)
 *   php artisan accounting:backfill
 */
class AccountingBackfill extends Command
{
    protected $signature = 'accounting:backfill {--dry-run : Solo muestra cuántos asientos faltan}';

    protected $description = 'Genera los asientos contables de facturas y cobros anteriores';

    public function handle(AccountingService $accounting): int
    {
        if (! AccountingService::enabled()) {
            $this->error('Primero corre las migraciones de contabilidad (php artisan migrate).');

            return self::FAILURE;
        }

        $postedInvoices = JournalEntry::where('source_type', 'invoice')->pluck('source_id');
        $postedPayments = JournalEntry::where('source_type', 'payment')->pluck('source_id');
        // Documentos en cero (por ejemplo, cobertura del 100 %: el paciente
        // paga 0) no llevan asiento; se informan aparte.
        $invoices = Invoices::whereNotIn('id', $postedInvoices)->whereRaw('(COALESCE(total,0) + COALESCE(discount,0)) > 0')->orderBy('id');
        $payments = Payments::whereNotIn('id', $postedPayments)->where('amount', '>', 0)->orderBy('id');
        $zeroInvoices = Invoices::whereNotIn('id', $postedInvoices)->whereRaw('(COALESCE(total,0) + COALESCE(discount,0)) <= 0')->pluck('id');
        $zeroPayments = Payments::whereNotIn('id', $postedPayments)->where('amount', '<=', 0)->pluck('invoices_id');

        $this->info("Facturas sin asiento: {$invoices->count()}");
        $this->info("Cobros sin asiento: {$payments->count()}");
        if ($zeroInvoices->isNotEmpty()) {
            $this->line('Facturas en cero (no llevan asiento): ' . $zeroInvoices->implode(', '));
        }
        if ($zeroPayments->isNotEmpty()) {
            $this->line('Cobros en cero (no llevan asiento), de las facturas: ' . $zeroPayments->implode(', '));
        }
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $ok = 0;
        $errors = 0;
        foreach ($invoices->cursor() as $invoice) {
            try {
                $accounting->postInvoice($invoice) && $ok++;
            } catch (\Throwable $e) {
                $errors++;
                $this->warn("Factura {$invoice->id}: {$e->getMessage()}");
            }
        }
        foreach ($payments->cursor() as $payment) {
            try {
                $accounting->postPayment($payment) && $ok++;
            } catch (\Throwable $e) {
                $errors++;
                $this->warn("Cobro {$payment->id}: {$e->getMessage()}");
            }
        }

        $this->info("Asientos creados: {$ok}. Con error: {$errors}.");

        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
