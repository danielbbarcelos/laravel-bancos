# 4. Consultar, PDF, baixar, alterar e listar

Todas as operações abaixo partem de um `$gateway` já autenticado
(`$banco->boleto()`), como visto em [02-autenticacao-runtime.md](02-autenticacao-runtime.md).

## Consultar

```php
$boleto = $gateway->consultar($nossoNumero);   // BoletoEmitido

$boleto->situacao;   // "EM CARTEIRA", "LIQUIDADO", ...
$boleto->vencimento;
$boleto->valor?->emReais();
$boleto->bruto;      // resposta completa (encargos, dadosLiquidacao, descontos[], etc.)
```

## PDF

```php
$bytes = $gateway->pdf($linhaDigitavel);   // string binária (application/pdf)

return response($bytes, 200, [
    'Content-Type'        => 'application/pdf',
    'Content-Disposition' => 'inline; filename="boleto.pdf"',
]);
```

## Baixar / cancelar

```php
$gateway->baixar($nossoNumero);   // void — solicita a baixa do boleto
```

## Alterar boleto emitido (comandos de instrução)

Cada alteração é um comando de instrução assíncrono; o PSP confirma o **envio** do
movimento e o efetiva depois. Todos retornam `InstrucaoBoleto`.

```php
// Prorrogar/alterar vencimento
$i = $gateway->alterarVencimento($nossoNumero, '2026-09-30');   // YYYY-MM-DD
$i->enviado();       // true se statusComando == MOVIMENTO_ENVIADO
$i->tipoMensagem;    // ex.: "ALTERA_VENCIMENTO"

// Alterar valores de desconto (até 3 faixas)
$gateway->alterarDesconto($nossoNumero, [10.0, 5.0]);

// Alterar datas-limite de desconto (até 3, YYYY-MM-DD)
$gateway->alterarDataDesconto($nossoNumero, ['2026-08-01']);

// Alterar juros (valor ou percentual, conforme cadastro do boleto)
$gateway->alterarJuros($nossoNumero, '2.50');

// Alterar o "seu número"
$gateway->alterarSeuNumero($nossoNumero, 'PEDIDO-99');
```

### `InstrucaoBoleto`

| Campo | Tipo |
|---|---|
| `nossoNumero` | `string` |
| `statusComando` | `?string` (ex.: `MOVIMENTO_ENVIADO`) |
| `tipoMensagem` | `?string` |
| `transactionId` | `?string` |
| `dataHoraRegistro` | `?string` |
| `bruto` | `array` |
| `enviado(): bool` | `statusComando === 'MOVIMENTO_ENVIADO'` |

## Listar boletos liquidados por dia

> A API de Cobrança do Sicredi **só** lista boletos **liquidados por dia** — não há um
> endpoint para "listar todos os boletos". Para acompanhar emitidos/em aberto, mantenha o
> índice no seu próprio banco de dados (grave `nossoNumero` na emissão).

```php
$pagina = $gateway->listarLiquidados(
    dia: '2026-07-30',                    // YYYY-MM-DD (convertido p/ DD/MM/YYYY internamente)
    cpfCnpjBeneficiarioFinal: null,       // opcional
    pagina: 1,                            // opcional (500 registros/página)
);

$pagina->itens;        // list<Liquidacao>
$pagina->temProxima;   // bool — há mais páginas?
$pagina->pagina;       // int

foreach ($pagina->itens as $liq) {
    $liq->nossoNumero;
    $liq->dataPagamento;              // YYYY-MM-DD
    $liq->valorLiquidado?->emReais();
    $liq->tipoLiquidacao;            // "PIX", "REDE", "COMPE", ...
}
```

### Paginação

```php
$dia = '2026-07-30';
$pagina = 1;
do {
    $p = $gateway->listarLiquidados($dia, pagina: $pagina);
    foreach ($p->itens as $liq) {
        // processa...
    }
    $pagina++;
} while ($p->temProxima);
```

### `Liquidacao`

| Campo | Tipo |
|---|---|
| `nossoNumero` | `string` |
| `seuNumero` | `?string` |
| `dataPagamento` | `?string` (`YYYY-MM-DD`) |
| `valor` | `?Valor` (nominal) |
| `valorLiquidado` | `?Valor` |
| `juros` / `desconto` / `multa` / `abatimento` | `?Valor` |
| `tipoLiquidacao` | `?string` |
| `tipoCarteira` | `?string` |
| `cooperativa` | `?string` |
| `bruto` | `array` |
