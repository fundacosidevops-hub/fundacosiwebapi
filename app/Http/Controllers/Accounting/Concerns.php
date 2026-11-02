<?php

namespace App\Http\Controllers\Accounting;

use App\Exceptions\AccountingException;
use App\Models\Accounting\Account;
use Illuminate\Support\Facades\DB;

/** Utilidades compartidas por los controladores de contabilidad. */
trait Concerns
{
    protected function authorizePermission(string $permission): void
    {
        abort_unless(
            auth()->user()?->can($permission),
            403,
            'No tienes permiso para esta sección de contabilidad.'
        );
    }

    /** Ejecuta una acción y convierte errores contables en respuestas 422. */
    protected function attempt(callable $fn)
    {
        try {
            return $fn();
        } catch (AccountingException $e) {
            return response()->json(['isSuccess' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Saldos por cuenta: débitos y créditos en un rango de fechas.
     * Devuelve [account_id => ['debit' => x, 'credit' => y]].
     */
    protected function movements(?string $from, ?string $to): array
    {
        $q = DB::table('acc_journal_lines as l')
            ->join('acc_journal_entries as e', 'e.id', '=', 'l.entry_id')
            ->selectRaw('l.account_id, SUM(l.debit) as debit, SUM(l.credit) as credit')
            ->groupBy('l.account_id');
        if ($from) {
            $q->where('e.date', '>=', $from);
        }
        if ($to) {
            $q->where('e.date', '<=', $to);
        }
        $out = [];
        foreach ($q->get() as $row) {
            $out[$row->account_id] = ['debit' => round((float) $row->debit, 2), 'credit' => round((float) $row->credit, 2)];
        }

        return $out;
    }

    /** Saldo con el signo normal del tipo de cuenta (activo/gasto: D-C; resto: C-D). */
    protected function signed(string $type, float $debit, float $credit): float
    {
        return round(in_array($type, ['asset', 'expense'], true) ? $debit - $credit : $credit - $debit, 2);
    }

    /**
     * Árbol del catálogo con montos sumados hacia las cuentas de grupo.
     *
     * @param  callable(Account):array  $valuesFor  valores de cada cuenta de detalle
     */
    protected function rollup(callable $valuesFor, array $keys): array
    {
        $accounts = Account::orderBy('code')->get();
        $rows = [];
        foreach ($accounts as $a) {
            $rows[$a->id] = [
                'id' => $a->id,
                'code' => $a->code,
                'name' => $a->name,
                'type' => $a->type,
                'nature' => $a->nature,
                'parent_id' => $a->parent_id,
                'is_postable' => $a->is_postable,
                'is_active' => $a->is_active,
                'level' => substr_count($a->code, '.'),
            ] + array_fill_keys($keys, 0.0);
            if ($a->is_postable) {
                foreach ($valuesFor($a) as $k => $v) {
                    $rows[$a->id][$k] = round($v, 2);
                }
            }
        }
        // Sumar de las hojas hacia arriba (código más largo primero).
        $byLength = $rows;
        uasort($byLength, fn ($x, $y) => strlen($y['code']) <=> strlen($x['code']));
        foreach ($byLength as $id => $row) {
            $parent = $rows[$id]['parent_id'];
            if ($parent && isset($rows[$parent])) {
                foreach ($keys as $k) {
                    $rows[$parent][$k] = round($rows[$parent][$k] + $rows[$id][$k], 2);
                }
            }
        }

        return array_values($rows);
    }
}
