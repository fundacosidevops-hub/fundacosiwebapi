<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige que el usuario tenga AL MENOS UNO de los accesos indicados.
 *
 * Uso en rutas:  RequireAccess::class . ':billing.view,billing.create,role:Caja'
 *  - "algo.algo"   → permiso de Spatie (guard api).
 *  - "role:Nombre" → rol de Spatie.
 *
 * SuperAdmin y Administrador pasan siempre (igual que el menú de la app,
 * que no filtra por permiso a esos dos roles).
 */
class RequireAccess
{
    public const ADMIN_ROLES = ['SuperAdmin', 'Administrador'];

    public function handle(Request $request, Closure $next, string ...$rules): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        if (self::allows($user, $rules)) {
            return $next($request);
        }

        return response()->json([
            'isSuccess' => false,
            'message' => 'No tienes permiso para esta acción.',
        ], 403);
    }

    /** ¿El usuario cumple alguna de las reglas (o es administrador)? */
    public static function allows($user, array $rules): bool
    {
        if ($user->hasAnyRole(self::ADMIN_ROLES)) {
            return true;
        }

        foreach ($rules as $rule) {
            $rule = trim($rule);
            if ($rule === '') {
                continue;
            }
            if (str_starts_with($rule, 'role:')) {
                if ($user->hasRole(substr($rule, 5))) {
                    return true;
                }
                continue;
            }
            try {
                if ($user->hasPermissionTo($rule, 'api')) {
                    return true;
                }
            } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
                // Permiso no creado en esta base: no concede acceso.
            }
        }

        return false;
    }
}
