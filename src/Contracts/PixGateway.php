<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Contracts;

use DanielBBarcelos\Bancos\Data\Pix\Cobranca;
use DanielBBarcelos\Bancos\Data\Pix\CobrancaComVencimento;
use DanielBBarcelos\Bancos\Data\Pix\CobrancaImediata;
use DanielBBarcelos\Bancos\Data\Pix\Devolucao;
use DanielBBarcelos\Bancos\Data\Pix\Recebimento;
use DanielBBarcelos\Bancos\Data\Pix\Webhook;

/**
 * Contrato de operações Pix. Mesma assinatura para todos os bancos — o
 * de-para para a API específica vive no driver que implementa esta interface.
 */
interface PixGateway
{
    /** Cria (POST) ou cria-com-txid (PUT) uma cobrança imediata. */
    public function cobrancaImediata(CobrancaImediata $dados): Cobranca;

    /** Consulta uma cobrança imediata pelo txid. */
    public function consultarCobranca(string $txid): Cobranca;

    /** Cria (ou atualiza) uma cobrança com vencimento (cobv) no txid informado. */
    public function cobrancaComVencimento(CobrancaComVencimento $dados): Cobranca;

    /** Consulta uma cobrança com vencimento pelo txid. */
    public function consultarCobrancaVencimento(string $txid): Cobranca;

    /**
     * Lista cobranças com vencimento num intervalo (timestamps ISO-8601).
     *
     * @param  array<string, scalar>  $filtros
     * @return list<Cobranca>
     */
    public function listarCobrancasVencimento(string $inicio, string $fim, array $filtros = []): array;

    /**
     * Revisa (PATCH) uma cobrança com vencimento. $alteracoes segue o formato
     * BACEN — ex.: ['valor' => ['original' => '120.00']] ou, para cancelar,
     * ['status' => 'REMOVIDA_PELO_USUARIO_RECEBEDOR'].
     *
     * @param  array<string, mixed>  $alteracoes
     */
    public function revisarCobrancaVencimento(string $txid, array $alteracoes): Cobranca;

    /** Cancela/remove uma cobrança imediata ativa (status REMOVIDA_PELO_USUARIO_RECEBEDOR). */
    public function cancelarCobranca(string $txid): Cobranca;

    /**
     * Lista cobranças imediatas num intervalo (timestamps ISO-8601). $filtros
     * aceita parâmetros extras do PSP (ex.: 'status', 'cpf', 'cnpj').
     *
     * @param  array<string, scalar>  $filtros
     * @return list<Cobranca>
     */
    public function listarCobrancas(string $inicio, string $fim, array $filtros = []): array;

    /** Consulta um Pix recebido pelo endToEndId. */
    public function consultarPix(string $e2eid): Recebimento;

    /**
     * Lista os Pix recebidos num intervalo (timestamps ISO-8601). $filtros
     * aceita parâmetros extras do PSP (ex.: 'cpf', 'cnpj', 'txid').
     *
     * @param  array<string, scalar>  $filtros
     * @return list<Recebimento>
     */
    public function listarPixRecebidos(string $inicio, string $fim, array $filtros = []): array;

    /** Solicita a devolução (total ou parcial) de um Pix recebido. */
    public function devolver(string $e2eid, string $idDevolucao, Devolucao $dados): Devolucao;

    /** Consulta o status de uma devolução já solicitada. */
    public function consultarDevolucao(string $e2eid, string $idDevolucao): Devolucao;

    /** Registra (ou atualiza) a URL de webhook para uma chave Pix. */
    public function configurarWebhook(string $url, ?string $chave = null): void;

    /** Consulta o webhook de uma chave Pix; null se não houver. */
    public function consultarWebhook(?string $chave = null): ?Webhook;

    /** Remove o webhook de uma chave Pix. */
    public function cancelarWebhook(?string $chave = null): void;

    /**
     * Processa o payload de uma notificação de webhook: dispara o evento
     * Events\PixRecebido para cada Pix e devolve a lista canônica.
     *
     * @param  array<string, mixed>  $payload
     * @return list<Recebimento>
     */
    public function processarNotificacao(array $payload): array;
}
