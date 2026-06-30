<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Enums;

/**
 * Capacidades que um banco pode (ou não) suportar. Use Banco::suporta()
 * para checar antes de chamar um gateway que possa não existir no driver.
 */
enum Recurso: string
{
    case Pix = 'pix';
    case Boleto = 'boleto';
    case Pagamento = 'pagamento';
    case Conciliacao = 'conciliacao';
}
