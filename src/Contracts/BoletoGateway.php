<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Contracts;

use DanielBBarcelos\Bancos\Data\Boleto\Boleto;
use DanielBBarcelos\Bancos\Data\Boleto\BoletoEmitido;
use DanielBBarcelos\Bancos\Data\Boleto\ContratoWebhook;
use DanielBBarcelos\Bancos\Data\Boleto\InstrucaoBoleto;
use DanielBBarcelos\Bancos\Data\Boleto\PaginaLiquidacoes;
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

    /** Altera (prorroga) a data de vencimento de um boleto emitido. Vencimento em YYYY-MM-DD. */
    public function alterarVencimento(string $nossoNumero, string $vencimento): InstrucaoBoleto;

    /**
     * Altera o(s) valor(es) de desconto de um boleto emitido (até 3 faixas).
     *
     * @param  list<float>  $valores  valorDesconto1..3, ao menos um
     */
    public function alterarDesconto(string $nossoNumero, array $valores): InstrucaoBoleto;

    /**
     * Altera a(s) data(s) limite de desconto de um boleto emitido (até 3 faixas).
     *
     * @param  list<string>  $datas  data1..3 em YYYY-MM-DD, ao menos uma
     */
    public function alterarDataDesconto(string $nossoNumero, array $datas): InstrucaoBoleto;

    /** Altera o juros de um boleto emitido (valor ou percentual, conforme cadastro). */
    public function alterarJuros(string $nossoNumero, string $valorOuPercentual): InstrucaoBoleto;

    /** Altera o "seu número" (identificador do beneficiário) de um boleto emitido. */
    public function alterarSeuNumero(string $nossoNumero, string $seuNumero): InstrucaoBoleto;

    /**
     * Lista os boletos liquidados em um dia (paginado, 500/página).
     *
     * @param  string  $dia  data da liquidação em YYYY-MM-DD
     */
    public function listarLiquidados(string $dia, ?string $cpfCnpjBeneficiarioFinal = null, int $pagina = 1): PaginaLiquidacoes;

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
