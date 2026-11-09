<?php

namespace App\Http\Controllers;

use App\Http\Middleware\BillingAccess;
use App\Http\Middleware\RequireAccess;
use App\Services\AccountingService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inicio: tablero con los números del día (o del período elegido).
 *
 * Lo puede ver cualquier usuario con sesión. Solo devuelve totales y
 * agrupaciones; no incluye nombres de pacientes ni datos clínicos.
 */
class DashboardController extends Controller
{
    private const PAID = 3;

    public function summary(Request $request)
    {
        $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
        ], [
            'from.date_format' => 'La fecha «desde» no es válida.',
            'to.date_format' => 'La fecha «hasta» no es válida.',
        ]);

        $from = Carbon::parse($request->input('from') ?: now()->toDateString())->startOfDay();
        $to = Carbon::parse($request->input('to') ?: $from->toDateString())->endOfDay();
        if ($to->lt($from)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }
        if ($from->diffInDays($to) > 366) {
            $from = $to->copy()->subDays(366)->startOfDay();
        }
        $days = (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;

        // Período anterior del mismo largo, para comparar.
        $prevTo = $from->copy()->subDay()->endOfDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1)->startOfDay();

        $inv = $this->invoices($from, $to);
        $prev = $this->invoices($prevFrom, $prevTo);
        $pay = $this->payments($from, $to);

        $names = fn (string $table, Collection $ids, string $col = 'description') => $ids->filter()->isEmpty()
            ? collect()
            : DB::table($table)->whereIn('id', $ids->filter()->unique()->values())->pluck($col, 'id');
        $people = fn (Collection $ids) => $ids->filter()->isEmpty()
            ? collect()
            : DB::table('users')->whereIn('id', $ids->filter()->unique()->values())->get(['id', 'name', 'last_name'])
                ->mapWithKeys(fn ($u) => [$u->id => trim($u->name . ' ' . $u->last_name)]);

        // Montos, cajas y ARS solo para quien trabaja con dinero (Facturación
        // o Contabilidad). El resto ve conteos de pacientes, turnos y consultas.
        $canSeeMoney = RequireAccess::allows($request->user(), array_merge(
            BillingAccess::RULES,
            ['accounting.view', 'accounting.manage']
        ));
        if (! $canSeeMoney) {
            $k = $this->kpis($inv, $prev, $pay);
            $countsOnly = fn (array $rows) => array_map(
                fn ($r) => ['name' => $r['name'], 'invoices' => $r['invoices'], 'gross' => 0],
                $rows
            );

            return response()->json([
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'days' => $days,
                'restricted' => true,
                'kpis' => [
                    'invoices' => $k['invoices'] ?? 0,
                    'invoices_prev' => $k['invoices_prev'] ?? 0,
                    'patients' => $k['patients'] ?? 0,
                ],
                'hours' => $this->hours($inv),
                'services' => $countsOnly($this->top($inv, 'catalog_services_id', $names('catalog_services', $inv->pluck('catalog_services_id')))),
                'doctors' => $countsOnly($this->top($inv, 'doctor_id', $people($inv->pluck('doctor_id')))),
                'queue' => $this->queue($from, $to),
                'clinical' => $this->clinical($from, $to),
            ]);
        }

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $days,
            'restricted' => false,
            'kpis' => $this->kpis($inv, $prev, $pay),
            'trend' => $this->trend($inv, $pay, $from, $to, $days),
            'hours' => $this->hours($inv),
            'methods' => $this->methods($pay),
            'insurances' => $this->insurances($inv, $names),
            'services' => $this->top($inv, 'catalog_services_id', $names('catalog_services', $inv->pluck('catalog_services_id'))),
            'doctors' => $this->top($inv, 'doctor_id', $people($inv->pluck('doctor_id'))),
            'cashiers' => $this->cashiers($inv, $pay, $people),
            'open_cash' => $this->openCash($people),
            'ars' => $this->ars($names),
            'queue' => $this->queue($from, $to),
            'clinical' => $this->clinical($from, $to),
            'latest' => $this->latest($inv, $names, $people),
        ]);
    }

    /* ------------------------------------------------------------------ */

    private function invoices(Carbon $from, Carbon $to): Collection
    {
        return DB::table('in_invoices')
            ->whereBetween('created_at', [$from, $to])
            ->get(['id', 'ncf_number', 'patient_id', 'doctor_id', 'insurance_id', 'catalog_services_id',
                'billing_type', 'status_id', 'subtotal', 'discount', 'total', 'created_by', 'created_at']);
    }

    private function payments(Carbon $from, Carbon $to): Collection
    {
        return DB::table('in_payments as p')
            ->leftJoin('in_payment_methods as m', 'm.id', '=', 'p.payment_method_id')
            ->whereBetween('p.paid_at', [$from, $to])
            ->get(['p.id', 'p.invoices_id', 'p.amount', 'p.paid_at', 'p.cash_session_id', 'm.name as method', 'm.code']);
    }

    private function kpis(Collection $inv, Collection $prev, Collection $pay): array
    {
        $sum = fn (Collection $c, string $k) => round((float) $c->sum(fn ($r) => (float) $r->$k), 2);
        $billed = $sum($inv, 'total');
        $pending = round((float) $inv->filter(fn ($r) => (int) $r->status_id !== self::PAID)->sum(fn ($r) => (float) $r->total), 2);

        return [
            'invoices' => $inv->count(),
            'invoices_prev' => $prev->count(),
            'gross' => $sum($inv, 'subtotal'),
            'gross_prev' => $sum($prev, 'subtotal'),
            'coverage' => $sum($inv, 'discount'),
            'patient_total' => $billed,
            'collected' => $sum($pay, 'amount'),
            'pending' => $pending,
            'patients' => $inv->pluck('patient_id')->unique()->count(),
            'avg_ticket' => $inv->count() ? round($billed / $inv->count(), 2) : 0,
            'insured_share' => $inv->count()
                ? round(100 * $inv->where('billing_type', 'insured')->count() / $inv->count())
                : 0,
        ];
    }

    /** Por día (o por mes si el período es largo). */
    private function trend(Collection $inv, Collection $pay, Carbon $from, Carbon $to, int $days): array
    {
        $monthly = $days > 62;
        $key = fn ($d) => Carbon::parse($d)->format($monthly ? 'Y-m' : 'Y-m-d');
        $gross = $inv->groupBy(fn ($r) => $key($r->created_at))->map(fn ($g) => round($g->sum(fn ($r) => (float) $r->subtotal), 2));
        $count = $inv->groupBy(fn ($r) => $key($r->created_at))->map->count();
        $paid = $pay->groupBy(fn ($r) => $key($r->paid_at))->map(fn ($g) => round($g->sum(fn ($r) => (float) $r->amount), 2));

        $labels = [];
        if ($monthly) {
            for ($m = $from->copy()->startOfMonth(); $m->lte($to); $m->addMonth()) {
                $labels[] = $m->format('Y-m');
            }
        } else {
            foreach (CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay()) as $d) {
                $labels[] = $d->format('Y-m-d');
            }
        }

        return [
            'unit' => $monthly ? 'month' : 'day',
            'points' => collect($labels)->map(fn ($l) => [
                'key' => $l,
                'gross' => $gross[$l] ?? 0,
                'collected' => $paid[$l] ?? 0,
                'invoices' => $count[$l] ?? 0,
            ])->values(),
        ];
    }

    private function hours(Collection $inv): array
    {
        $by = $inv->groupBy(fn ($r) => (int) Carbon::parse($r->created_at)->format('G'))->map->count();
        $out = [];
        for ($h = 6; $h <= 21; $h++) {
            $out[] = ['hour' => $h, 'invoices' => $by[$h] ?? 0];
        }
        // Facturas fuera de 6:00–21:59 se suman a los extremos para no perderlas.
        $early = $by->filter(fn ($v, $h) => $h < 6)->sum();
        $late = $by->filter(fn ($v, $h) => $h > 21)->sum();
        $out[0]['invoices'] += $early;
        $out[count($out) - 1]['invoices'] += $late;

        return $out;
    }

    private function methods(Collection $pay): array
    {
        return $pay->groupBy(fn ($r) => $r->method ?: 'Sin forma de pago')
            ->map(fn ($g, $name) => ['name' => $name, 'amount' => round($g->sum(fn ($r) => (float) $r->amount), 2), 'count' => $g->count()])
            ->sortByDesc('amount')->values()->all();
    }

    private function insurances(Collection $inv, \Closure $names): array
    {
        $insured = $inv->where('billing_type', 'insured')->filter(fn ($r) => $r->insurance_id);
        $n = $names('insurances', $insured->pluck('insurance_id'));

        return $insured->groupBy('insurance_id')
            ->map(fn ($g, $id) => [
                'name' => $n[$id] ?? 'ARS #' . $id,
                'coverage' => round($g->sum(fn ($r) => (float) $r->discount), 2),
                'invoices' => $g->count(),
            ])
            ->sortByDesc('coverage')->values()->all();
    }

    private function top(Collection $inv, string $col, Collection $names, int $limit = 6): array
    {
        return $inv->filter(fn ($r) => $r->$col)->groupBy($col)
            ->map(fn ($g, $id) => [
                'name' => $names[$id] ?? '#' . $id,
                'invoices' => $g->count(),
                'gross' => round($g->sum(fn ($r) => (float) $r->subtotal), 2),
            ])
            ->sortByDesc('invoices')->take($limit)->values()->all();
    }

    private function cashiers(Collection $inv, Collection $pay, \Closure $people): array
    {
        $sessionUser = collect();
        if (AccountingService::enabled() && $pay->pluck('cash_session_id')->filter()->isNotEmpty()) {
            $sessionUser = DB::table('acc_cash_sessions')->whereIn('id', $pay->pluck('cash_session_id')->filter()->unique())
                ->pluck('user_id', 'id');
        }
        $collected = $pay->groupBy(fn ($p) => $sessionUser[$p->cash_session_id] ?? 0)
            ->map(fn ($g) => round($g->sum(fn ($r) => (float) $r->amount), 2));
        $ids = $inv->pluck('created_by')->merge($collected->keys())->filter()->unique();
        $n = $people($ids);

        return $ids->map(fn ($id) => [
            'name' => $n[$id] ?? '#' . $id,
            'invoices' => $inv->where('created_by', $id)->count(),
            'billed' => round($inv->where('created_by', $id)->sum(fn ($r) => (float) $r->total), 2),
            'collected' => $collected[$id] ?? 0,
        ])->sortByDesc('billed')->values()->all();
    }

    private function openCash(\Closure $people): array
    {
        if (! AccountingService::enabled()) {
            return [];
        }
        $open = DB::table('acc_cash_sessions')->where('status', 'open')->orderBy('opened_at')->get(['id', 'user_id', 'opened_at', 'opening_amount']);
        if ($open->isEmpty()) {
            return [];
        }
        $n = $people($open->pluck('user_id'));
        $cash = DB::table('in_payments as p')->join('in_payment_methods as m', 'm.id', '=', 'p.payment_method_id')
            ->whereIn('p.cash_session_id', $open->pluck('id'))
            ->select('p.cash_session_id', 'm.code', DB::raw('SUM(p.amount) as amount'), DB::raw('COUNT(*) as c'))
            ->groupBy('p.cash_session_id', 'm.code')->get()->groupBy('cash_session_id');

        return $open->map(function ($s) use ($n, $cash) {
            $rows = $cash[$s->id] ?? collect();

            return [
                'name' => $n[$s->user_id] ?? '#' . $s->user_id,
                'opened_at' => $s->opened_at,
                'opening' => (float) $s->opening_amount,
                'cash' => round((float) $rows->where('code', 'cash')->sum('amount'), 2),
                'other' => round((float) $rows->where('code', '!=', 'cash')->sum('amount'), 2),
                'payments' => (int) $rows->sum('c'),
            ];
        })->values()->all();
    }

    /** Lo que deben las ARS (saldo de coberturas sin cobrar) y reclamaciones abiertas. */
    private function ars(\Closure $names): array
    {
        $hasClaims = Schema::hasTable('acc_ars_claim_items');
        $q = DB::table('in_invoices as f')
            ->where('f.billing_type', 'insured')->whereNotNull('f.insurance_id')->where('f.discount', '>', 0);
        if ($hasClaims) {
            $q->leftJoin('acc_ars_claim_items as ci', 'ci.invoice_id', '=', 'f.id');
            $rows = $q->get(['f.insurance_id', 'f.discount', 'ci.id as item_id', 'ci.amount_paid', 'ci.amount_glosa']);
        } else {
            $rows = $q->get(['f.insurance_id', 'f.discount']);
        }
        $n = $names('insurances', $rows->pluck('insurance_id'));
        $by = [];
        foreach ($rows as $r) {
            $pending = round((float) $r->discount - (float) ($r->amount_paid ?? 0) - (float) ($r->amount_glosa ?? 0), 2);
            if ($pending <= 0) {
                continue;
            }
            $k = $r->insurance_id;
            $by[$k] ??= ['name' => $n[$k] ?? 'ARS #' . $k, 'unclaimed' => 0, 'claimed' => 0, 'total' => 0];
            $by[$k][($r->item_id ?? null) ? 'claimed' : 'unclaimed'] += $pending;
            $by[$k]['total'] += $pending;
        }
        $receivable = collect($by)->map(fn ($r) => array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $r))
            ->sortByDesc('total')->values();

        $claims = [];
        if (Schema::hasTable('acc_ars_claims')) {
            $claims = DB::table('acc_ars_claims')->select('status', DB::raw('COUNT(*) as count'), DB::raw('COALESCE(SUM(total_claimed),0) as claimed'), DB::raw('COALESCE(SUM(total_paid),0) as paid'))
                ->groupBy('status')->get()->map(fn ($c) => [
                    'status' => $c->status,
                    'count' => (int) $c->count,
                    'claimed' => round((float) $c->claimed, 2),
                    'paid' => round((float) $c->paid, 2),
                ])->values()->all();
        }

        return [
            'receivable' => $receivable->all(),
            'total' => round($receivable->sum('total'), 2),
            'unclaimed' => round($receivable->sum('unclaimed'), 2),
            'claims' => $claims,
        ];
    }

    private function queue(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('queue_managers')->whereBetween('created_at', [$from, $to])->get(['status', 'created_at', 'updated_at']);
        $done = $rows->where('status', 'done');
        $waits = $done->map(fn ($r) => Carbon::parse($r->created_at)->diffInMinutes(Carbon::parse($r->updated_at)))
            ->filter(fn ($m) => $m >= 0 && $m < 600);

        return [
            'total' => $rows->count(),
            'waiting' => $rows->whereIn('status', ['pending', 'called'])->count(),
            'done' => $done->count(),
            'skipped' => $rows->where('status', 'skip')->count(),
            'avg_minutes' => $waits->count() ? (int) round($waits->avg()) : null,
        ];
    }

    private function clinical(Carbon $from, Carbon $to): ?array
    {
        if (! Schema::hasTable('cl_encounters')) {
            return null;
        }
        $rows = DB::table('cl_encounters')->whereBetween('created_at', [$from, $to])->get(['status']);

        return [
            'total' => $rows->count(),
            'triage' => $rows->where('status', 'triage')->count(),
            'in_progress' => $rows->where('status', 'in_progress')->count(),
            'signed' => $rows->where('status', 'signed')->count(),
        ];
    }

    /** Últimas facturas, sin el nombre del paciente. */
    private function latest(Collection $inv, \Closure $names, \Closure $people): array
    {
        $last = $inv->sortByDesc('created_at')->take(6)->values();
        $svc = $names('catalog_services', $last->pluck('catalog_services_id'));
        $ins = $names('insurances', $last->pluck('insurance_id'));
        $usr = $people($last->pluck('created_by'));

        return $last->map(fn ($r) => [
            'id' => $r->id,
            'ncf' => $r->ncf_number,
            'created_at' => $r->created_at,
            'service' => $svc[$r->catalog_services_id] ?? null,
            'insurance' => $r->billing_type === 'insured' ? ($ins[$r->insurance_id] ?? null) : 'Privado',
            'total' => (float) $r->total,
            'paid' => (int) $r->status_id === self::PAID,
            'cashier' => $usr[$r->created_by] ?? null,
        ])->all();
    }
}
