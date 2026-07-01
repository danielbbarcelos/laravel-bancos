# 5. Webhook de liquidação e eventos

O Sicredi notifica seu endpoint quando um boleto é **liquidado** (ou estornado). O fluxo
tem duas partes: (1) registrar o contrato de webhook no PSP e (2) receber e processar as
notificações.

## Registrar o contrato de webhook

```php
$contrato = $gateway->registrarWebhook(
    url: 'https://suaapi.com/webhooks/sicredi/boleto',
    eventos: ['LIQUIDACAO'],   // default
);

$contrato->idContrato;
$contrato->status;   // "ATIVO"
```

Outras operações de contrato:

```php
$gateway->consultarWebhook();                       // ?ContratoWebhook (null se não houver)
$gateway->alterarWebhook($idContrato, $novaUrl);    // ContratoWebhook
```

> O endpoint precisa ser **HTTPS**.

## Receber a notificação

O package **não** registra a rota — você cria o endpoint na sua aplicação. A autenticidade
da chamada é responsabilidade da borda (mTLS do PSP / IP allowlist); o BACEN/Sicredi não
usa HMAC.

```php
use Illuminate\Http\Request;
use DanielBBarcelos\Bancos\Facades\Bancos;

Route::post('/webhooks/sicredi/boleto', function (Request $request) {
    // Resolva o gateway do tenant correspondente (por conta/beneficiário no payload,
    // por rota dedicada por tenant, etc.). Veja o exemplo completo.
    $gateway = app(App\Services\BoletoSicredi::class)->paraTenant($tenant);

    $recebimento = $gateway->processarNotificacao($request->all());

    // O evento BoletoLiquidado já foi disparado (veja abaixo). Responda 200 rápido.
    return response()->noContent();
});
```

`processarNotificacao(array $payload)` faz o de-para, **dispara o evento
`Events\BoletoLiquidado`** e devolve um `RecebimentoBoleto`.

### `RecebimentoBoleto`

| Campo | Tipo |
|---|---|
| `nossoNumero` | `string` |
| `movimento` | `string` (ex.: `LIQUIDACAO_PIX`, `ESTORNO_LIQUIDACAO_REDE`) |
| `valorPago` / `valorJuros` / `valorMulta` / `valorDesconto` | `?Valor` |
| `beneficiario` / `agencia` / `posto` | `?string` |
| `bruto` | `array` |
| `pago(): bool` | movimento começa com `LIQUIDACAO` |
| `estornado(): bool` | movimento começa com `ESTORNO` |

## Reagir ao evento

Registre um listener para `BoletoLiquidado` (dar baixa no pedido, conciliar, reverter):

```php
use DanielBBarcelos\Bancos\Events\BoletoLiquidado;
use Illuminate\Support\Facades\Event;

Event::listen(function (BoletoLiquidado $e) {
    $rec = $e->recebimento;   // RecebimentoBoleto
    $banco = $e->banco;       // "sicredi"

    if ($rec->pago()) {
        // baixa o pedido correspondente a $rec->nossoNumero
    } elseif ($rec->estornado()) {
        // reverte
    }
});
```

> `BoletoLiquidado` é uma classe simples (sem `Dispatchable`), despachada via o helper
> `event()`. Registre o listener no seu `EventServiceProvider` ou via `Event::listen`.
