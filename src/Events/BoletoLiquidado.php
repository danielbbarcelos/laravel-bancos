<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Events;

use DanielBBarcelos\Bancos\Data\Boleto\RecebimentoBoleto;

/**
 * Disparado ao processar uma notificação de webhook de boleto (liquidação ou
 * estorno). Cheque $recebimento->pago()/estornado() no listener para dar baixa,
 * conciliar ou reverter. Classe simples (sem Dispatchable) para não acoplar a
 * illuminate/foundation; é despachada via o helper event().
 */
class BoletoLiquidado
{
    public function __construct(
        public readonly string $banco,
        public readonly RecebimentoBoleto $recebimento,
    ) {
    }
}
