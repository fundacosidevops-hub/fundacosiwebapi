<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corta la sesión de un usuario desactivado aunque su token siga vigente.
 * Se aplica a todas las rutas protegidas (después de auth:api).
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Solo bloquea si está explícitamente desactivado (is_active = 0).
        if ($user && $user->is_active === false) {
            try {
                auth('api')->invalidate();
            } catch (\Throwable) {
                // El token ya no es válido: no hay nada que invalidar.
            }

            return response()->json([
                'error' => 'Tu usuario está desactivado. Habla con un administrador.',
                'message' => 'Tu usuario está desactivado. Habla con un administrador.',
            ], 401);
        }

        return $next($request);
    }
}
