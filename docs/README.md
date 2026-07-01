# Documentação — laravel-bancos

Guia de integração da sua API Laravel com o package
[`danielbbarcelos/laravel-bancos`](https://packagist.org/packages/danielbbarcelos/laravel-bancos)
para **emitir e gerenciar boletos** (Sicredi — API de Cobrança).

O foco desta documentação é o cenário **multi-tenant / SaaS**, onde as credenciais
de cada cliente são passadas **em runtime** (via `Bancos::build()`), sem depender do
`.env`. Se você tem um único beneficiário, veja também o modo single-tenant em
[boletos/01-configuracao.md](boletos/01-configuracao.md).

## Índice — Boletos

1. [Configuração e instalação](boletos/01-configuracao.md)
2. [Autenticação em runtime (multi-tenant)](boletos/02-autenticacao-runtime.md) ⭐
3. [Emitir boleto](boletos/03-emitir.md)
4. [Consultar, PDF, baixar, alterar e listar](boletos/04-operacoes.md)
5. [Webhook de liquidação e eventos](boletos/05-webhook.md)
6. [Tratamento de erros](boletos/06-erros.md)
7. [Exemplo completo (Service + Controller)](boletos/07-exemplo-completo.md) ⭐

## Conceito central

Você programa contra um **contrato canônico** (`BoletoGateway`), em português, com DTOs
`final readonly`. O de-para para a API específica do banco fica isolado no driver —
trocar de banco (ou de credenciais por tenant) não muda o seu código cliente.

```php
use DanielBBarcelos\Bancos\Facades\Bancos;

$boleto = Bancos::build('sicredi', $credenciaisDoTenant)
    ->boleto()
    ->emitir($dados);

$boleto->linhaDigitavel;  // pronto para exibir/enviar ao pagador
```

## Requisitos

- PHP `^8.2`, Laravel `>= 11`
- Credenciais da **API de Cobrança do Sicredi** (obtidas no Portal do Desenvolvedor +
  Internet Banking) — veja [01-configuracao.md](boletos/01-configuracao.md).
