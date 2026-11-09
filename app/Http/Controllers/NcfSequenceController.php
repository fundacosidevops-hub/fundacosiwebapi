<?php

namespace App\Http\Controllers;

use App\Models\NcfSequences;
use App\Services\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Finanza → Comprobantes NCF (administración y Contabilidad).
 * Registra los rangos de NCF que autoriza la DGII. NcfService toma la
 * secuencia activa de cada tipo, así que solo puede haber una activa por tipo.
 */
class NcfSequenceController extends Controller
{
    public const TYPES = [
        'B01' => 'Crédito fiscal',
        'B02' => 'Consumo',
        'B03' => 'Nota de débito',
        'B04' => 'Nota de crédito',
        'B14' => 'Regímenes especiales',
        'B15' => 'Gubernamental',
    ];

    public function index()
    {
        return response()->json([
            'types' => collect(self::TYPES)->map(fn ($label, $code) => ['code' => $code, 'label' => $label])->values(),
            'data' => NcfSequences::orderBy('type')->orderByDesc('is_active')->orderByDesc('id')->get()
                ->map(fn ($s) => $this->row($s)),
        ]);
    }

    public function store(Request $request)
    {
        $v = $request->validate([
            'type' => ['required', Rule::in(array_keys(self::TYPES))],
            'from' => 'required|integer|min:1|max:99999999',
            'to' => 'required|integer|min:1|max:99999999|gte:from',
            'expires_at' => 'required|date_format:Y-m-d|after:today',
            'activate' => 'boolean',
        ], [
            'type.in' => 'Tipo de comprobante no válido.',
            'to.gte' => 'El número final debe ser mayor o igual que el inicial.',
            'expires_at.after' => 'La fecha de vencimiento debe ser futura.',
            'expires_at.date_format' => 'La fecha de vencimiento no es válida.',
        ]);

        $overlap = NcfSequences::where('type', $v['type'])
            ->where('from_number', '<=', $v['to'])
            ->where('to_number', '>=', $v['from'])
            ->first();
        if ($overlap) {
            return $this->fail(sprintf(
                'Ese rango se cruza con otro %s ya registrado (%s a %s).',
                $v['type'],
                $this->ncf($v['type'], $overlap->from_number),
                $this->ncf($v['type'], $overlap->to_number)
            ));
        }

        $seq = DB::transaction(function () use ($v) {
            $activate = (bool) ($v['activate'] ?? true);
            if ($activate) {
                NcfSequences::where('type', $v['type'])->where('is_active', true)->update(['is_active' => false]);
            }

            return NcfSequences::create([
                'type' => $v['type'],
                'prefix' => $v['type'],
                'from_number' => $v['from'],
                'to_number' => $v['to'],
                'current_number' => null,
                'expires_at' => $v['expires_at'],
                'is_active' => $activate,
            ]);
        });

        AuditLog::record('ncf.created', 'ncf_sequence', $seq->id, sprintf(
            'Registró la secuencia %s del %s al %s (vence %s)%s',
            $seq->type,
            $this->ncf($seq->type, $seq->from_number),
            $this->ncf($seq->type, $seq->to_number),
            $v['expires_at'],
            $seq->is_active ? ' y la activó' : ''
        ), null, $v);

        return response()->json([
            'isSuccess' => true,
            'message' => "Secuencia {$seq->type} registrada.",
            'data' => $this->row($seq),
        ], 201);
    }

    public function setActive(Request $request, int $id)
    {
        $active = (bool) $request->validate(['active' => 'required|boolean'])['active'];
        $seq = NcfSequences::find($id);
        if (! $seq) {
            return $this->fail('La secuencia no existe.', 404);
        }

        if ($active) {
            $row = $this->row($seq);
            if ($row['state'] === 'expired') {
                return $this->fail('No se puede activar: la secuencia está vencida.');
            }
            if ($row['state'] === 'exhausted') {
                return $this->fail('No se puede activar: la secuencia está agotada.');
            }
        }

        DB::transaction(function () use ($seq, $active) {
            if ($active) {
                NcfSequences::where('type', $seq->type)->where('id', '!=', $seq->id)->update(['is_active' => false]);
            }
            $seq->update(['is_active' => $active]);
        });

        AuditLog::record(
            $active ? 'ncf.activated' : 'ncf.deactivated',
            'ncf_sequence',
            $seq->id,
            ($active ? 'Activó' : 'Desactivó') . " la secuencia {$seq->type} #{$seq->id}"
        );

        return response()->json(['isSuccess' => true, 'message' => $active ? 'Secuencia activada.' : 'Secuencia desactivada.']);
    }

    private function row(NcfSequences $s): array
    {
        $next = $s->current_number ? (int) $s->current_number + 1 : (int) $s->from_number;
        $remaining = max(0, (int) $s->to_number - $next + 1);
        $expired = $s->expires_at && now()->startOfDay()->gt($s->expires_at);
        $state = $expired ? 'expired' : ($remaining === 0 ? 'exhausted' : ($s->is_active ? 'active' : 'inactive'));

        return [
            'id' => $s->id,
            'type' => $s->type,
            'label' => self::TYPES[$s->type] ?? $s->type,
            'from' => $this->ncf($s->prefix ?: $s->type, $s->from_number),
            'to' => $this->ncf($s->prefix ?: $s->type, $s->to_number),
            'last' => $s->current_number ? $this->ncf($s->prefix ?: $s->type, $s->current_number) : null,
            'next' => $remaining ? $this->ncf($s->prefix ?: $s->type, $next) : null,
            'used' => $s->current_number ? (int) $s->current_number - (int) $s->from_number + 1 : 0,
            'remaining' => $remaining,
            'expires_at' => $s->expires_at ? substr((string) $s->expires_at, 0, 10) : null,
            'is_active' => (bool) $s->is_active,
            'state' => $state,
        ];
    }

    private function ncf(string $prefix, int|string $n): string
    {
        return $prefix . str_pad((string) $n, 8, '0', STR_PAD_LEFT);
    }

    private function fail(string $message, int $status = 422)
    {
        return response()->json(['isSuccess' => false, 'message' => $message], $status);
    }
}
