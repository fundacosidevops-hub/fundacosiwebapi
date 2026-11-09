<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Si el usuario entró con una contraseña débil o por defecto, su token lleva
 * la marca «pwd_change». Mientras no la cambie solo puede ver su perfil,
 * cambiar la contraseña y cerrar sesión.
 */
class EnsurePasswordIsChanged
{
    /** Rutas permitidas (relativas a /api/v1). */
    private const ALLOWED = [
        'profile',
        'logout',
        'change-password',
        'users/me',
        'users/catalogs',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $mustChange = (bool) auth('api')->payload()->get('pwd_change');
        } catch (\Throwable) {
            $mustChange = false;
        }

        if (! $mustChange) {
            return $next($request);
        }

        $path = preg_replace('#^api/v1/#', '', $request->path());
        if (in_array($path, self::ALLOWED, true) && ($path !== 'users/me' || $request->isMethod('GET'))) {
            return $next($request);
        }

        return response()->json([
            'isSuccess' => false,
            'code' => 'password_change_required',
            'message' => 'Debes cambiar tu contraseña antes de continuar.',
        ], 403);
    }
}
