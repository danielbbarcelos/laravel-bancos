<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Exceptions;

use RuntimeException;

/** Exceção base do pacote. Capture esta para tratar qualquer falha de banco. */
class BancoException extends RuntimeException
{
}
