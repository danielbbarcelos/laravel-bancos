<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Data\Pix;

use DanielBBarcelos\Bancos\Data\Shared\Valor;

/**
 * Um Pix efetivamente recebido, canônico. Vem da notificação de webhook ou da
 * consulta de Pix recebidos. $txid é nulo quando o Pix não veio de uma cobrança.
 */
final readonly class Recebimento
{
    /**
     * @param  array<string, mixed>  $bruto
     */
    public function __construct(
        public string $endToEndId,
        public Valor $valor,
        public ?string $txid = null,
        public ?string $chave = null,
        public ?string $horario = null,         // ISO-8601 do recebimento
        public ?string $infoPagador = null,     // mensagem do pagador
        public ?string $pagadorNome = null,
        public ?string $pagadorDocumento = null,
        public array $bruto = [],
    ) {
    }
}
