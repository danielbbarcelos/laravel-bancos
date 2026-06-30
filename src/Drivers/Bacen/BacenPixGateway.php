<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Drivers\Bacen;

use DanielBBarcelos\Bancos\Contracts\PixGateway;
use DanielBBarcelos\Bancos\Data\Pix\Cobranca;
use DanielBBarcelos\Bancos\Data\Pix\CobrancaComVencimento;
use DanielBBarcelos\Bancos\Data\Pix\CobrancaImediata;
use DanielBBarcelos\Bancos\Data\Pix\Devolucao;
use DanielBBarcelos\Bancos\Data\Pix\Recebimento;
use DanielBBarcelos\Bancos\Data\Pix\Webhook;
use DanielBBarcelos\Bancos\Drivers\Bacen\Mappers\CobrancaMapper;
use DanielBBarcelos\Bancos\Drivers\Bacen\Mappers\CobrancaVencimentoMapper;
use DanielBBarcelos\Bancos\Drivers\Bacen\Mappers\DevolucaoMapper;
use DanielBBarcelos\Bancos\Drivers\Bacen\Mappers\RecebimentoMapper;
use DanielBBarcelos\Bancos\Drivers\Bacen\Mappers\WebhookMapper;
use DanielBBarcelos\Bancos\Enums\StatusCobranca;
use DanielBBarcelos\Bancos\Events\PixRecebido;
use DanielBBarcelos\Bancos\Exceptions\BancoApiException;
use DanielBBarcelos\Bancos\Exceptions\BancoException;
use DanielBBarcelos\Bancos\Support\Txid;
use DanielBBarcelos\Bancos\Support\Url;

/**
 * Implementação de Pix para qualquer PSP que siga o padrão BACEN. O que varia
 * por banco — autenticação (via BacenConnector), nome e os prefixos/versões de
 * rota — entra pelo construtor. Sicredi e C6 reutilizam esta mesma classe.
 */
class BacenPixGateway implements PixGateway
{
    public function __construct(
        protected BacenConnector $http,
        protected CobrancaMapper $cobrancas,
        protected DevolucaoMapper $devolucoes,
        protected WebhookMapper $webhooks,
        protected CobrancaVencimentoMapper $cobrancasVencimento,
        protected RecebimentoMapper $recebimentos,
        protected string $banco = 'bacen',
        protected ?string $chavePadrao = null,
        protected string $rotaCob = '/api/v2/cob',
        protected string $rotaCobv = '/api/v2/cobv',
        protected string $rotaPix = '/api/v2/pix',
        protected string $rotaWebhook = '/api/v2/webhook',
        protected int $maxItensNotificacao = 1000,
    ) {
    }

    public function cobrancaImediata(CobrancaImediata $dados): Cobranca
    {
        $payload = $this->cobrancas->paraApi($dados, $this->chavePadrao);

        // Sem txid: o PSP gera (POST). Com txid: cria/atualiza idempotente (PUT).
        if ($dados->txid === null) {
            $resposta = $this->http->post($this->rotaCob, $payload);
        } else {
            Txid::exigirValido($dados->txid);
            $resposta = $this->http->put("{$this->rotaCob}/{$dados->txid}", $payload);
        }

        return $this->cobrancas->paraDominio($resposta->json());
    }

    public function consultarCobranca(string $txid): Cobranca
    {
        $resposta = $this->http->get("{$this->rotaCob}/{$txid}");

        return $this->cobrancas->paraDominio($resposta->json());
    }

    public function cancelarCobranca(string $txid): Cobranca
    {
        $resposta = $this->http->patch("{$this->rotaCob}/{$txid}", [
            'status' => StatusCobranca::RemovidaPeloUsuario->value,
        ]);

        return $this->cobrancas->paraDominio($resposta->json());
    }

    public function listarCobrancas(string $inicio, string $fim, array $filtros = []): array
    {
        $resposta = $this->http->get($this->rotaCob, array_merge(
            ['inicio' => $inicio, 'fim' => $fim],
            $filtros,
        ));

        return $this->cobrancas->listaParaDominio($resposta->json());
    }

    public function cobrancaComVencimento(CobrancaComVencimento $dados): Cobranca
    {
        Txid::exigirValido($dados->txid);

        // cobv sempre é criada/atualizada com o txid informado pelo recebedor (PUT).
        $resposta = $this->http->put(
            "{$this->rotaCobv}/{$dados->txid}",
            $this->cobrancasVencimento->paraApi($dados, $this->chavePadrao),
        );

        return $this->cobrancasVencimento->paraDominio($resposta->json());
    }

    public function consultarCobrancaVencimento(string $txid): Cobranca
    {
        $resposta = $this->http->get("{$this->rotaCobv}/{$txid}");

        return $this->cobrancasVencimento->paraDominio($resposta->json());
    }

    public function listarCobrancasVencimento(string $inicio, string $fim, array $filtros = []): array
    {
        $resposta = $this->http->get($this->rotaCobv, array_merge(
            ['inicio' => $inicio, 'fim' => $fim],
            $filtros,
        ));

        return $this->cobrancasVencimento->listaParaDominio($resposta->json());
    }

    public function revisarCobrancaVencimento(string $txid, array $alteracoes): Cobranca
    {
        $resposta = $this->http->patch("{$this->rotaCobv}/{$txid}", $alteracoes);

        return $this->cobrancasVencimento->paraDominio($resposta->json());
    }

    public function consultarPix(string $e2eid): Recebimento
    {
        $resposta = $this->http->get("{$this->rotaPix}/{$e2eid}");

        return $this->recebimentos->umParaDominio($resposta->json());
    }

    public function listarPixRecebidos(string $inicio, string $fim, array $filtros = []): array
    {
        $resposta = $this->http->get($this->rotaPix, array_merge(
            ['inicio' => $inicio, 'fim' => $fim],
            $filtros,
        ));

        return $this->recebimentos->listaParaDominio($resposta->json());
    }

    public function devolver(string $e2eid, string $idDevolucao, Devolucao $dados): Devolucao
    {
        $resposta = $this->http->put(
            "{$this->rotaPix}/{$e2eid}/devolucao/{$idDevolucao}",
            $this->devolucoes->paraApi($dados),
        );

        return $this->devolucoes->paraDominio($resposta->json());
    }

    public function consultarDevolucao(string $e2eid, string $idDevolucao): Devolucao
    {
        $resposta = $this->http->get("{$this->rotaPix}/{$e2eid}/devolucao/{$idDevolucao}");

        return $this->devolucoes->paraDominio($resposta->json());
    }

    public function configurarWebhook(string $url, ?string $chave = null): void
    {
        $chave = $this->exigirChave($chave);

        // Anti-SSRF/MITM: a URL é pública (o PSP a chama) e deve ser https.
        $url = Url::exigirHttps($url);

        $this->http->put("{$this->rotaWebhook}/{$chave}", ['webhookUrl' => $url]);
    }

    public function consultarWebhook(?string $chave = null): ?Webhook
    {
        $chave = $this->exigirChave($chave);

        try {
            $resposta = $this->http->get("{$this->rotaWebhook}/{$chave}");
        } catch (BancoApiException $e) {
            if ($e->statusHttp === 404) {
                return null; // sem webhook configurado para a chave
            }

            throw $e;
        }

        return $this->webhooks->webhookParaDominio($resposta->json());
    }

    public function cancelarWebhook(?string $chave = null): void
    {
        $chave = $this->exigirChave($chave);

        $this->http->delete("{$this->rotaWebhook}/{$chave}");
    }

    public function processarNotificacao(array $payload): array
    {
        // Defesa contra payload abusivo (DoS): limita o número de itens processados.
        $qtd = is_array($payload['pix'] ?? null) ? count($payload['pix']) : 0;

        if ($qtd > $this->maxItensNotificacao) {
            throw new BancoException(
                "Notificação com {$qtd} Pix excede o limite de {$this->maxItensNotificacao}."
            );
        }

        $recebidos = $this->recebimentos->listaParaDominio($payload);

        foreach ($recebidos as $pix) {
            event(new PixRecebido($this->banco, $pix));
        }

        return $recebidos;
    }

    protected function exigirChave(?string $chave): string
    {
        $chave ??= $this->chavePadrao;

        if ($chave === null || $chave === '') {
            throw new BancoException(
                'Chave Pix não informada e sem chave padrão (chave_pix) na configuração.'
            );
        }

        return $chave;
    }
}
