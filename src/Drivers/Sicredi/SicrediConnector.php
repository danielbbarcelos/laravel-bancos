<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Drivers\Sicredi;

use DanielBBarcelos\Bancos\Drivers\Bacen\ClienteHttpBacen;
use Illuminate\Support\Facades\Http;

/**
 * Camada HTTP do Sicredi. Herda todo o fluxo BACEN (mTLS, cache de token,
 * erros) e só define como o token é emitido.
 *
 * Conforme o Guia Técnico Pix Sicredi (v1.9.5, p.11): POST /oauth/token com
 * Basic auth (client_id:client_secret), corpo application/x-www-form-urlencoded
 * contendo grant_type=client_credentials e os scopes — que SÃO obrigatórios
 * (sem eles o Sicredi retorna 400 "Escopo vazio").
 */
class SicrediConnector extends ClienteHttpBacen
{
    /** Scopes Pix padrão quando a config não especifica (inclui cob, cobv, pix e webhook). */
    protected const SCOPES_PADRAO = 'cob.read cob.write cobv.read cobv.write pix.read pix.write webhook.read webhook.write';

    protected function emitirToken(): array
    {
        $req = $this->aplicarMtls(
            Http::baseUrl($this->urlBase())
                ->timeout((int) ($this->config['timeout'] ?? 30))
                ->asForm()
                ->withBasicAuth(
                    (string) $this->config['client_id'],
                    (string) $this->config['client_secret'],
                )
        );

        $resposta = $req->post('/oauth/token', [
            'grant_type' => 'client_credentials',
            'scope' => $this->scopes(),
        ]);

        $this->garantirOk($resposta);

        return $resposta->json();
    }

    /** Scopes como string separada por espaço (form-urlencode converte para "+"). */
    protected function scopes(): string
    {
        $scopes = $this->config['scopes'] ?? null;

        if (is_array($scopes)) {
            return implode(' ', $scopes);
        }

        return $scopes !== null && $scopes !== ''
            ? (string) $scopes
            : self::SCOPES_PADRAO;
    }
}
