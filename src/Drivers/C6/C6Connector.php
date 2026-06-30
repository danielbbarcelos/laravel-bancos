<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Drivers\C6;

use DanielBBarcelos\Bancos\Drivers\Bacen\ClienteHttpBacen;
use Illuminate\Support\Facades\Http;

/**
 * Camada HTTP do C6 Bank. Herda o fluxo BACEN (mTLS, cache de token, erros);
 * aqui fica só a emissão do token via OAuth2 client_credentials.
 *
 * ⚠️ CONFIRMAR NA DOC OFICIAL (portal developers.c6bank.com.br, requer cadastro):
 *   - endpoint do token  -> config 'token_url' (default abaixo)
 *   - formato: Basic auth vs. client_id/secret no body -> config 'token_via_basic'
 *   - scopes exigidos    -> config 'scopes'
 *   - base_url e versão das rotas (ver C6Banco)
 * Os valores padrão seguem o que é mais comum em PSPs BACEN; ajuste pela config.
 */
class C6Connector extends ClienteHttpBacen
{
    protected function emitirToken(): array
    {
        $tokenUrl = (string) ($this->config['token_url'] ?? '/v1/auth/oauth/token');
        $viaBasic = (bool) ($this->config['token_via_basic'] ?? true);

        $req = $this->aplicarMtls(
            Http::baseUrl($this->urlBase())
                ->timeout((int) ($this->config['timeout'] ?? 30))
                ->asForm()
        );

        $corpo = ['grant_type' => 'client_credentials'];

        if ($scopes = $this->config['scopes'] ?? null) {
            $corpo['scope'] = is_array($scopes) ? implode(' ', $scopes) : (string) $scopes;
        }

        if ($viaBasic) {
            $req = $req->withBasicAuth(
                (string) $this->config['client_id'],
                (string) $this->config['client_secret'],
            );
        } else {
            $corpo['client_id'] = (string) $this->config['client_id'];
            $corpo['client_secret'] = (string) $this->config['client_secret'];
        }

        $resposta = $req->post($tokenUrl, $corpo);

        $this->garantirOk($resposta);

        return $resposta->json();
    }
}
