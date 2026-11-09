<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Reglas de contraseña del sistema.
 *
 * - Mínimo 10 caracteres, con al menos una letra y un número.
 * - No puede ser una de las contraseñas por defecto que existieron en el código.
 *
 * Al iniciar sesión con una contraseña que no cumple, el token sale marcado
 * («pwd_change») y la API solo deja cambiar la contraseña (ver
 * EnsurePasswordIsChanged).
 */
final class PasswordPolicy
{
    public const MIN = 10;

    /** Contraseñas por defecto conocidas (en minúscula). */
    private const KNOWN_DEFAULTS = [
        'temppass123',
        'password',
        'password123',
        '1234567890',
        'fundacosi',
        'fundacosi123',
        'admin12345',
    ];

    public const MESSAGE = 'La contraseña debe tener al menos 10 caracteres, con letras y números, y no puede ser una contraseña por defecto.';

    public static function isWeak(?string $password): bool
    {
        $password = (string) $password;

        return mb_strlen($password) < self::MIN
            || ! preg_match('/[A-Za-z]/', $password)
            || ! preg_match('/\d/', $password)
            || in_array(Str::lower($password), self::KNOWN_DEFAULTS, true);
    }

    /** Reglas de validación de Laravel para una contraseña nueva. */
    public static function rules(bool $confirmed = true): array
    {
        $rules = [
            'required',
            'string',
            'min:' . self::MIN,
            'max:100',
            function (string $attribute, mixed $value, \Closure $fail) {
                if (self::isWeak((string) $value)) {
                    $fail(self::MESSAGE);
                }
            },
        ];
        if ($confirmed) {
            $rules[] = 'confirmed';
        }

        return $rules;
    }

    /** Contraseña aleatoria para cuentas que no inician sesión (pacientes). */
    public static function random(): string
    {
        return Str::password(32);
    }
}
