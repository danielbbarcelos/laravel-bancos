<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Data\Pix;

use DanielBBarcelos\Bancos\Data\Shared\Valor;

/**
 * Devolução (estorno) de um Pix recebido. Usada tanto como entrada
 * (valor + id de controle) quanto como leitura da resposta do PSP.
 */
final readonly class Devolucao
{
    /**
     * @param  array<string, mixed>  $bruto
     */
    public function __construct(
        public Valor $valor,
        public ?string $id = null,        // idControleDevolucao (idempotência)
        public ?string $status = null,    // EM_PROCESSAMENTO|DEVOLVIDO|NAO_REALIZADO
        public ?string $rtrId = null,
        public array $bruto = [],
    ) {
    }
}
