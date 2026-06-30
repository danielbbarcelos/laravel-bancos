<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Enums;

/**
 * Status canônico de uma cobrança Pix. Os valores espelham o padrão BACEN,
 * que a maioria dos PSPs brasileiros adota; o mapper de cada driver é
 * responsável por traduzir qualquer divergência para um destes casos.
 */
enum StatusCobranca: string
{
    case Ativa = 'ATIVA';
    case Concluida = 'CONCLUIDA';
    case RemovidaPeloUsuario = 'REMOVIDA_PELO_USUARIO_RECEBEDOR';
    case RemovidaPeloPsp = 'REMOVIDA_PELO_PSP';

    public function paga(): bool
    {
        return $this === self::Concluida;
    }

    public function ativa(): bool
    {
        return $this === self::Ativa;
    }
}
