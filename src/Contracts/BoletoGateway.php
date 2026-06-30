<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Contracts;

use DanielBBarcelos\Bancos\Data\Boleto\Boleto;
use DanielBBarcelos\Bancos\Data\Boleto\BoletoEmitido;
use DanielBBarcelos\Bancos\Data\Boleto\ContratoWebhook;
use DanielBBarcelos\Bancos\Data\Boleto\RecebimentoBoleto;

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

    /** Baixa o PDF do boleto (bytes) pela linha digitável. */
    public function pdf(string $linhaDigitavel): string;

    /** Solicita a baixa/cancelamento de um boleto. */
    public function baixar(string $nossoNumero): void;

    /**
     * Registra um contrato de webhook (URL de notificação de liquidação/estorno).
     *
     * @param  list<string>  $eventos
     */
    public function registrarWebhook(string $url, array $eventos = ['LIQUIDACAO']): ContratoWebhook;

    /** Consulta o contrato de webhook ativo; null se não houver. */
    public function consultarWebhook(): ?ContratoWebhook;

    /**
     * Altera o contrato de webhook existente.
     *
     * @param  list<string>  $eventos
     */
    public function alterarWebhook(string $idContrato, string $url, array $eventos = ['LIQUIDACAO']): ContratoWebhook;

    /**
     * Processa o payload de uma notificação de boleto: dispara Events\BoletoLiquidado
     * e devolve o DTO canônico.
     *
     * @param  array<string, mixed>  $payload
     */
    public function processarNotificacao(array $payload): RecebimentoBoleto;
}
