<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\AccountMapping;
use App\Models\Accounting\CashSession;
use App\Models\Accounting\JournalEntry;
use App\Services\AccountingService;
use App\Services\AuditLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Contabilidad general: catálogo, asientos, períodos y estados financieros.
 * Ver: accounting.view. Modificar: accounting.manage.
 */
class AccountingController extends Controller
{
    use Concerns;

    // ------------------------------------------------------------------
    // Resumen
    // ------------------------------------------------------------------

    public function summary(Request $request)
    {
        $this->authorizePermission('accounting.view');
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->toDateString());
        $svc = app(AccountingService::class);

        $balances = $this->movements(null, $to);
        $balanceOf = function (array $codes) use ($balances) {
            $ids = Account::where(function ($q) use ($codes) {
                foreach ($codes as $c) {
                    $q->orWhere('code', $c)->orWhere('code', 'like', $c . '.%');
                }
            })->where('is_postable', true)->pluck('id');
            $sum = 0.0;
            foreach ($ids as $id) {
                $sum += ($balances[$id]['debit'] ?? 0) - ($balances[$id]['credit'] ?? 0);
            }

            return round($sum, 2);
        };
        $accountCode = fn (string $key) => Account::find($svc->mappedAccount($key))?->code;

        $period = $this->movements($from, $to);
        $income = 0.0;
        $expense = 0.0;
        foreach (Account::whereIn('type', ['income', 'expense'])->where('is_postable', true)->get() as $a) {
            $m = $period[$a->id] ?? ['debit' => 0, 'credit' => 0];
            if ($a->type === 'income') {
                $income += $m['credit'] - $m['debit'];
            } else {
                $expense += $m['debit'] - $m['credit'];
            }
        }

        $arsByInsurance = DB::table('acc_journal_lines as l')
            ->join('acc_journal_entries as e', 'e.id', '=', 'l.entry_id')
            ->leftJoin('insurances as i', 'i.id', '=', 'l.insurance_id')
            ->where('l.account_id', $svc->mappedAccount('ar.ars'))
            ->where('e.date', '<=', $to)
            ->groupBy('l.insurance_id', 'i.description')
            ->selectRaw('l.insurance_id, i.description, SUM(l.debit) - SUM(l.credit) as balance')
            ->get()
            ->filter(fn ($r) => abs((float) $r->balance) >= 0.01)
            ->sortByDesc('balance')
            ->values()
            ->map(fn ($r) => ['insurance_id' => $r->insurance_id, 'insurance' => $r->description ?? 'Sin ARS', 'balance' => round((float) $r->balance, 2)]);

        return response()->json([
            'from' => $from,
            'to' => $to,
            'cash' => $balanceOf(['1.1.01']),
            'banks' => $balanceOf(['1.1.02']),
            'cards' => $balanceOf(['1.1.03.03']),
            'ar_ars' => $balanceOf([$accountCode('ar.ars')]),
            'ar_patients' => $balanceOf([$accountCode('ar.patients')]),
            'income' => round($income, 2),
            'expense' => round($expense, 2),
            'result' => round($income - $expense, 2),
            'entries' => JournalEntry::whereBetween('date', [$from, $to])->count(),
            'open_cash_sessions' => CashSession::where('status', 'open')->with('user:id,name,last_name')->get(['id', 'user_id', 'opened_at', 'opening_amount']),
            'ars_balances' => $arsByInsurance,
        ]);
    }

    // ------------------------------------------------------------------
    // Catálogo de cuentas
    // ------------------------------------------------------------------

    public function accounts(Request $request)
    {
        $this->authorizePermission('accounting.view');
        $balances = $this->movements(null, $request->input('date'));
        $hasLines = DB::table('acc_journal_lines')->distinct()->pluck('account_id')->flip();

        $rows = $this->rollup(function (Account $a) use ($balances) {
            $m = $balances[$a->id] ?? ['debit' => 0, 'credit' => 0];

            return ['balance' => $this->signed($a->type, $m['debit'], $m['credit'])];
        }, ['balance']);

        foreach ($rows as &$row) {
            $row['has_movements'] = isset($hasLines[$row['id']]);
        }

        return response()->json($rows);
    }

    public function storeAccount(Request $request)
    {
        $this->authorizePermission('accounting.manage');
        $v = $request->validate([
            'code' => 'required|string|max:20|regex:/^[0-9]+(\.[0-9]+)*$/|unique:acc_accounts,code',
            'name' => 'required|string|max:150',
            'parent_id' => 'nullable|integer|exists:acc_accounts,id',
            'type' => ['nullable', Rule::in(Account::TYPES)],
            'nature' => ['nullable', Rule::in(['debit', 'credit'])],
            'is_postable' => 'boolean',
            'description' => 'nullable|string|max:255',
        ], ['code.regex' => 'El código usa números separados por puntos, por ejemplo 5.1.07.']);

        $parent = isset($v['parent_id']) ? Account::find($v['parent_id']) : null;
        if ($parent) {
            if (! str_starts_with($v['code'], $parent->code . '.')) {
                return $this->fail("El código debe empezar con {$parent->code}. (el código de la cuenta padre).");
            }
            if ($parent->is_postable) {
                if (DB::table('acc_journal_lines')->where('account_id', $parent->id)->exists()) {
                    return $this->fail("La cuenta {$parent->code} ya tiene movimientos; no puede tener subcuentas.");
                }
                $parent->update(['is_postable' => false]);
            }
        } elseif (! isset($v['type'])) {
            return $this->fail('Indica el tipo de la cuenta o elige una cuenta padre.');
        }

        $type = $parent?->type ?? $v['type'];
        $account = Account::create([
            'code' => $v['code'],
            'name' => $v['name'],
            'parent_id' => $parent?->id,
            'type' => $type,
            'nature' => $v['nature'] ?? (in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit'),
            'is_postable' => $v['is_postable'] ?? true,
            'is_active' => true,
            'description' => $v['description'] ?? null,
        ]);

        AuditLog::record('accounting.account_created', 'account', $account->id, "Creó la cuenta {$account->code} {$account->name}");

        return response()->json($account, 201);
    }

    public function updateAccount(Request $request, int $id)
    {
        $this->authorizePermission('accounting.manage');
        $account = Account::findOrFail($id);
        $v = $request->validate([
            'name' => 'sometimes|required|string|max:150',
            'description' => 'nullable|string|max:255',
            'is_active' => 'sometimes|boolean',
        ]);
        if (array_key_exists('is_active', $v) && ! $v['is_active']) {
            if (AccountMapping::where('account_id', $account->id)->exists()) {
                return $this->fail('Esta cuenta se usa en los asientos automáticos. Cámbiala en Configuración antes de desactivarla.');
            }
        }
        $account->update($v);

        AuditLog::record('accounting.account_updated', 'account', $account->id, "Editó la cuenta {$account->code} {$account->name}"
            . ($account->wasChanged('is_active') ? ($account->is_active ? ' (activada)' : ' (desactivada)') : ''));

        return response()->json($account);
    }

    // ------------------------------------------------------------------
    // Configuración: cuentas de los asientos automáticos y períodos
    // ------------------------------------------------------------------

    public function mappings()
    {
        $this->authorizePermission('accounting.view');

        return response()->json(AccountMapping::with('account:id,code,name')->orderBy('key')->get());
    }

    public function updateMappings(Request $request)
    {
        $this->authorizePermission('accounting.manage');
        $v = $request->validate([
            'mappings' => 'required|array|min:1',
            'mappings.*.key' => 'required|string|exists:acc_account_mappings,key',
            'mappings.*.account_id' => 'required|integer|exists:acc_accounts,id',
        ]);
        foreach ($v['mappings'] as $m) {
            $account = Account::find($m['account_id']);
            if (! $account->is_postable || ! $account->is_active) {
                return $this->fail("La cuenta {$account->code} debe ser de detalle y estar activa.");
            }
        }
        DB::transaction(function () use ($v) {
            foreach ($v['mappings'] as $m) {
                AccountMapping::where('key', $m['key'])->update(['account_id' => $m['account_id']]);
            }
        });

        AuditLog::record('accounting.mappings_updated', 'settings', null, 'Cambió las cuentas de los asientos automáticos', null, ['mappings' => $request->input('mappings')]);

        return $this->mappings();
    }

    public function periods(Request $request)
    {
        $this->authorizePermission('accounting.view');
        $year = (int) $request->input('year', now()->year);
        $saved = AccountingPeriod::where('year', $year)->get()->keyBy('month');
        $counts = JournalEntry::where('year', $year)
            ->selectRaw('date, count(*) as c')->groupBy('date')->get()
            ->groupBy(fn ($r) => (int) substr((string) $r->date, 5, 2))
            ->map(fn ($g) => $g->sum('c'));

        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $p = $saved->get($m);
            $out[] = [
                'year' => $year,
                'month' => $m,
                'status' => $p->status ?? 'open',
                'closed_at' => $p?->closed_at,
                'entries' => (int) ($counts[$m] ?? 0),
            ];
        }

        return response()->json($out);
    }

    public function setPeriodStatus(Request $request, int $year, int $month)
    {
        $this->authorizePermission('accounting.manage');
        $status = $request->validate(['status' => ['required', Rule::in(['open', 'closed'])]])['status'];
        abort_unless($month >= 1 && $month <= 12, 422, 'Mes inválido.');
        if ($status === 'closed' && CashSession::where('status', 'open')->whereYear('opened_at', $year)->whereMonth('opened_at', $month)->exists()) {
            return $this->fail('Hay cajas abiertas de ese mes. Ciérralas antes de cerrar el período.');
        }
        $period = AccountingPeriod::updateOrCreate(['year' => $year, 'month' => $month], [
            'status' => $status,
            'closed_at' => $status === 'closed' ? now() : null,
            'closed_by' => $status === 'closed' ? auth()->id() : null,
        ]);

        AuditLog::record('accounting.period_' . $status, 'period', null,
            sprintf('%s el período %02d/%d', $status === 'closed' ? 'Cerró' : 'Reabrió', $month, $year));

        return response()->json($period);
    }

    // ------------------------------------------------------------------
    // Asientos
    // ------------------------------------------------------------------

    public function entries(Request $request)
    {
        $this->authorizePermission('accounting.view');
        $q = JournalEntry::with(['lines.account:id,code,name', 'lines.insurance:id,description', 'creator:id,name,last_name'])
            ->orderByDesc('date')->orderByDesc('id');
        if ($request->filled('from')) {
            $q->where('date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $q->where('date', '<=', $request->input('to'));
        }
        if ($request->filled('source_type')) {
            $q->where('source_type', $request->input('source_type'));
        }
        if ($request->filled('account_id')) {
            $q->whereHas('lines', fn ($l) => $l->where('account_id', $request->input('account_id')));
        }
        if ($request->filled('search')) {
            $s = '%' . $request->input('search') . '%';
            $q->where(fn ($w) => $w->where('number', 'like', $s)->orWhere('description', 'like', $s));
        }

        return response()->json($q->paginate((int) $request->input('per_page', 25)));
    }

    public function showEntry(int $id)
    {
        $this->authorizePermission('accounting.view');

        return response()->json(
            JournalEntry::with(['lines.account:id,code,name', 'lines.insurance:id,description', 'creator:id,name,last_name', 'reversalOf:id,number', 'reversedBy:id,number,reversal_of_id'])
                ->findOrFail($id)
        );
    }

    public function storeEntry(Request $request)
    {
        $this->authorizePermission('accounting.manage');
        $v = $request->validate([
            'date' => 'required|date',
            'description' => 'required|string|max:255',
            'lines' => 'required|array|min:2',
            'lines.*.account_id' => 'required|integer|exists:acc_accounts,id',
            'lines.*.debit' => 'nullable|numeric|min:0',
            'lines.*.credit' => 'nullable|numeric|min:0',
            'lines.*.description' => 'nullable|string|max:255',
            'lines.*.insurance_id' => 'nullable|integer|exists:insurances,id',
        ]);

        return $this->attempt(function () use ($v) {
            $entry = app(AccountingService::class)->post([
                'date' => $v['date'],
                'description' => $v['description'],
                'source_type' => 'manual',
                'lines' => $v['lines'],
            ]);
            AuditLog::record('accounting.entry_created', 'journal_entry', $entry->id,
                sprintf('Registró el asiento manual %s por %s: %s', $entry->number, number_format((float) $entry->total, 2), $v['description']));

            return response()->json($entry->load('lines.account:id,code,name'), 201);
        });
    }

    public function reverseEntry(Request $request, int $id)
    {
        $this->authorizePermission('accounting.manage');
        $v = $request->validate(['date' => 'nullable|date', 'reason' => 'nullable|string|max:150']);
        $entry = JournalEntry::with('lines')->findOrFail($id);

        return $this->attempt(function () use ($entry, $v) {
            $reversal = app(AccountingService::class)->reverse($entry, $v['date'] ?? now()->toDateString(), $v['reason'] ?? null);
            AuditLog::record('accounting.entry_reversed', 'journal_entry', $entry->id,
                "Reversó el asiento {$entry->number} con el {$reversal->number}" . (! empty($v['reason']) ? ": {$v['reason']}" : ''));

            return response()->json($reversal);
        });
    }

    // ------------------------------------------------------------------
    // Reportes
    // ------------------------------------------------------------------

    /** Libro mayor de una cuenta, con saldo acumulado. */
    public function ledger(Request $request)
    {
        $this->authorizePermission('accounting.view');
        $v = $request->validate(['account_id' => 'required|integer|exists:acc_accounts,id', 'from' => 'required|date', 'to' => 'required|date']);
        $account = Account::findOrFail($v['account_id']);
        $ids = $this->descendantPostableIds($account);

        $openingRow = DB::table('acc_journal_lines as l')->join('acc_journal_entries as e', 'e.id', '=', 'l.entry_id')
            ->whereIn('l.account_id', $ids)->where('e.date', '<', $v['from'])
            ->selectRaw('COALESCE(SUM(l.debit),0) as d, COALESCE(SUM(l.credit),0) as c')->first();
        $opening = $this->signed($account->type, (float) $openingRow->d, (float) $openingRow->c);

        $rows = DB::table('acc_journal_lines as l')
            ->join('acc_journal_entries as e', 'e.id', '=', 'l.entry_id')
            ->join('acc_accounts as a', 'a.id', '=', 'l.account_id')
            ->leftJoin('insurances as i', 'i.id', '=', 'l.insurance_id')
            ->whereIn('l.account_id', $ids)
            ->whereBetween('e.date', [$v['from'], $v['to']])
            ->orderBy('e.date')->orderBy('e.id')->orderBy('l.id')
            ->get(['e.id as entry_id', 'e.number', 'e.date', 'e.description as entry_description', 'e.source_type',
                'l.description', 'l.debit', 'l.credit', 'a.code', 'a.name as account_name', 'i.description as insurance']);

        $running = $opening;
        $debit = 0.0;
        $credit = 0.0;
        $movements = $rows->map(function ($r) use (&$running, &$debit, &$credit, $account) {
            $d = (float) $r->debit;
            $c = (float) $r->credit;
            $debit += $d;
            $credit += $c;
            $running = round($running + $this->signed($account->type, $d, $c), 2);

            return (array) $r + ['balance' => $running];
        });

        return response()->json([
            'account' => $account,
            'from' => $v['from'],
            'to' => $v['to'],
            'opening' => $opening,
            'debit' => round($debit, 2),
            'credit' => round($credit, 2),
            'closing' => $running,
            'movements' => $movements,
        ]);
    }

    /** Balanza de comprobación. */
    public function trialBalance(Request $request)
    {
        $this->authorizePermission('accounting.view');
        $v = $request->validate(['from' => 'required|date', 'to' => 'required|date']);
        $before = $this->movements(null, Carbon::parse($v['from'])->subDay()->toDateString());
        $period = $this->movements($v['from'], $v['to']);

        $rows = $this->rollup(function (Account $a) use ($before, $period) {
            $b = $before[$a->id] ?? ['debit' => 0, 'credit' => 0];
            $p = $period[$a->id] ?? ['debit' => 0, 'credit' => 0];
            $opening = $this->signed($a->type, $b['debit'], $b['credit']);

            return [
                'opening' => $opening,
                'debit' => $p['debit'],
                'credit' => $p['credit'],
                'closing' => round($opening + $this->signed($a->type, $p['debit'], $p['credit']), 2),
            ];
        }, ['opening', 'debit', 'credit', 'closing']);

        $roots = array_filter($rows, fn ($r) => $r['parent_id'] === null);

        return response()->json([
            'from' => $v['from'],
            'to' => $v['to'],
            'rows' => $rows,
            'total_debit' => round(array_sum(array_column($roots, 'debit')), 2),
            'total_credit' => round(array_sum(array_column($roots, 'credit')), 2),
        ]);
    }

    /** Estado de resultados (ingresos − gastos) y desglose por servicio. */
    public function incomeStatement(Request $request)
    {
        $this->authorizePermission('accounting.view');
        $v = $request->validate(['from' => 'required|date', 'to' => 'required|date']);
        $period = $this->movements($v['from'], $v['to']);

        $rows = array_values(array_filter($this->rollup(function (Account $a) use ($period) {
            $p = $period[$a->id] ?? ['debit' => 0, 'credit' => 0];

            return ['amount' => $this->signed($a->type, $p['debit'], $p['credit'])];
        }, ['amount']), fn ($r) => in_array($r['type'], ['income', 'expense'], true)));

        $income = array_sum(array_column(array_filter($rows, fn ($r) => $r['type'] === 'income' && $r['parent_id'] === null), 'amount'));
        $expense = array_sum(array_column(array_filter($rows, fn ($r) => $r['type'] === 'expense' && $r['parent_id'] === null), 'amount'));

        $byService = DB::table('acc_journal_lines as l')
            ->join('acc_journal_entries as e', 'e.id', '=', 'l.entry_id')
            ->join('acc_accounts as a', 'a.id', '=', 'l.account_id')
            ->leftJoin('catalog_services as s', 's.id', '=', 'l.catalog_services_id')
            ->where('a.type', 'income')
            ->whereBetween('e.date', [$v['from'], $v['to']])
            ->whereNotNull('l.catalog_services_id')
            ->groupBy('l.catalog_services_id', 's.description')
            ->selectRaw('s.description as service, SUM(l.credit) - SUM(l.debit) as amount')
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($r) => ['service' => $r->service ?? 'Sin servicio', 'amount' => round((float) $r->amount, 2)]);

        return response()->json([
            'from' => $v['from'],
            'to' => $v['to'],
            'rows' => $rows,
            'income' => round($income, 2),
            'expense' => round($expense, 2),
            'result' => round($income - $expense, 2),
            'by_service' => $byService,
        ]);
    }

    /** Balance general a una fecha. */
    public function balanceSheet(Request $request)
    {
        $this->authorizePermission('accounting.view');
        $date = $request->validate(['date' => 'required|date'])['date'];
        $balances = $this->movements(null, $date);

        $rows = $this->rollup(function (Account $a) use ($balances) {
            $m = $balances[$a->id] ?? ['debit' => 0, 'credit' => 0];

            return ['balance' => $this->signed($a->type, $m['debit'], $m['credit'])];
        }, ['balance']);

        $total = fn (string $type) => round(array_sum(array_column(
            array_filter($rows, fn ($r) => $r['type'] === $type && $r['parent_id'] === null),
            'balance'
        )), 2);

        $result = round($total('income') - $total('expense'), 2);

        return response()->json([
            'date' => $date,
            'rows' => array_values(array_filter($rows, fn ($r) => in_array($r['type'], ['asset', 'liability', 'equity'], true))),
            'assets' => $total('asset'),
            'liabilities' => $total('liability'),
            'equity' => $total('equity'),
            'result' => $result,
            'liabilities_and_equity' => round($total('liability') + $total('equity') + $result, 2),
        ]);
    }

    private function descendantPostableIds(Account $account): array
    {
        if ($account->is_postable) {
            return [$account->id];
        }

        return Account::where('code', 'like', $account->code . '.%')->where('is_postable', true)->pluck('id')->all();
    }

    private function fail(string $message)
    {
        return response()->json(['isSuccess' => false, 'message' => $message], 422);
    }
}
