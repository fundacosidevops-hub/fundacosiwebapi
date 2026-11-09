<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rutas que usan el kiosco y la pantalla de turnos (sin login de paciente).
 *
 * Pasa si:
 *  1. La petición trae una clave de kiosco válida en la cabecera
 *     `X-Kiosk-Key` (claves en KIOSK_KEYS del .env, separadas por coma), o
 *  2. Trae un token JWT de un empleado activo. Con el parámetro `billing`
 *     además debe tener acceso a Facturación (para «Tomar turno» desde caja).
 *
 * Uso: KioskOrStaff::class            → cualquier empleado con sesión
 *      KioskOrStaff::class . ':billing' → empleado con acceso a Facturación
 */
class KioskOrStaff
{
    public const HEADER = 'X-Kiosk-Key';

    public function handle(Request $request, Closure $next, string $staffAccess = 'any'): Response
    {
        if (self::hasValidKioskKey($request)) {
            return $next($request);
        }

        $user = null;
        if ($request->bearerToken()) {
            try {
                $user = auth('api')->user();
            } catch (\Throwable) {
                $user = null;
            }
        }

        if ($user && $user->is_active !== false) {
            if ($staffAccess !== 'billing' || RequireAccess::allows($user, BillingAccess::RULES)) {
                return $next($request);
            }

            return response()->json([
                'isSuccess' => false,
                'message' => 'No tienes permiso para esta acción.',
            ], 403);
        }

        return response()->json([
            'isSuccess' => false,
            'message' => 'Kiosco no autorizado. Revisa la clave del kiosco (kiosk_key).',
        ], 401);
    }

    public static function hasValidKioskKey(Request $request): bool
    {
        $given = (string) $request->header(self::HEADER, '');
        if ($given === '') {
            return false;
        }

        $ips = (array) config('kiosk.allowed_ips', []);
        if ($ips && ! in_array($request->ip(), $ips, true)) {
            return false;
        }

        foreach ((array) config('kiosk.keys', []) as $key) {
            // Claves cortas no cuentan: evita que una clave vacía o trivial abra la puerta.
            if (strlen($key) >= 24 && hash_equals($key, $given)) {
                return true;
            }
        }

        return false;
    }
}
