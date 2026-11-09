<?php

namespace App\Http\Middleware;

/**
 * Quién puede usar Facturación (caja, turnos, registro de pacientes).
 *
 * Se incluye el rol «Caja» además de los permisos por si en la base de
 * producción ese rol no tiene todavía los permisos de billing.*.
 * SuperAdmin y Administrador pasan siempre (ver RequireAccess).
 */
final class BillingAccess
{
    public const RULES = ['billing.view', 'billing.create', 'cash.operate', 'role:Caja'];

    /** Parámetro para la ruta: RequireAccess::class . BillingAccess::MIDDLEWARE */
    public const MIDDLEWARE = ':billing.view,billing.create,cash.operate,role:Caja';
}
