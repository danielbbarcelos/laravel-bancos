<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Exceptions;

use DanielBBarcelos\Bancos\Enums\Recurso;

/** Lançada quando o driver do banco não implementa o recurso solicitado. */
class RecursoNaoSuportadoException extends BancoException
{
    public static function para(string $banco, Recurso $recurso): self
    {
        return new self("O banco [{$banco}] não suporta o recurso [{$recurso->value}].");
    }
}
