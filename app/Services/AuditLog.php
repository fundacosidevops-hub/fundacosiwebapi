<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de acciones sensibles (tabla sys_audit_logs).
 *
 * AuditLog::record('invoice.created', 'invoice', $id, 'Factura B02… creada', null, [...]);
 *
 * Nunca rompe la operación que registra: si la tabla no existe todavía (sin
 * migrar) o falla la escritura, solo deja un aviso en el log de Laravel.
 * Las contraseñas y tokens se quitan de los valores antes de guardarlos.
 */
final class AuditLog
{
    private static ?bool $enabled = null;

    private const SECRET_KEYS = ['password', 'password_confirmation', 'currentPassword', 'token', 'access_token', 'remember_token'];

    public static function enabled(): bool
    {
        if (self::$enabled === null) {
            try {
                self::$enabled = Schema::hasTable('sys_audit_logs');
            } catch (\Throwable) {
                self::$enabled = false;
            }
        }

        return self::$enabled;
    }

    /** Para las pruebas: vuelve a revisar si la tabla existe. */
    public static function reset(): void
    {
        self::$enabled = null;
    }

    public static function record(
        string $action,
        ?string $entityType,
        int|string|null $entityId,
        string $description,
        ?array $old = null,
        ?array $new = null,
        $user = null
    ): void {
        if (! self::enabled()) {
            return;
        }

        try {
            $user ??= auth('api')->user();
            $request = request();

            DB::table('sys_audit_logs')->insert([
                'user_id' => $user?->id,
                'user_name' => $user ? mb_substr(trim(($user->name ?? '') . ' ' . ($user->last_name ?? '')), 0, 120) : null,
                'action' => mb_substr($action, 0, 60),
                'entity_type' => $entityType ? mb_substr($entityType, 0, 40) : null,
                'entity_id' => is_numeric($entityId) ? (int) $entityId : null,
                'description' => mb_substr($description, 0, 500),
                'old_values' => $old ? json_encode(self::clean($old), JSON_UNESCAPED_UNICODE) : null,
                'new_values' => $new ? json_encode(self::clean($new), JSON_UNESCAPED_UNICODE) : null,
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('No se pudo registrar en la bitácora: ' . $e->getMessage(), ['action' => $action]);
        }
    }

    private static function clean(array $values): array
    {
        foreach ($values as $k => $v) {
            if (in_array($k, self::SECRET_KEYS, true)) {
                unset($values[$k]);
            } elseif (is_array($v)) {
                $values[$k] = self::clean($v);
            }
        }

        return $values;
    }
}
