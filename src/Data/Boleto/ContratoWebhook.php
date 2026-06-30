<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Data\Boleto;

/** Contrato de webhook de boleto registrado no Sicredi (notificações de liquidação/estorno). */
final readonly class ContratoWebhook
{
    /**
     * @param  list<string>  $eventos
     * @param  array<string, mixed>  $bruto
     */
    public function __construct(
        public ?string $idContrato,
        public string $url,
        public array $eventos = [],
        public ?string $status = null,
        public array $bruto = [],
    ) {
    }
}
