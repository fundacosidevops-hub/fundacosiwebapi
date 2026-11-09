<?php

/*
|--------------------------------------------------------------------------
| Kiosco y pantalla de turnos
|--------------------------------------------------------------------------
|
| KIOSK_KEYS: claves de los kioscos, separadas por coma (mínimo 24
| caracteres cada una). Cada kiosco/pantalla la envía en la cabecera
| X-Kiosk-Key (se configura en su config-queue.json → "kiosk_key").
| Genera una con:  php -r "echo bin2hex(random_bytes(24));"
|
| Límites contra abuso (por IP, por minuto) y turnos por persona al día.
|
*/

return [
    'keys' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('KIOSK_KEYS', ''))
    ))),

    // Opcional: IPs públicas desde donde se aceptan las claves (la del
    // centro). Vacío = cualquier IP. Separadas por coma.
    'allowed_ips' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('KIOSK_ALLOWED_IPS', ''))
    ))),

    // Peticiones por minuto y por IP a las rutas del kiosco.
    'rate_per_minute' => (int) env('KIOSK_RATE_PER_MINUTE', 120),

    // Consultas de cédula por minuto y por IP (evita barridos de documentos).
    'lookup_per_minute' => (int) env('KIOSK_LOOKUP_PER_MINUTE', 20),

    // Turnos por documento al día (titular + dependientes).
    'max_tickets_per_document' => (int) env('KIOSK_MAX_TICKETS_PER_DOCUMENT', 6),
];
