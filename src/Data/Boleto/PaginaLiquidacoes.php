<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Data\Boleto;

/**
 * Página da consulta de boletos liquidados por dia. A API pagina de 500 em 500
 * registros; $temProxima indica se há mais páginas a buscar (incrementar
 * $pagina). $bruto guarda a resposta original.
 */
final readonly class PaginaLiquidacoes
{
    /**
     * @param  list<Liquidacao>  $itens
     * @param  array<string, mixed>  $bruto
     */
    public function __construct(
        public array $itens,
        public bool $temProxima,
        public int $pagina,
        public array $bruto = [],
    ) {
    }
}
