<?php

namespace App\Services;

use App\Models\NcfSequences;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;

class NcfService
{
    public static function generate(string $type = 'B02'): string
    {
        return DB::transaction(function () use ($type) {

            $sequence = NcfSequences::where('type', $type)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
                throw new Exception("No hay secuencia activa para {$type}");
            }

            if (Carbon::now()->gt($sequence->expires_at)) {
                throw new Exception("La secuencia {$type} está vencida");
            }

            $next = $sequence->current_number
                ? $sequence->current_number + 1
                : $sequence->from_number;

            if ($next > $sequence->to_number) {
                throw new Exception("Secuencia {$type} agotada");
            }

            $sequence->current_number = $next;
            $sequence->save();

            return $sequence->prefix.str_pad($next, 8, '0', STR_PAD_LEFT);
        });
    }
}
