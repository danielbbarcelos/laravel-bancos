<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Drivers\Sicredi\Boleto;

use DanielBBarcelos\Bancos\Drivers\Bacen\ClienteHttpBacen;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Camada HTTP da API de Cobrança (boleto) do Sicredi. Diferente do Pix: OAuth2
 * grant_type=password (username = beneficiário+cooperativa, password = código
 * de acesso), headers x-api-key/cooperativa/posto e SEM mTLS. Reaproveita do
 * núcleo BACEN apenas o cache de token, o retry e o tratamento de erro.
 */
class SicrediBoletoConnector extends ClienteHttpBacen
{
    protected function emitirToken(): array
    {
        $resposta = Http::baseUrl($this->urlBase())
            ->timeout((int) ($this->config['timeout'] ?? 30))
            ->asForm()
            ->withHeaders([
                'x-api-key' => (string) $this->config['x_api_key'],
                'context' => 'COBRANCA',
            ])
            ->post('/auth/openapi/token', [
                'grant_type' => 'password',
                'username' => (string) $this->config['username'],
                'password' => (string) $this->config['password'],
                'scope' => 'cobranca',
            ]);

        $this->garantirOk($resposta);

        return $resposta->json();
    }

    /** Acrescenta os headers fixos da API de Cobrança a toda requisição autenticada. */
    protected function cliente(): PendingRequest
    {
        return parent::cliente()->withHeaders(array_filter([
            'x-api-key' => $this->config['x_api_key'] ?? null,
            'cooperativa' => $this->config['cooperativa'] ?? null,
            'posto' => $this->config['posto'] ?? null,
            'codigoBeneficiario' => $this->config['codigo_beneficiario'] ?? null,
        ]));
    }
}
