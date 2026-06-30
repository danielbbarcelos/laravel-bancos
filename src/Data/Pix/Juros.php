<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Data\Pix;

/**
 * Juros de uma cobrança com vencimento. Modalidade BACEN: 1 = valor fixo,
 * 2 = percentual ao dia, 3 = ao mês, 4 = ao ano (e assim por diante).
 */
final readonly class Juros
{
    public function __construct(
        public int $modalidade,
        public string $valorPerc,
    ) {
    }
}
