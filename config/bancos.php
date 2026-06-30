<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Banco padrão
    |--------------------------------------------------------------------------
    |
    | Driver usado quando você chama a fachada sem especificar o banco:
    | Bancos::pix()->cobrancaImediata(...). Deve existir em "drivers" abaixo.
    |
    */

    'default' => env('BANCO_DRIVER', 'sicredi'),

    /*
    |--------------------------------------------------------------------------
    | Drivers (bancos) configurados
    |--------------------------------------------------------------------------
    |
    | Cada entrada aponta para um driver registrado no BancoManager. A chave
    | "driver" determina a implementação; o restante é específico do banco.
    | Todos os bancos brasileiros de Pix exigem certificado mTLS + OAuth2.
    |
    | DOIS MODOS DE USO:
    |
    |  • Single-tenant: preencha as credenciais via env() abaixo e use
    |    Bancos::driver('sicredi') / Bancos::pix().
    |
    |  • SaaS / multi-tenant: deixe client_id/client_secret/chave_pix/certificado
    |    como null aqui (mantendo só os defaults compartilhados base_url/scopes/
    |    timeout) e passe as credenciais do tenant em runtime:
    |        Bancos::build('sicredi', [
    |            'client_id' => ..., 'client_secret' => ...,
    |            'certificado' => $pemOuCaminho, 'chave_privada' => $pemOuCaminho,
    |            'cache_key' => "empresa:{$id}",  // isola o token deste tenant
    |        ]);
    |    O 'certificado'/'chave_privada' aceitam caminho de arquivo OU o conteúdo
    |    PEM (o package materializa um arquivo temporário 0600 e o remove sozinho).
    |
    */

    'drivers' => [

        'sicredi' => [
            'driver'        => 'sicredi',
            'client_id'     => env('SICREDI_CLIENT_ID'),
            'client_secret' => env('SICREDI_CLIENT_SECRET'),
            'chave_pix'     => env('SICREDI_CHAVE_PIX'),

            // Certificado mTLS exigido em TODAS as chamadas. Baixe no Portal do
            // Desenvolvedor Sicredi: .cer (PEM) + a chave .key SEM senha.
            'certificado'   => env('SICREDI_CERT_PATH'),
            'chave_privada' => env('SICREDI_KEY_PATH'),
            'senha_cert'    => env('SICREDI_CERT_PASSWORD'),

            // Scopes OAuth2 — obrigatórios (sem eles o Sicredi retorna "Escopo vazio").
            // null usa o default do driver (cob + pix + webhook). Para Pix com
            // vencimento/recorrência, acrescente cobv.* / cobr.* / locrec.*.
            'scopes'        => env('SICREDI_SCOPES'),

            // Produção (documentada): https://api-pix.sicredi.com.br
            // Homologação: a URL NÃO é pública — solicite ao Sicredi
            // (integracoes_pix@sicredi.com.br) e defina em SICREDI_BASE_URL.
            'base_url'      => env('SICREDI_BASE_URL', 'https://api-pix.sicredi.com.br'),

            // SEGURANÇA: base_url DEVE ser https (anti-MITM). 'permitir_http' só
            // libera http em DEV local — nunca em produção.
            'permitir_http' => (bool) env('SICREDI_PERMITIR_HTTP', false),

            // Máx. de Pix por notificação de webhook processada (anti-DoS).
            'webhook_max_itens' => (int) env('SICREDI_WEBHOOK_MAX_ITENS', 1000),

            'timeout'       => (int) env('SICREDI_TIMEOUT', 30),

            // Retry com backoff em falhas transitórias (timeout/conexão e 5xx).
            // Não re-tenta 4xx. tentativas <= 1 desliga. Use txid nos PUT p/ idempotência.
            'tentativas'        => (int) env('SICREDI_TENTATIVAS', 3),
            'retry_intervalo_ms' => (int) env('SICREDI_RETRY_INTERVALO_MS', 200),

            // Boleto registrado (API de Cobrança). É um produto SEPARADO do Pix:
            // outra base_url, OAuth2 grant_type=password e x-api-key — SEM mTLS.
            // As credenciais vêm do portal do desenvolvedor + Internet Banking.
            // sandbox base_url: https://api-parceiro.sicredi.com.br/sb
            'boleto' => [
                'x_api_key'           => env('SICREDI_BOLETO_API_KEY'),
                'username'            => env('SICREDI_BOLETO_USERNAME'),     // beneficiário + cooperativa
                'password'            => env('SICREDI_BOLETO_ACCESS_CODE'),  // código de acesso (Internet Banking)
                'cooperativa'         => env('SICREDI_BOLETO_COOPERATIVA'),
                'posto'               => env('SICREDI_BOLETO_POSTO'),
                'codigo_beneficiario' => env('SICREDI_BOLETO_COD_BENEFICIARIO'),
                'base_url'            => env('SICREDI_BOLETO_BASE_URL', 'https://api-parceiro.sicredi.com.br'),
            ],
        ],

        'c6' => [
            'driver'        => 'c6',
            'client_id'     => env('C6_CLIENT_ID'),
            'client_secret' => env('C6_CLIENT_SECRET'),
            'chave_pix'     => env('C6_CHAVE_PIX'),
            'certificado'   => env('C6_CERT_PATH'),
            'chave_privada' => env('C6_KEY_PATH'),
            'base_url'      => env('C6_BASE_URL', 'https://baas-api-pix.c6bank.info'),
            'timeout'       => (int) env('C6_TIMEOUT', 30),

            // ⚠️ Confirme estes valores na doc oficial do C6 (portal exige cadastro).
            // Os defaults seguem o padrão BACEN mais comum; sobreponha se divergir.
            'token_url'       => env('C6_TOKEN_URL', '/v1/auth/oauth/token'),
            'token_via_basic' => env('C6_TOKEN_VIA_BASIC', true),
            'scopes'          => env('C6_SCOPES'), // ex.: 'cob.write cob.read pix.write pix.read'
            'rota_cob'        => env('C6_ROTA_COB', '/v2/cob'),
            'rota_pix'        => env('C6_ROTA_PIX', '/v2/pix'),
        ],

    ],

];
