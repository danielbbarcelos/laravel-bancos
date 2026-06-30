<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Contracts;

use DanielBBarcelos\Bancos\Data\Boleto\Boleto;
use DanielBBarcelos\Bancos\Data\Boleto\BoletoEmitido;

/**
 * Contrato de boleto registrado (cobrança bancária). Mesma assinatura para
 * qualquer banco; o de-para para a API específica vive no driver.
 */
interface BoletoGateway
{
    /** Registra um boleto e devolve linha digitável, código de barras e nossoNumero. */
    public function emitir(Boleto $dados): BoletoEmitido;

    /** Consulta um boleto pelo nossoNumero. */
    public function consultar(string $nossoNumero): BoletoEmitido;

    /** Solicita a baixa/cancelamento de um boleto. */
    public function baixar(string $nossoNumero): void;
}
