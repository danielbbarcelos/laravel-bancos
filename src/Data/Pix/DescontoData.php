<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Data\Pix;

/** Um desconto até uma data fixa (item de descontoDataFixa do BACEN). */
final readonly class DescontoData
{
    public function __construct(
        public string $data,       // YYYY-MM-DD
        public string $valorPerc,  // valor fixo ou percentual, conforme a modalidade
    ) {
    }
}
