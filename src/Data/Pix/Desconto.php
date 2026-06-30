<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Data\Pix;

/**
 * Desconto de uma cobrança com vencimento. Modalidades 1 e 2 (valor fixo /
 * percentual até uma data) usam $datasFixas; modalidades 3–6 (por antecipação)
 * usam $valorPerc. Informe apenas o que a modalidade exige.
 */
final readonly class Desconto
{
    /**
     * @param  list<DescontoData>  $datasFixas
     */
    public function __construct(
        public int $modalidade,
        public array $datasFixas = [],
        public ?string $valorPerc = null,
    ) {
    }
}
