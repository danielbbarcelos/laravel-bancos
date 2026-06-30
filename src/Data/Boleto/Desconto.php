<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Data\Boleto;

use DanielBBarcelos\Bancos\Enums\TipoValor;

/**
 * Desconto de um boleto: o tipo (valor/percentual) e até 3 faixas por data
 * limite. O mapper expande as faixas para valorDesconto1..3 / dataDesconto1..3.
 */
final readonly class Desconto
{
    /**
     * @param  list<array{valor: float, data: string}>  $faixas  até 3 itens
     */
    public function __construct(
        public TipoValor $tipo,
        public array $faixas,
    ) {
    }
}
