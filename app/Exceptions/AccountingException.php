<?php

namespace App\Exceptions;

use RuntimeException;

/** Error de negocio contable (período cerrado, asiento descuadrado, etc.). */
class AccountingException extends RuntimeException
{
}
