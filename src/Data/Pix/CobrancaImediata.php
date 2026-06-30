<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Data\Pix;

use DanielBBarcelos\Bancos\Data\Shared\Pessoa;
use DanielBBarcelos\Bancos\Data\Shared\Valor;

/**
 * Requisição canônica de cobrança Pix imediata (cob). Independe do banco —
 * cada driver traduz isto para o payload da sua API.
 *
 * Se $txid for nulo, o driver usa POST (o PSP gera o txid); se preenchido,
 * usa PUT no txid informado (idempotente).
 */
final readonly class CobrancaImediata
{
    /**
     * @param  list<InfoAdicional>  $infoAdicionais
     */
    public function __construct(
        public Valor $valor,
        public ?string $chave = null,
        public int $expiracaoSegundos = 3600,
        public ?string $txid = null,
        public ?Pessoa $pagador = null,
        public ?string $solicitacaoPagador = null,
        public array $infoAdicionais = [],
    ) {
    }
}
