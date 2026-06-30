<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Events;

use DanielBBarcelos\Bancos\Data\Pix\Recebimento;

/**
 * Disparado uma vez para cada Pix recebido numa notificação de webhook. Registre
 * um listener no seu app para dar baixa no pedido, conciliar, notificar, etc.
 *
 * Classe simples (sem o trait Dispatchable) para não acoplar a illuminate/foundation;
 * é despachada pelo gateway via o helper event().
 */
class PixRecebido
{
    public function __construct(
        public readonly string $banco,        // conexão de origem (ex.: 'sicredi')
        public readonly Recebimento $pix,
    ) {
    }
}
