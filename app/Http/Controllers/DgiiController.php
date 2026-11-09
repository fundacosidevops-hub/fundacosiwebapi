<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequireAccess;
use App\Services\AuditLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Formato 607 — Envío de Datos de Ventas de Bienes y Servicios (DGII).
 *
 * Estructura tomada de la herramienta oficial de la DGII
 * («Herramienta de Envío Formato 607», NG 07-2018 y 05-2019):
 *   Encabezado: 607|RNC|AAAAMM|CantidadRegistros
 *   Detalle (23 campos separados por |):
 *    1 RNC/Cédula o Pasaporte   2 Tipo Identificación (1 RNC, 2 Cédula, 3 Pasaporte)
 *    3 NCF                      4 NCF Modificado
 *    5 Tipo de Ingreso (1 dígito: 1 = Ingresos por operaciones)
 *    6 Fecha Comprobante (AAAAMMDD)   7 Fecha de Retención
 *    8 Monto Facturado (sin ITBIS)    9 ITBIS Facturado
 *   10 ITBIS Retenido por Terceros   11 ITBIS Percibido
 *   12 Retención Renta por Terceros  13 ISR Percibido
 *   14 Impuesto Selectivo al Consumo 15 Otros Impuestos/Tasas
 *   16 Monto Propina Legal           17 Efectivo
 *   18 Cheque/Transferencia/Depósito 19 Tarjeta Débito/Crédito
 *   20 Venta a Crédito               21 Bonos o Certificados de Regalo
 *   22 Permuta                       23 Otras Formas de Ventas
 * Archivo: DGII_F_607_<RNC>_<AAAAMM>.txt, líneas CRLF, la última sin salto.
 *
 * Los servicios de salud se reportan con ITBIS 0. Las notas de crédito (B04)
 * van con montos positivos y sin formas de pago (la herramienta las resta).
 */
class DgiiController extends Controller
{
    public function preview607(Request $request)
    {
        $this->authorize607();

        return response()->json($this->build($request));
    }

    public function download607(Request $request)
    {
        $this->authorize607();
        $r = $this->build($request);

        $lines = [implode('|', ['607', $r['rnc'], $r['period'], count($r['rows'])])];
        foreach ($r['rows'] as $row) {
            $lines[] = implode('|', $row['fields']);
        }
        $content = implode("\r\n", $lines);
        $name = "DGII_F_607_{$r['rnc']}_{$r['period']}.txt";

        AuditLog::record('dgii.607', 'report', null, sprintf(
            'Generó el 607 de %s: %d registros, %s facturado (%d con observaciones)',
            $r['period'],
            count($r['rows']),
            number_format($r['totals']['amount'], 2),
            count($r['problems'])
        ));

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Access-Control-Expose-Headers' => 'Content-Disposition',
        ]);
    }

    /* ------------------------------------------------------------------ */

    private function build(Request $request): array
    {
        $v = $request->validate([
            'period' => ['required', 'regex:/^\d{4}(0[1-9]|1[0-2])$/'],
            'mode' => 'nullable|in:threshold,all',
            'min' => 'nullable|numeric|min:0',
        ], [
            'period.regex' => 'El período debe tener el formato AAAAMM.',
        ]);
        $period = $v['period'];
        $mode = $v['mode'] ?? 'threshold';
        $min = (float) ($v['min'] ?? config('dgii.consumo_min', 250000));
        $from = Carbon::createFromFormat('Ym-d', $period . '-01')->startOfDay();
        $to = $from->copy()->endOfMonth();

        $rnc = (string) config('dgii.rnc');
        $methods = DB::table('in_payment_methods')->pluck('code', 'id');

        $invoices = DB::table('in_invoices as i')
            ->leftJoin('users as u', 'u.id', '=', 'i.patient_id')
            ->leftJoin('document_types as dt', 'dt.id', '=', 'u.document_type_id')
            ->leftJoin('users as h', 'h.id', '=', 'u.dependent_of')
            ->leftJoin('document_types as hdt', 'hdt.id', '=', 'h.document_type_id')
            ->whereBetween('i.created_at', [$from, $to])
            ->whereNotNull('i.ncf_number')
            ->orderBy('i.created_at')->orderBy('i.id')
            ->get([
                'i.id', 'i.ncf_number', 'i.created_at', 'i.total', 'i.discount', 'i.patient_id',
                'u.name', 'u.last_name', 'u.document_number', 'dt.description as doc_type',
                'h.document_number as holder_document', 'hdt.description as holder_doc_type',
            ]);
        $payments = DB::table('in_payments')->whereIn('invoices_id', $invoices->pluck('id'))->get()->groupBy('invoices_id');

        $rows = [];
        $problems = [];
        $excluded = ['count' => 0, 'amount' => 0.0, 'cash' => 0.0, 'card' => 0.0, 'transfer' => 0.0, 'credit' => 0.0];
        $totals = ['invoices' => 0, 'credit_notes' => 0, 'amount' => 0.0];

        foreach ($invoices as $inv) {
            $amount = round((float) $inv->total + (float) $inv->discount, 2);
            $type = substr($inv->ncf_number, 1, 2);
            $forms = $this->forms($amount, $payments[$inv->id] ?? collect(), $methods);

            if ($mode === 'threshold' && $type === '02' && $amount < $min) {
                $excluded['count']++;
                $excluded['amount'] += $amount;
                foreach (['cash', 'card', 'transfer', 'credit'] as $k) {
                    $excluded[$k] += $forms[$k];
                }

                continue;
            }

            [$idNum, $idType, $idProblem] = $this->identification($inv);
            if ($idProblem) {
                $problems[] = ['ncf' => $inv->ncf_number, 'patient' => trim($inv->name . ' ' . $inv->last_name), 'message' => $idProblem];
            }

            $rows[] = [
                'kind' => 'invoice',
                'invoice_id' => $inv->id,
                'patient' => trim($inv->name . ' ' . $inv->last_name),
                'fields' => $this->fields($idNum, $idType, $inv->ncf_number, '', $inv->created_at, $amount, $forms),
            ];
            $totals['invoices']++;
            $totals['amount'] += $amount;
        }

        // Notas de crédito del período (aunque la factura sea de otro mes).
        if (Schema::hasTable('in_credit_notes')) {
            $notes = DB::table('in_credit_notes as n')
                ->join('in_invoices as i', 'i.id', '=', 'n.invoice_id')
                ->leftJoin('users as u', 'u.id', '=', 'i.patient_id')
                ->leftJoin('document_types as dt', 'dt.id', '=', 'u.document_type_id')
                ->leftJoin('users as h', 'h.id', '=', 'u.dependent_of')
                ->leftJoin('document_types as hdt', 'hdt.id', '=', 'h.document_type_id')
                ->whereBetween('n.created_at', [$from, $to])
                ->orderBy('n.created_at')
                ->get([
                    'n.id', 'n.ncf_number', 'n.created_at', 'n.amount', 'i.ncf_number as invoice_ncf',
                    'u.name', 'u.last_name', 'u.document_number', 'dt.description as doc_type',
                    'h.document_number as holder_document', 'hdt.description as holder_doc_type',
                ]);

            foreach ($notes as $n) {
                [$idNum, $idType, $idProblem] = $this->identification($n);
                $modType = substr((string) $n->invoice_ncf, 1, 2);
                // La DGII no exige identificación en notas de crédito a facturas
                // de consumo por debajo del mínimo.
                $idOptional = $modType === '02' && (float) $n->amount < $min;
                if ($idProblem && ! $idOptional) {
                    $problems[] = ['ncf' => $n->ncf_number, 'patient' => trim($n->name . ' ' . $n->last_name), 'message' => $idProblem];
                }
                if ($idProblem && $idOptional) {
                    [$idNum, $idType] = ['', ''];
                }

                $rows[] = [
                    'kind' => 'credit_note',
                    'invoice_id' => null,
                    'patient' => trim($n->name . ' ' . $n->last_name),
                    'fields' => $this->fields($idNum, $idType, $n->ncf_number, (string) $n->invoice_ncf, $n->created_at, round((float) $n->amount, 2), null),
                ];
                $totals['credit_notes']++;
                $totals['amount'] -= (float) $n->amount;
            }
        }

        foreach (['amount', 'cash', 'card', 'transfer', 'credit'] as $k) {
            $excluded[$k] = round($excluded[$k], 2);
        }
        $totals['amount'] = round($totals['amount'], 2);

        return [
            'rnc' => $rnc,
            'period' => $period,
            'mode' => $mode,
            'min' => $min,
            'columns' => self::COLUMNS,
            'rows' => $rows,
            'problems' => $problems,
            'excluded' => $excluded,
            'totals' => $totals,
            'file_name' => "DGII_F_607_{$rnc}_{$period}.txt",
        ];
    }

    public const COLUMNS = [
        'RNC/Cédula o Pasaporte', 'Tipo Identificación', 'NCF', 'NCF Modificado', 'Tipo de Ingreso',
        'Fecha Comprobante', 'Fecha de Retención', 'Monto Facturado', 'ITBIS Facturado',
        'ITBIS Retenido por Terceros', 'ITBIS Percibido', 'Retención Renta por Terceros', 'ISR Percibido',
        'Impuesto Selectivo al Consumo', 'Otros Impuestos/Tasas', 'Monto Propina Legal', 'Efectivo',
        'Cheque/Transferencia/Depósito', 'Tarjeta Débito/Crédito', 'Venta a Crédito',
        'Bonos o Certificados de Regalo', 'Permuta', 'Otras Formas de Ventas',
    ];

    /** Los 23 campos del detalle, en orden. $forms = null en notas de crédito. */
    private function fields(string $id, string $idType, string $ncf, string $ncfMod, $date, float $amount, ?array $forms): array
    {
        $opt = fn (float $x) => $x > 0 ? number_format($x, 2, '.', '') : '';

        return [
            $id,
            $idType,
            $ncf,
            $ncfMod,
            '1',                                   // Ingresos por operaciones (no financieros)
            Carbon::parse($date)->format('Ymd'),
            '',                                    // Fecha de retención
            number_format($amount, 2, '.', ''),
            '0.00',                                // Servicios de salud: exentos de ITBIS
            '', '', '', '', '', '', '',            // Retenciones, percibidos, selectivo, otros, propina
            $forms ? $opt($forms['cash']) : '',
            $forms ? $opt($forms['transfer']) : '',
            $forms ? $opt($forms['card']) : '',
            $forms ? $opt($forms['credit']) : '',
            '', '',                                // Bonos, permuta
            $forms ? $opt($forms['other']) : '',
        ];
    }

    /**
     * Formas de pago: lo cobrado según el método; la cobertura de la ARS y lo
     * no cobrado van como «Venta a crédito». Suman el monto facturado.
     */
    private function forms(float $amount, Collection $payments, Collection $methods): array
    {
        $f = ['cash' => 0.0, 'card' => 0.0, 'transfer' => 0.0, 'other' => 0.0, 'credit' => 0.0];
        foreach ($payments as $p) {
            $code = (string) ($methods[$p->payment_method_id] ?? '');
            $key = match (true) {
                $code === 'cash' => 'cash',
                str_contains($code, 'card') => 'card',
                in_array($code, ['transfer', 'check', 'deposit'], true) => 'transfer',
                default => 'other',
            };
            $f[$key] += (float) $p->amount;
        }
        $paid = $f['cash'] + $f['card'] + $f['transfer'] + $f['other'];
        $f['credit'] = max(0, $amount - $paid);
        foreach ($f as $k => $x) {
            $f[$k] = round($x, 2);
        }

        return $f;
    }

    /** [número, tipo, problema] — si el paciente no tiene documento se usa el del tutor. */
    private function identification(object $r): array
    {
        $doc = trim((string) $r->document_number);
        $docType = (string) $r->doc_type;
        if ($doc === '' && trim((string) ($r->holder_document ?? '')) !== '') {
            $doc = trim((string) $r->holder_document);
            $docType = (string) $r->holder_doc_type;
        }
        if ($doc === '') {
            return ['', '', 'El paciente no tiene cédula, RNC ni pasaporte (ni su tutor).'];
        }

        $isPassport = stripos($docType, 'pasaporte') !== false;
        if ($isPassport) {
            return [$doc, '3', null];
        }

        $digits = preg_replace('/\D/', '', $doc);
        if (strlen($digits) === 11) {
            return [$digits, '2', null];
        }
        if (strlen($digits) === 9) {
            return [$digits, '1', null];
        }

        return [$digits, '', "Documento «{$doc}» no es una cédula (11 dígitos) ni un RNC (9)."];
    }

    private function authorize607(): void
    {
        abort_unless(
            RequireAccess::allows(auth()->user(), ['accounting.view', 'accounting.manage']),
            403,
            'Solo administración y Contabilidad pueden generar el 607.'
        );
    }
}
