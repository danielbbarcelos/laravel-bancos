# 2. Autenticação em runtime (multi-tenant) ⭐

Este é o modo recomendado para SaaS: as credenciais de Cobrança de cada tenant ficam no
**seu banco de dados**, e você as injeta na hora de emitir o boleto — sem tocar no `.env`.

## `Bancos::build()`

```php
use DanielBBarcelos\Bancos\Facades\Bancos;

$banco = Bancos::build('sicredi', [
    'boleto' => [
        'x_api_key'           => $tenant->sicredi_x_api_key,
        'username'            => $tenant->sicredi_username,      // beneficiário+cooperativa
        'password'            => $tenant->sicredi_codigo_acesso, // código de acesso (IB)
        'cooperativa'         => $tenant->sicredi_cooperativa,
        'posto'               => $tenant->sicredi_posto,
        'codigo_beneficiario' => $tenant->sicredi_cod_beneficiario,
        'base_url'            => 'https://api-parceiro.sicredi.com.br',

        // ⭐ Isola o cache de token deste tenant (veja abaixo).
        'cache_key'           => "boleto:tenant:{$tenant->id}",
    ],
]);

$gateway = $banco->boleto();   // DanielBBarcelos\Bancos\Contracts\BoletoGateway
```

### Como funciona a mesclagem

`build('sicredi', $config)` mescla `$config` **sobre** o bloco `bancos.drivers.sicredi`
do seu config. A mesclagem é rasa (`array_merge`): a chave `boleto` que você passa
**substitui inteira** a do config. Por isso, inclua **todas** as chaves do boleto no
runtime — inclusive `base_url`.

Diferente de `driver()`/`banco()`, o `build()` **não** é cacheado por nome: cada chamada
devolve uma instância isolada, com as credenciais e o cache de token daquele tenant.

## ⚠️ `cache_key` é obrigatório em multi-tenant

O token OAuth2 é cacheado (cifrado) para não reautenticar a cada chamada. A chave desse
cache é derivada assim:

- se você informar `cache_key`, ela é usada diretamente;
- senão, ela é derivada de `sha1(driver | x_api_key | base_url)`.

O fallback já isola por `x_api_key`, mas **sempre informe `cache_key` explicitamente** em
multi-tenant. É determinístico, legível e imune a mudanças de credencial (ex.: se o tenant
rotacionar o `x_api_key`, você controla a invalidação). Use algo estável e único por
convênio, por exemplo `"boleto:tenant:{$tenant->id}"`.

> Nunca use uma `cache_key` fixa/global — isso faria tenants compartilharem o mesmo token.

## Segurança das credenciais

- O `x_api_key`/`password` são mascarados em `__debugInfo()` (dumps/logs) via
  `#[\SensitiveParameter]`. Ainda assim, **não** logue o array de config nem serialize o
  connector/gateway em jobs.
- O token é cacheado com `Crypt::encrypt`/`decrypt`.
- Guarde as credenciais do tenant cifradas no banco (ex.: casts `encrypted` do Eloquent).

## Onde instanciar

Não guarde o gateway em propriedade estática/singleton compartilhada entre requisições de
tenants diferentes. O padrão é resolver **por requisição**, a partir do tenant atual —
veja o [exemplo completo](07-exemplo-completo.md), que encapsula isso num Service.
