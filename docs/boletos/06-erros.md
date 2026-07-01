# 6. Tratamento de erros

Toda falha de API vira uma `BancoApiException`, preservando o contexto da resposta.

```php
use DanielBBarcelos\Bancos\Exceptions\BancoApiException;

try {
    $emitido = $gateway->emitir($dados);
} catch (BancoApiException $e) {
    $e->statusHttp;      // ?int    — ex.: 400, 401, 422
    $e->getMessage();    // string  — mensagem do PSP (lê "detail" ou "mensagem")
    $e->corpo;           // array   — corpo completo da resposta
    $e->violacoes;       // array   — violações (RFC 7807), quando houver

    report($e);
    return response()->json(['erro' => $e->getMessage()], 422);
}
```

## Hierarquia de exceções

| Exceção | Quando |
|---|---|
| `BancoApiException` | a API respondeu com erro (4xx/5xx) |
| `BancoException` | erro de configuração (ex.: bloco `boleto` ausente, driver não registrado) |
| `RecursoNaoSuportado` | o driver não implementa o recurso pedido |

## Erros comuns (Cobrança Sicredi)

| Status | Mensagem típica | Causa |
|---|---|---|
| `400` | `O seu numero do boleto deve ter até 10 caracteres.` | `seuNumero` > 10 caracteres |
| `401` | `... x-api-key ... is invalid` | `x_api_key` inválido / não liberado para Cobrança |
| `401` | `Invalid user parameter` | `username`/`password` incorretos |
| `422` | `Instrução inválida: ...` | regra de negócio do boleto (ex.: título já baixado) |

## Robustez embutida

- **Retry com backoff**: falhas transitórias (timeout/conexão e `5xx`) são re-tentadas
  automaticamente; `4xx` **não** é re-tentado. Configurável por `tentativas` e
  `retry_intervalo_ms`.
- **Re-auth em 401**: o token da API de Cobrança é curto; o package re-emite o token e
  re-tenta a chamada uma vez em caso de `401`.

Você não precisa tratar reautenticação nem retries manualmente — apenas capture
`BancoApiException` para os erros definitivos.
