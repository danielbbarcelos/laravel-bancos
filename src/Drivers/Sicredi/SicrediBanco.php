<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Drivers\Sicredi;

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
use DanielBBarcelos\Bancos\Drivers\Sicredi\Boleto\BoletoMapper;
use DanielBBarcelos\Bancos\Drivers\Sicredi\Boleto\SicrediBoletoConnector;
use DanielBBarcelos\Bancos\Drivers\Sicredi\Boleto\SicrediBoletoGateway;
use DanielBBarcelos\Bancos\Enums\Recurso;
use DanielBBarcelos\Bancos\Exceptions\BancoException;
use DanielBBarcelos\Bancos\Support\Segredo;
use SensitiveParameter;

/**
 * Driver do Sicredi. Pix usa o gateway BACEN compartilhado (cob v3, pix/cobv v2);
 * boleto usa a API de Cobrança, com autenticação e rotas próprias.
 */
class SicrediBanco implements Banco
{
    use VerificaConexaoBacen;

    protected SicrediConnector $http;

    protected ?PixGateway $pix = null;

    protected ?BoletoGateway $boleto = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        #[SensitiveParameter]
        protected array $config,
        protected string $nome = 'sicredi',
    ) {
        $this->http = new SicrediConnector($config, $nome);
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
        return in_array($recurso, [Recurso::Pix, Recurso::Boleto], true);
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
            rotaCob: '/api/v3/cob',
            rotaCobv: '/api/v2/cobv',
            rotaPix: '/api/v2/pix',
            rotaWebhook: '/api/v2/webhook',
            maxItensNotificacao: (int) ($this->config['webhook_max_itens'] ?? 1000),
        );
    }

    public function boleto(): BoletoGateway
    {
        $config = $this->config['boleto'] ?? null;

        if (! is_array($config) || $config === []) {
            throw new BancoException(
                "Boleto do Sicredi não configurado: defina o bloco 'boleto' em "
                .'bancos.drivers.sicredi (x_api_key, username, password, cooperativa, posto, '
                .'codigo_beneficiario, base_url).'
            );
        }

        return $this->boleto ??= new SicrediBoletoGateway(
            new SicrediBoletoConnector($config + ['timeout' => $this->config['timeout'] ?? 30], $this->nome),
            new BoletoMapper(),
            (string) ($config['codigo_beneficiario'] ?? ''),
        );
    }
}

