<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Data\Boleto;

use DanielBBarcelos\Bancos\Data\Shared\Valor;

/**
 * Um boleto liquidado, item da consulta de liquidados por dia. Traz os valores
 * decompostos da liquidação (nominal, líquido, juros, desconto, multa,
 * abatimento) e o meio de pagamento. $bruto guarda o item original do PSP.
 */
final readonly class Liquidacao
{
    /**
     * @param  array<string, mixed>  $bruto
     */
    public function __construct(
        public string $nossoNumero,
        public ?string $seuNumero = null,
        public ?string $dataPagamento = null,    // YYYY-MM-DD
        public ?Valor $valor = null,             // valor nominal
        public ?Valor $valorLiquidado = null,
        public ?Valor $juros = null,
        public ?Valor $desconto = null,
        public ?Valor $multa = null,
        public ?Valor $abatimento = null,
        public ?string $tipoLiquidacao = null,   // ex.: PIX, REDE, COMPE
        public ?string $tipoCarteira = null,
        public ?string $cooperativa = null,
        public array $bruto = [],
    ) {
    }
}
