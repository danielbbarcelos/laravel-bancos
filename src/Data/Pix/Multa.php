<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Data\Pix;

/**
 * Multa de uma cobrança com vencimento. Modalidade BACEN:
 * 1 = valor fixo (R$), 2 = percentual. $valorPerc é o valor ou o percentual.
 */
final readonly class Multa
{
    public function __construct(
        public int $modalidade,
        public string $valorPerc,
    ) {
    }
}
