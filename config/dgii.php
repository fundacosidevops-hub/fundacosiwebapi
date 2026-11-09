<?php

/*
|--------------------------------------------------------------------------
| DGII (República Dominicana)
|--------------------------------------------------------------------------
|
| DGII_RNC: RNC de la empresa que reporta (encabezado del 607).
| DGII_607_CONSUMO_MIN: monto desde el que las facturas de consumo (B02) se
| reportan una por una en el 607; las menores se muestran como resumen.
| Confírmalo con tu contador; en la pantalla del 607 se puede cambiar o
| incluir todas.
|
*/

return [
    'rnc' => preg_replace('/\D/', '', (string) env('DGII_RNC', '430128602')),
    'consumo_min' => (float) env('DGII_607_CONSUMO_MIN', 250000),
];
