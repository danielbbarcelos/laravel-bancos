# laravel-bancos

[![CI](https://github.com/danielbbarcelos/laravel-bancos/actions/workflows/ci.yml/badge.svg)](https://github.com/danielbbarcelos/laravel-bancos/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/danielbbarcelos/laravel-bancos.svg)](https://packagist.org/packages/danielbbarcelos/laravel-bancos)
[![Total Downloads](https://img.shields.io/packagist/dt/danielbbarcelos/laravel-bancos.svg)](https://packagist.org/packages/danielbbarcelos/laravel-bancos)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Integração com bancos brasileiros (Pix, e futuramente boleto, pagamentos e conciliação) sob um **contrato único**, com um **driver por banco**. Você programa contra a interface canônica; cada driver faz o de-para para a API específica do banco.

```php
use DanielBBarcelos\Bancos\Facades\Bancos;
use DanielBBarcelos\Bancos\Data\Pix\CobrancaImediata;
use DanielBBarcelos\Bancos\Data\Shared\Valor;

$cobranca = Bancos::driver('sicredi')   // ou só Bancos::pix() para o banco padrão
    ->pix()
    ->cobrancaImediata(new CobrancaImediata(
        valor: Valor::reais('150.00'),
        expiracaoSegundos: 3600,
    ));

$cobranca->qrCode;   // EMV copia-e-cola — idêntico para qualquer banco
$cobranca->txid;
$cobranca->status;   // StatusCobranca enum
```

## Requisitos

- PHP ^8.2
- Laravel ^11.0 || ^12.0

## Instalação

```bash
composer require danielbbarcelos/laravel-bancos
php artisan vendor:publish --tag=bancos-config
```

Configure as credenciais em `.env` — veja **`.env.example`** (na raiz do package) com todas as
variáveis comentadas (Pix + boleto, Sicredi + C6) e `config/bancos.php`. Bancos de Pix exigem
certificado mTLS — informe `*_CERT_PATH` e `*_KEY_PATH`.

## Sicredi: como ligar de verdade

Para a integração funcionar contra a API real do Sicredi você precisa de **três** coisas:

1. **Credenciais OAuth2** — `client_id` e `client_secret` gerados no app do Portal do
   Desenvolvedor Sicredi → `SICREDI_CLIENT_ID` / `SICREDI_CLIENT_SECRET`.
2. **Certificado mTLS** (obrigatório em toda chamada) — baixe no portal o `.cer` (PEM) e a
   chave `.key` **SEM senha**. Aponte em `SICREDI_CERT_PATH` / `SICREDI_KEY_PATH`. Sem o
   certificado, a API responde `403`.
3. **Scopes** — são **obrigatórios** (sem eles o Sicredi retorna `400 "Escopo vazio"`). O
   driver já envia um default para Pix (`cob.* pix.* webhook.*`); ajuste em `SICREDI_SCOPES`
   se precisar de `cobv.*`, `cobr.*` etc.

**Ambientes:** produção é `https://api-pix.sicredi.com.br` (default). A URL de
**homologação não é pública** — solicite ao Sicredi (`integracoes_pix@sicredi.com.br`) e
defina em `SICREDI_BASE_URL`.

### Diagnóstico

Antes de emitir cobranças, valide a conexão de ponta a ponta:

```bash
php artisan bancos:ping sicredi              # checa config, certificado e autenticação
php artisan bancos:ping sicredi --cobranca   # + emite e consulta uma cobrança de R$ 0,01
```

O comando traduz os erros mais comuns (ex.: `403` → certificado mTLS; `401` → credenciais).
Em código, o mesmo está disponível via `Bancos::driver('sicredi')->verificarConexao()`.

## Uso em SaaS / multi-tenant

Em vez de credenciais fixas no `.env`, cada empresa/tenant pode ter as suas. A aplicação
passa tudo **em runtime** com `Bancos::build()` — nada sensível precisa ir para o `.env`:

```php
$banco = Bancos::build('sicredi', [
    'client_id'     => $empresa->sicredi_client_id,
    'client_secret' => $empresa->sicredi_secret(),    // descriptografado pela sua app
    'chave_pix'     => $empresa->chave_pix,
    'certificado'   => $empresa->certificado_pem(),    // conteúdo PEM OU caminho de arquivo
    'chave_privada' => $empresa->chave_pem(),
    'base_url'      => $empresa->sicredi_base_url,      // ou herda o default do config
    'cache_key'     => "empresa:{$empresa->id}",         // isola o token deste tenant
]);

$banco->pix()->cobrancaImediata(new CobrancaImediata(valor: Valor::reais('150.00')));
```

- **Defaults compartilhados** (`base_url`, `scopes`, `timeout`) vêm do bloco do banco no
  `config/bancos.php`; o tenant sobrepõe só o que é dele.
- **Certificado**: aceita o caminho de um arquivo **ou** o conteúdo PEM em memória — neste
  caso o package grava um arquivo temporário `0600` e o remove sozinho. A app guarda o
  certificado de forma segura (criptografado) e só entrega o conteúdo.
- **Isolamento de token**: o cache de token é separado por credencial automaticamente
  (hash de `client_id`+`base_url`); use `cache_key` para forçar um escopo por tenant.
  Dois clientes do mesmo banco **nunca** compartilham token.

> [!note]
> `Bancos::driver('sicredi')` (modo `.env`) e `Bancos::build('sicredi', [...])` (runtime)
> coexistem. Single-tenant usa o primeiro; SaaS usa o segundo.

## Cobrança com vencimento (cobv) — "boleto-Pix"

Cobrança com data de vencimento, multa, juros e desconto. O `txid` é sempre seu (PUT):

```php
use DanielBBarcelos\Bancos\Data\Pix\{CobrancaComVencimento, Multa, Juros, Desconto, DescontoData};
use DanielBBarcelos\Bancos\Data\Shared\{Pessoa, Valor};

$cobranca = Bancos::pix()->cobrancaComVencimento(new CobrancaComVencimento(
    txid: 'fatura-2026-07-0042',
    valor: Valor::reais('250.00'),
    vencimento: '2026-07-30',                 // YYYY-MM-DD
    validadeAposVencimento: 30,               // dias que aceita pagamento após vencer
    devedor: new Pessoa(nome: 'Fulano', documento: '000.123.123-12'),
    multa: new Multa(modalidade: 2, valorPerc: '2.00'),       // modalidade 2 = percentual
    juros: new Juros(modalidade: 2, valorPerc: '1.00'),
    desconto: new Desconto(modalidade: 2, datasFixas: [
        new DescontoData(data: '2026-07-20', valorPerc: '5.00'), // 5% se pago até 20/07
    ]),
));

$cobranca->qrCode;      // copia-e-cola
$cobranca->vencimento;  // '2026-07-30'

Bancos::pix()->consultarCobrancaVencimento('fatura-2026-07-0042');
```

> [!note] `cobv` exige os scopes `cobv.read cobv.write` no token — já incluídos no default do
> driver Sicredi. As modalidades de multa/juros/desconto seguem o padrão BACEN.

## Gerenciar cobrança e conciliar

```php
// Cancelar/remover uma cobrança ativa
$cobranca = Bancos::pix()->cancelarCobranca($txid);   // status vira RemovidaPeloUsuario

// Listar cobranças imediatas num intervalo (relatórios)
$cobrancas = Bancos::pix()->listarCobrancas(
    '2026-06-01T00:00:00Z',
    '2026-06-30T23:59:59Z',
    ['status' => 'ATIVA'],                            // filtros opcionais do PSP
);

// Consultar um Pix recebido (fallback do webhook)
$pix = Bancos::pix()->consultarPix($endToEndId);      // Recebimento

// Listar Pix recebidos num intervalo (timestamps ISO-8601) — conciliação
$recebidos = Bancos::pix()->listarPixRecebidos(
    '2026-06-01T00:00:00Z',
    '2026-06-30T23:59:59Z',
    ['cnpj' => '12345678000195'],                     // filtros opcionais do PSP
);

// Consultar o status de uma devolução já solicitada
$devolucao = Bancos::pix()->consultarDevolucao($endToEndId, $idDevolucao);
```

## Boleto registrado

A API de boleto do Sicredi é um produto **separado** do Pix (outra base URL, OAuth2
`grant_type=password` + `x-api-key`, sem mTLS). Configure o bloco `boleto` em
`config/bancos.php` e use:

```php
use DanielBBarcelos\Bancos\Data\Boleto\{Boleto, Pessoa, Encargo, Desconto};
use DanielBBarcelos\Bancos\Data\Shared\Valor;
use DanielBBarcelos\Bancos\Enums\{TipoValor, TipoCobrancaBoleto};

$boleto = Bancos::driver('sicredi')->boleto()->emitir(new Boleto(
    pagador: new Pessoa(
        nome: 'Rodrigo Oliveira', documento: '027.383.060-06',
        cep: '91250000', cidade: 'Porto Alegre', uf: 'RS',
        logradouro: 'Rua Doutor Vargas', numero: '150',
    ),
    valor: Valor::reais('250.00'),
    vencimento: '2026-07-30',
    seuNumero: 'PEDIDO-42',
    multa: new Encargo(TipoValor::Percentual, 2.0, '2026-07-31'),
    juros: new Encargo(TipoValor::Percentual, 1.0, '2026-07-31'),
    desconto: new Desconto(TipoValor::Valor, [['valor' => 10.0, 'data' => '2026-07-15']]),
    tipoCobranca: TipoCobrancaBoleto::Hibrido,   // gera também o QR Code Pix
));

$boleto->linhaDigitavel;
$boleto->codigoBarras;
$boleto->nossoNumero;
$boleto->qrCode;   // preenchido quando híbrido

Bancos::driver('sicredi')->boleto()->consultar($nossoNumero);  // BoletoEmitido (situação, etc.)
$pdfBytes = Bancos::driver('sicredi')->boleto()->pdf($cobranca->linhaDigitavel); // PDF (bytes)
Bancos::driver('sicredi')->boleto()->baixar($nossoNumero);     // cancela/baixa
```

> [!note] Cheque o suporte antes: `Bancos::banco()->suporta(Recurso::Boleto)`.

### Webhook de boleto (liquidação/estorno)

Diferente do webhook Pix, o boleto usa um **contrato de webhook**:

```php
$boleto = Bancos::driver('sicredi')->boleto();
$boleto->registrarWebhook('https://meuapp.com/webhooks/boleto', ['LIQUIDACAO']);
$boleto->consultarWebhook();          // ?ContratoWebhook
$boleto->alterarWebhook($idContrato, 'https://nova-url', ['LIQUIDACAO']);

// No seu controller que recebe a notificação do Sicredi:
$recebimento = $boleto->processarNotificacao($request->all()); // dispara Events\BoletoLiquidado
$recebimento->pago();       // movimento LIQUIDACAO_*
$recebimento->estornado();  // movimento ESTORNO_*
$recebimento->valorPago;    // Valor
```

```php
use DanielBBarcelos\Bancos\Events\BoletoLiquidado;

class DarBaixaNoBoleto {
    public function handle(BoletoLiquidado $e): void {
        $e->recebimento->nossoNumero; // localizar e dar baixa/conciliar
    }
}
```

## Webhook Pix (confirmação de recebimento)

Para saber que uma cobrança foi **paga**, registre um webhook e processe a notificação.

### 1. Registrar o webhook no PSP

```php
Bancos::pix()->configurarWebhook('https://meuapp.com/webhooks/pix/sicredi');
// usa a chave Pix padrão (chave_pix); ou passe a chave: ->configurarWebhook($url, $chave)

Bancos::pix()->consultarWebhook();   // ?Webhook
Bancos::pix()->cancelarWebhook();    // remove
```

### 2. Receber a notificação

O package **não registra rota** — você cria o endpoint e aplica a sua segurança. O Sicredi
notifica via **mTLS** (valide o certificado do cliente na borda: servidor web / proxy), e é
recomendável restringir por IP. No controller, delegue o parsing ao package:

```php
use DanielBBarcelos\Bancos\Facades\Bancos;

Route::post('/webhooks/pix/sicredi', function (Illuminate\Http\Request $request) {
    Bancos::driver('sicredi')->pix()->processarNotificacao($request->all());

    return response()->noContent(); // 200/204
})->middleware('auth.webhook-pix'); // sua proteção (mTLS/IP)
```

`processarNotificacao()` dispara o evento `Events\PixRecebido` para **cada** Pix recebido e
retorna `list<Recebimento>`.

### 3. Reagir ao pagamento

```php
use DanielBBarcelos\Bancos\Events\PixRecebido;

class DarBaixaNoPedido
{
    public function handle(PixRecebido $evento): void
    {
        $pix = $evento->pix; // Recebimento: txid, valor, endToEndId, pagador...
        // localizar pedido por $pix->txid, marcar como pago, conciliar...
    }
}
```

> [!warning] O endpoint de webhook é público. Proteja-o (mTLS do PSP na borda + allowlist de
> IP). O padrão BACEN não usa assinatura HMAC — a autenticidade vem do mTLS.

## Arquitetura

| Camada | Papel |
|--------|-------|
| `Contracts\*` | O contrato canônico, independente de banco (`Banco`, `PixGateway`, `BoletoGateway`). |
| `Data\*` | DTOs `readonly` canônicos (`Valor`, `Pessoa`, `CobrancaImediata`, `CobrancaComVencimento`, `Cobranca`, `Devolucao`, `Webhook`, `Recebimento`). |
| `Events\*` | Eventos de domínio (`PixRecebido`, `BoletoLiquidado`), despachados ao processar webhooks. |
| `BancoManager` | Resolve/cacheia drivers a partir da config. Use `extend()` para registrar bancos próprios. |
| `Drivers\Bacen\*` | Núcleo reutilizável do padrão BACEN: `BacenPixGateway` + Mappers + `ClienteHttpBacen` (mTLS, cache de token). |
| `Drivers\<Banco>` | Só o que muda por banco: **autenticação** (token) e **rotas/versões**. Reaproveita o núcleo BACEN. |

Como Sicredi, C6 e a maioria dos PSPs brasileiros seguem o padrão Pix do BACEN, o de-para vive uma vez só em `Drivers\Bacen`. Um novo banco geralmente é só um `Connector` (como ele emite o token) + os prefixos de rota.

```
DTO canônico → [Driver Sicredi/Mappers] → payload da API → resposta → DTO canônico
```

Trocar de banco não muda o código de quem chama.

### Registrar um driver de terceiros

```php
Bancos::extend('meu_banco', fn (array $config, string $nome) => new MeuBanco($config, $nome));
```

## Suporte atual

| Banco | Pix | Boleto | Pagamento | Conciliação |
|-------|:--:|:--:|:--:|:--:|
| Sicredi | ✅ | ✅ | 🔜 | 🔜 |
| C6 | ✅* | 🔜 | 🔜 | 🔜 |

\* C6: implementado sobre o núcleo BACEN. O endpoint de token, scopes e versão das rotas
estão sob a doc oficial (portal exige cadastro) — confirme e ajuste via config
(`token_url`, `token_via_basic`, `scopes`, `rota_cob`, `rota_pix`) antes de produção.

Antes de chamar um recurso que pode não existir: `Bancos::banco()->suporta(Recurso::Boleto)`.

## Testes

```bash
composer test   # vendor/bin/pest
```

Os testes usam `Http::fake()` — não tocam nenhuma API real.

## Segurança

- **mTLS + TLS verify**: a verificação do certificado do servidor é sempre habilitada
  (`verify => true`); o package nunca a desabilita.
- **HTTPS obrigatório**: `base_url` e a `webhookUrl` precisam ser `https` (anti-MITM). Um
  `http://` lança `BancoException`. Só dev local pode liberar via `permitir_http => true`.
- **Credenciais mascaradas**: connectors e drivers usam `#[\SensitiveParameter]` (redige em
  stack traces) e `__debugInfo()` (mascara `client_secret`/`password`/`x_api_key`/certificado
  em `dd()`/`var_dump()`/`dump()`).
  > ⚠️ `var_export()`, `print_r()` e `serialize()` **não** respeitam `__debugInfo()` — **não
  > serialize um connector/driver numa queued job**; passe os dados, não o objeto.
- **Token cifrado no cache**: o access token é gravado com `Crypt::encrypt` (usa a `APP_KEY`),
  então vazar o store de cache não basta. Ainda assim, use um store seguro (ex.: Redis com
  AUTH/TLS) em produção.
- **Webhook**: a autenticidade vem do **mTLS do PSP na borda** + allowlist de IP (o BACEN não
  usa HMAC). O `processarNotificacao()` limita o nº de Pix por payload (`webhook_max_itens`,
  anti-DoS) — implemente rate-limit no endpoint.
- **Erros**: `BancoApiException->corpo` traz a resposta crua do PSP (pode conter PII). **Não
  logue a exceção crua**; o `getMessage()` expõe só `detail`/`title`.
- **Certificado em memória**: ao passar o PEM por conteúdo, o arquivo temporário é `0600` e
  removido no `__destruct` **e** no shutdown (mesmo após fatal).

## Robustez

- **Retry com backoff**: chamadas re-tentam automaticamente em falhas transitórias
  (timeout/conexão e HTTP 5xx); 4xx **não** é re-tentado (vira `BancoApiException` na hora).
  Configurável por banco: `tentativas` (default 3) e `retry_intervalo_ms` (default 200).
  Use `txid` nos PUT para idempotência.
- **Retry em 401**: se o PSP responder `401` (token expirado no servidor antes do TTL local),
  o token cacheado é descartado e a chamada é refeita **uma vez** automaticamente.
- **Validação de `txid`**: ao criar cobrança com `txid` (imediata ou `cobv`), o formato BACEN
  (26–35 alfanuméricos) é validado antes de chamar a API — falha cedo com mensagem clara, sem
  gastar um round-trip para receber um `400`.
