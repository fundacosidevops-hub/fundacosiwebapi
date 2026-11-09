<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rutas /v1/web/* que usa el chatbot del sitio web (servidor a servidor).
 *
 * Pasa solo si la petición trae una clave válida en la cabecera `X-Web-Key`
 * (claves en WEB_CHAT_KEYS del .env). La clave del kiosco NO sirve aquí, y
 * esta clave no abre ninguna ruta del kiosco ni del personal.
 */
class WebChatKey
{
    public const HEADER = 'X-Web-Key';

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::isValid($request)) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Clave del sitio web no válida.',
            ], 401);
        }

        return $next($request);
    }

    public static function isValid(Request $request): bool
    {
        $given = (string) $request->header(self::HEADER, '');
        if ($given === '') {
            return false;
        }

        $ips = (array) config('webchat.allowed_ips', []);
        if ($ips && ! in_array($request->ip(), $ips, true)) {
            return false;
        }

        foreach ((array) config('webchat.keys', []) as $key) {
            if (strlen($key) >= 24 && hash_equals($key, $given)) {
                return true;
            }
        }

        return false;
    }
}
