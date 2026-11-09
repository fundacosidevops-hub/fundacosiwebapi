<?php

/*
|--------------------------------------------------------------------------
| Chatbot del sitio web (fundacosixxi.com)
|--------------------------------------------------------------------------
|
| WEB_CHAT_KEYS: claves del sitio web, separadas por coma (mínimo 24
| caracteres). El servidor del sitio la envía en la cabecera X-Web-Key.
| Es DISTINTA de la clave del kiosco y solo abre las rutas /v1/web/*:
| lectura de servicios, médicos y seguros, y creación de solicitudes de
| cita. No permite consultar pacientes ni crear turnos.
| Genera una con:  php -r "echo bin2hex(random_bytes(24));"
|
*/

return [
    'keys' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('WEB_CHAT_KEYS', ''))
    ))),

    // Opcional: IPs del servidor del sitio web. Vacío = cualquier IP.
    'allowed_ips' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('WEB_CHAT_ALLOWED_IPS', ''))
    ))),

    // Peticiones por minuto (por IP) a las rutas del chatbot.
    'rate_per_minute' => (int) env('WEB_CHAT_RATE_PER_MINUTE', 60),

    // Solicitudes de cita por número de teléfono al día.
    'max_requests_per_phone' => (int) env('WEB_CHAT_MAX_REQUESTS_PER_PHONE', 3),

    // Servicios del catálogo que NO se muestran al público (cargos internos).
    'hidden_services' => [
        'CAFETERIA', 'COPIA', 'EQUIPOS', 'ERROR ELIMINAR', 'GASTOS CLINICOS',
        'HONORARIOS MÉDICOS', 'MATERIAL GASTABLE', 'MEDICAMENTOS', 'MISCELANEO',
    ],
];
