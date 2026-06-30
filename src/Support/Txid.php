<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Support;

use DanielBBarcelos\Bancos\Exceptions\BancoException;

/**
 * Regras de txid do padrão BACEN: 26 a 35 caracteres alfanuméricos
 * ([a-zA-Z0-9]). Validamos no app, antes de enviar, para falhar cedo com uma
 * mensagem clara em vez de receber um 400 genérico do PSP.
 */
final class Txid
{
    public static function valido(string $txid): bool
    {
        return preg_match('/^[a-zA-Z0-9]{26,35}$/', $txid) === 1;
    }

    public static function exigirValido(string $txid): void
    {
        if (! self::valido($txid)) {
            throw new BancoException(
                "txid inválido [\"{$txid}\"]: o padrão BACEN exige de 26 a 35 caracteres "
                .'alfanuméricos (a-z, A-Z, 0-9), sem hífens ou símbolos.'
            );
        }
    }
}
