<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Data\Boleto;

use DanielBBarcelos\Bancos\Data\Shared\Valor;

/**
 * Evento de movimentação de um boleto recebido por webhook (liquidação ou estorno).
 * O "movimento" do Sicredi indica o tipo: LIQUIDACAO_* (pago) ou ESTORNO_* (estornado).
 */
final readonly class RecebimentoBoleto
{
    /**
     * @param  array<string, mixed>  $bruto
     */
    public function __construct(
        public string $nossoNumero,
        public string $movimento,
        public ?Valor $valorPago = null,
        public ?Valor $valorJuros = null,
        public ?Valor $valorMulta = null,
        public ?Valor $valorDesconto = null,
        public ?string $beneficiario = null,
        public ?string $agencia = null,
        public ?string $posto = null,
        public array $bruto = [],
    ) {
    }

    public function pago(): bool
    {
        return str_starts_with($this->movimento, 'LIQUIDACAO');
    }

    public function estornado(): bool
    {
        return str_starts_with($this->movimento, 'ESTORNO');
    }
}
