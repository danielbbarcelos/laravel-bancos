# 1. Configuração e instalação

## Instalação

```bash
composer require danielbbarcelos/laravel-bancos
```

O service provider é auto-descoberto. Para publicar o arquivo de configuração:

```bash
php artisan vendor:publish --tag=bancos-config
```

Isso cria `config/bancos.php`.

## Credenciais da API de Cobrança (Sicredi)

O boleto usa a **API de Cobrança**, que é um produto **separado do Pix**: outra base URL,
autenticação OAuth2 `grant_type=password` + header `x-api-key`, **sem mTLS**.

Você precisa de:

| Credencial | O que é | Onde obter |
|---|---|---|
| `x_api_key` | Access Token da APP | Portal do Desenvolvedor → Minhas APPs → sua APP → *Ver detalhes* (gerado via chamado de suporte) |
| `username` | beneficiário + cooperativa (9 díg) | dados do convênio de Cobrança |
| `password` | código de acesso | Internet Banking → Cobrança → Código de Acesso → Gerar |
| `cooperativa` | 4 dígitos | dados da conta |
| `posto` | 2 dígitos | dados da conta |
| `codigo_beneficiario` | 5 dígitos | contrato de Cobrança |

> ⚠️ O `x_api_key` **não** é o `client_id` da APP. O `client_id` é gerado automaticamente
> ao criar a APP; o `x_api_key` (Access Token) é liberado depois, via chamado no portal.

### Ambientes

| Ambiente | `base_url` |
|---|---|
| Sandbox (homologação) | `https://api-parceiro.sicredi.com.br/sb` |
| Produção | `https://api-parceiro.sicredi.com.br` |

No sandbox, o manual do Sicredi fornece valores fixos de teste:
`username=123456789`, `password=teste123`, `cooperativa=6789`, `posto=03`,
`codigo_beneficiario=12345`. O único valor real que você precisa é o seu `x_api_key`
de homologação.

## Dois modos de uso

### Single-tenant (via `.env`)

Se você tem um único beneficiário, preencha o bloco `boleto` do config via `env()` e
use `Bancos::driver('sicredi')->boleto()`:

```env
SICREDI_BOLETO_API_KEY=seu-x-api-key
SICREDI_BOLETO_USERNAME=123450101
SICREDI_BOLETO_ACCESS_CODE=seu-codigo-de-acesso
SICREDI_BOLETO_COOPERATIVA=0512
SICREDI_BOLETO_POSTO=03
SICREDI_BOLETO_COD_BENEFICIARIO=12345
SICREDI_BOLETO_BASE_URL=https://api-parceiro.sicredi.com.br
```

```php
$boleto = Bancos::boleto();                 // banco padrão (sicredi)
// ou explicitamente:
$boleto = Bancos::driver('sicredi')->boleto();
```

### Multi-tenant / SaaS (via runtime) ⭐

Se cada cliente da sua aplicação tem o próprio convênio Sicredi, **não** use o `.env` —
passe as credenciais em runtime com `Bancos::build()`. Continue em
[02-autenticacao-runtime.md](02-autenticacao-runtime.md).

Nesse modo, você pode deixar o bloco `boleto` do config com apenas os defaults
compartilhados (ex.: `base_url`), já que as credenciais virão do banco de dados por
tenant.
