# CLAUDE.md — laravel-bancos

Orientação para agentes que trabalham neste package.

## O que é

Package Laravel (>= 11, PHP ^8.2) para integrar com **bancos brasileiros** sob um
**contrato único** e **um driver por banco**. Hoje: Pix (Sicredi). Roadmap: boleto,
pagamentos (Pix out), conciliação, mais bancos.

- Vendor: `danielbbarcelos/laravel-bancos` · Namespace: `DanielBBarcelos\Bancos`
- Princípio central: quem chama programa contra a interface canônica; o **de-para** para
  a API de cada banco vive isolado no driver. Trocar de banco não muda o código cliente.

## Arquitetura

```
src/
├── BancoManager.php          # resolve/cacheia drivers; extend() registra novos
├── Facades/Bancos.php
├── Contracts/                # contrato canônico: Banco, PixGateway, BoletoGateway
├── Data/                     # DTOs readonly: Shared/{Valor,Pessoa,StatusConexao}, Pix/*
├── Enums/                    # Recurso, StatusCobranca
├── Exceptions/               # BancoException, BancoApiException (RFC 7807), RecursoNaoSuportado
├── Events/PixRecebido.php    # disparado por processarNotificacao() do webhook
├── Console/PingCommand.php   # php artisan bancos:ping
└── Drivers/
    ├── Bacen/                # NÚCLEO reutilizável do padrão BACEN
    │   ├── BacenPixGateway.php       # implementa PixGateway p/ qualquer PSP BACEN
    │   ├── ClienteHttpBacen.php      # base HTTP: mTLS, OAuth2, cache de token
    │   ├── BacenConnector.php        # interface get/post/put
    │   ├── Concerns/VerificaConexaoBacen.php  # trait verificarConexao()
    │   └── Mappers/{Cobranca,Devolucao}Mapper.php
    ├── Sicredi/              # só auth (SicrediConnector) + rotas (cob v3, pix v2)
    └── C6/                   # esqueleto; auth/rotas a confirmar na doc oficial
```

### Como adicionar um banco BACEN

1. `Drivers/<Banco>/<Banco>Connector.php extends ClienteHttpBacen` — só implementa
   `emitirToken()` (a única coisa que varia: como o PSP emite o token).
2. `Drivers/<Banco>/<Banco>Banco.php implements Banco`, usa o trait
   `VerificaConexaoBacen`, e em `pix()` instancia `BacenPixGateway` com `rotaCob`/`rotaPix`.
3. Registrar no `BancoManager::registrarDriversPadrao()` via `extend()`.
4. Adicionar bloco em `config/bancos.php` e um teste em `tests/`.

Só crie mappers próprios se o banco divergir do payload BACEN; senão, reutilize
`Drivers\Bacen\Mappers`.

## Webhook Pix

- Gerência no PSP (`BacenPixGateway`): `configurarWebhook()`/`consultarWebhook()`/
  `cancelarWebhook()` em `{rotaWebhook}/{chave}`. `consultarWebhook()` trata 404 como `null`.
- Recebimento: `processarNotificacao(array $payload)` parseia via `WebhookMapper`
  (`{"pix":[...]}`), dispara `Events\PixRecebido` por item (helper `event()`) e retorna
  `list<Recebimento>`. O package **não** registra rota — a app cria o endpoint e a segurança
  (mTLS do PSP na borda / IP allowlist; BACEN não usa HMAC).

## Boleto (Sicredi — API de Cobrança)

- Produto **separado** do Pix: `src/Drivers/Sicredi/Boleto/`. Base `api-parceiro.sicredi.com.br`,
  OAuth2 `grant_type=password` (username = beneficiário+cooperativa, password = código de acesso),
  headers `x-api-key`/`cooperativa`/`posto`/`codigoBeneficiario`, **sem mTLS**.
- `SicrediBoletoConnector extends ClienteHttpBacen` só para reusar cache de token, retry e erros;
  sobrescreve `emitirToken()` (password grant) e `cliente()` (headers). Token expira em 300s
  (re-emite por password; refresh_token não usado — melhoria futura).
- `BoletoGateway`: `emitir`/`consultar`/`pdf`/`baixar` + **comandos de instrução**
  (`alterarVencimento`/`alterarDesconto`/`alterarDataDesconto`/`alterarJuros`/`alterarSeuNumero`,
  cada um um `PATCH /boletos/{nossoNumero}/{comando}` → `Data\Boleto\InstrucaoBoleto`) +
  **listagem** (`listarLiquidados` → `GET /boletos/liquidados/dia`, paginado 500/pág →
  `Data\Boleto\PaginaLiquidacoes` de `Liquidacao`; a API do Sicredi **só** lista liquidados por
  dia, não há "listar todos") + webhook por contrato
  (`registrarWebhook`/`consultarWebhook`/`alterarWebhook`/`processarNotificacao` → evento
  `Events\BoletoLiquidado`). DTOs em `Data\Boleto\*`. Config no bloco `boleto` de
  `bancos.drivers.sicredi`; `SicrediBanco::boleto()` lança `BancoException` se ausente.
- Comandos de instrução e liquidados fiéis ao **Manual da API da Cobrança 1.2** do Sicredi
  (paths `/data-vencimento`, `/desconto`, `/data-desconto`, `/juros`, `/seu-numero`;
  `/liquidados/dia` com `dia` em DD/MM/YYYY — o gateway converte de YYYY-MM-DD canônico).
- PDF via `ClienteHttpBacen::getRaw()` (Accept custom). Token de boleto é curto (~10 min) →
  `enviar()` re-tenta uma vez em 401 (vale p/ Pix também). Erro lido também de `mensagem` (PT).
- Endpoints de webhook de boleto seguem o `sicredi_client.py` real do meanify
  (`/cobranca/boleto/v1/webhook/contrato[s]`), não o markdown (que diverge).

## Robustez (ClienteHttpBacen)

- **Retry**: `aplicarRetry()` re-tenta em `ConnectionException`/5xx (config `tentativas`,
  `retry_intervalo_ms`); 4xx não re-tenta. O wrapper `enviar()` captura `RequestException`
  (lançada pelo retry) e devolve a resposta ao `garantirOk()` → sempre `BancoApiException`.
- **txid**: `Support\Txid::exigirValido()` valida 26–35 alfanuméricos (BACEN) na criação com
  txid (cob imediata com txid e cobv) antes do PUT.

## Multi-tenant / SaaS

- `Bancos::build($driver, $config)` (`BancoManager::build`) cria uma instância isolada com
  credenciais de runtime, mesclando sobre os defaults do bloco do config. **Não** cacheia
  por nome. `driver()`/`banco()` (modo `.env`) continuam para single-tenant.
- **Cache de token isolado por credencial**: `ClienteHttpBacen::chaveCacheToken()` deriva a
  chave de `cache_key` (se informado) ou de `sha1(driver|client_id|base_url)`. Nunca volte à
  chave fixa por nome — isso vazaria token entre tenants.
- **Certificado**: `certificado`/`chave_privada` aceitam caminho OU conteúdo PEM.
  `ClienteHttpBacen::resolverArquivo()` materializa o PEM via `Support\CertificadoTemporario`
  (arquivo `0600`, removido no `__destruct`) e memoiza o caminho.

## Segurança (invariantes — não regredir)

- **HTTPS**: `ClienteHttpBacen::urlBase()` valida via `Support\Url::exigirHttps()` (bypass só
  com `config['permitir_http']`). Todo `emitirToken()` e `cliente()` usam `urlBase()`.
  `verify => true` é forçado no cliente. `configurarWebhook()` também exige https.
- **Mascaramento**: `#[\SensitiveParameter]` no `$config` dos construtores + `__debugInfo()`
  via `Support\Segredo::mascarar()` (ClienteHttpBacen, SicrediBanco, C6Banco). Não adicione
  logging de `$config`/headers; não serialize connectors em jobs.
- **Token**: cacheado com `Crypt::encrypt`/`decrypt` (fallback `DecryptException` → re-emite).
  Chave isolada por credencial (`chaveCacheToken`).
- **Webhook**: `processarNotificacao()` limita itens (`maxItensNotificacao`); autenticidade é
  responsabilidade da borda (mTLS/IP), não do package.
- **Certificado**: `CertificadoTemporario` grava `0600` e limpa no `__destruct` + shutdown.

## Convenções

- **Idioma**: código, nomes e comentários em **português** (DTOs, métodos, etc.).
- **DTOs**: `final readonly`, sem dependência externa. `Valor` guarda centavos como `int`.
- **HTTP**: cliente nativo do Laravel (`Illuminate\Support\Facades\Http`). **Não** usar Saloon.
- **Erros de API**: sempre via `BancoApiException::daResposta()` (preserva `statusHttp`,
  `corpo`, `violacoes`).
- **Sem efeitos colaterais em leitura**: `verificarConexao()` só autentica, não cria nada.

## Testes

```bash
composer install
vendor/bin/pest          # ou: composer test
```

- Testbench + Pest. Tudo mockado com `Http::fake()` — **nenhum** teste toca a rede real.
- `tests/TestCase.php` define conexões `sicredi` e `c6` de teste (sem mTLS).
- Ao mudar de-para/auth, atualize os asserts de `Http::assertSent` correspondentes.

## Fidelidade à API (Sicredi)

Baseado no Guia Técnico Pix Sicredi v1.9.5 (`docs/Sicredi/`, fora do package):
- Token: `POST /oauth/token`, **Basic auth**, corpo **form-urlencoded** com
  `grant_type=client_credentials` + **`scope`** (obrigatório; sem ele → `400 "Escopo vazio"`).
- mTLS obrigatório (`.cer` PEM + `.key` sem senha) → falta dele = `403`.
- cob em `/api/v3/cob`; cobv (com vencimento) em `/api/v2/cobv/{txid}` (sempre PUT); devolução
  em `/api/v2/pix/{e2e}/devolucao/{id}`; webhook em `/api/v2/webhook/{chave}`.
- Erros em RFC 7807 (`type/title/status/detail/violacoes`).

## Estado e pendências

Pix Sicredi funcional e testado. Bloqueios de produção (certificado mTLS, ambiente) e
roadmap completo documentados no Obsidian: `Projetos/laravel-bancos/`.
