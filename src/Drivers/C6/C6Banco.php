<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Drivers\C6;

use DanielBBarcelos\Bancos\Contracts\Banco;
use DanielBBarcelos\Bancos\Contracts\BoletoGateway;
use DanielBBarcelos\Bancos\Contracts\PixGateway;
use DanielBBarcelos\Bancos\Drivers\Bacen\BacenPixGateway;
use DanielBBarcelos\Bancos\Drivers\Bacen\Concerns\VerificaConexaoBacen;
use DanielBBarcelos\Bancos\Drivers\Bacen\Mappers\CobrancaMapper;
use DanielBBarcelos\Bancos\Drivers\Bacen\Mappers\CobrancaVencimentoMapper;
use DanielBBarcelos\Bancos\Drivers\Bacen\Mappers\DevolucaoMapper;
use DanielBBarcelos\Bancos\Drivers\Bacen\Mappers\RecebimentoMapper;
use DanielBBarcelos\Bancos\Drivers\Bacen\Mappers\WebhookMapper;
use DanielBBarcelos\Bancos\Enums\Recurso;
use DanielBBarcelos\Bancos\Exceptions\RecursoNaoSuportadoException;
use DanielBBarcelos\Bancos\Support\Segredo;
use SensitiveParameter;

/**
 * Driver do C6 Bank. Reutiliza o gateway Pix BACEN; só muda a autenticação
 * (C6Connector) e as rotas. As rotas podem ser sobrescritas pela config
 * ('rota_cob' / 'rota_pix') enquanto a versão oficial não é confirmada.
 *
 * ⚠️ CONFIRMAR rotas/versão na doc do portal C6 antes de usar em produção.
 */
class C6Banco implements Banco
{
    use VerificaConexaoBacen;

    protected C6Connector $http;

    protected ?PixGateway $pix = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        #[SensitiveParameter]
        protected array $config,
        protected string $nome = 'c6',
    ) {
        $this->http = new C6Connector($config, $nome);
    }

    public function __debugInfo(): array
    {
        return ['nome' => $this->nome, 'config' => Segredo::mascarar($this->config)];
    }

    public function nome(): string
    {
        return $this->nome;
    }

    public function suporta(Recurso $recurso): bool
    {
        return $recurso === Recurso::Pix;
    }

    public function pix(): PixGateway
    {
        return $this->pix ??= new BacenPixGateway(
            http: $this->http,
            cobrancas: new CobrancaMapper(),
            devolucoes: new DevolucaoMapper(),
            webhooks: new WebhookMapper(),
            cobrancasVencimento: new CobrancaVencimentoMapper(),
            recebimentos: new RecebimentoMapper(),
            banco: $this->nome,
            chavePadrao: $this->config['chave_pix'] ?? null,
            rotaCob: (string) ($this->config['rota_cob'] ?? '/v2/cob'),
            rotaCobv: (string) ($this->config['rota_cobv'] ?? '/v2/cobv'),
            rotaPix: (string) ($this->config['rota_pix'] ?? '/v2/pix'),
            rotaWebhook: (string) ($this->config['rota_webhook'] ?? '/v2/webhook'),
            maxItensNotificacao: (int) ($this->config['webhook_max_itens'] ?? 1000),
        );
    }

    public function boleto(): BoletoGateway
    {
        throw RecursoNaoSuportadoException::para($this->nome, Recurso::Boleto);
    }
}
