<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Data\Boleto;

use DanielBBarcelos\Bancos\Enums\TipoValor;

/** Juros ou multa de um boleto: valor fixo (R$) ou percentual, com data de início. */
final readonly class Encargo
{
    public function __construct(
        public TipoValor $tipo,
        public float $valor,
        public ?string $dataInicio = null, // YYYY-MM-DD
    ) {
    }
}
