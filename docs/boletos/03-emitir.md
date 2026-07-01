# 3. Emitir boleto

```php
use DanielBBarcelos\Bancos\Data\Boleto\Boleto;
use DanielBBarcelos\Bancos\Data\Boleto\Pessoa;
use DanielBBarcelos\Bancos\Data\Shared\Valor;

$emitido = $gateway->emitir(new Boleto(
    pagador: new Pessoa(
        nome: 'Rodrigo Oliveira',
        documento: '027.383.060-06',   // CPF ou CNPJ (PF/PJ é derivado automaticamente)
        cep: '91250000',
        cidade: 'Porto Alegre',
        uf: 'RS',
        logradouro: 'Rua Doutor Vargas',
        numero: '150',
    ),
    valor: Valor::reais('50.00'),
    vencimento: '2026-07-30',           // YYYY-MM-DD
    seuNumero: 'PEDIDO-42',             // seu identificador — MÁX. 10 caracteres
));

$emitido->nossoNumero;     // ex.: "251006142"
$emitido->linhaDigitavel;  // linha digitável para exibir/enviar
$emitido->codigoBarras;
```

`emitir()` devolve um [`BoletoEmitido`](#boletoemitido).

## O DTO `Boleto`

| Campo | Tipo | Obrigatório | Observação |
|---|---|---|---|
| `pagador` | `Pessoa` | ✅ | quem paga o boleto |
| `valor` | `Valor` | ✅ | veja [Valor](#valor) |
| `vencimento` | `string` | ✅ | `YYYY-MM-DD` |
| `seuNumero` | `string` | ✅ | seu identificador, **≤ 10 caracteres** |
| `especieDocumento` | `string` | — | default `DUPLICATA_MERCANTIL_INDICACAO` |
| `tipoCobranca` | `TipoCobrancaBoleto` | — | `Normal` (default) ou `Hibrido` (gera QR Code Pix) |
| `nossoNumero` | `?string` | — | omita para o Sicredi gerar |
| `beneficiarioFinal` | `?Pessoa` | — | quando o recebedor final difere do beneficiário |
| `juros` | `?Encargo` | — | veja [Encargo](#encargo) |
| `multa` | `?Encargo` | — | veja [Encargo](#encargo) |
| `desconto` | `?Desconto` | — | veja [Desconto](#desconto) |
| `mensagens` | `list<string>` | — | mensagens no corpo do boleto |
| `informativos` | `list<string>` | — | textos informativos |

> **`seuNumero` ≤ 10 caracteres.** O Sicredi rejeita valores maiores com
> `400 "O seu numero do boleto deve ter até 10 caracteres."`.

## `Valor`

Guarda centavos internamente (sem imprecisão de float). Construa por reais ou centavos:

```php
Valor::reais('50.00');   // string
Valor::reais(50.0);      // float
Valor::reais(50);        // int
Valor::centavos(5000);   // int em centavos

$v->emReais();  // 50.0  (float)
$v->paraApi();  // "50.00" (string)
```

## `Pessoa`

```php
new Pessoa(
    nome: 'Empresa X LTDA',
    documento: '12.345.678/0001-99',  // com ou sem máscara; PF/PJ derivado do tamanho
    cep: '91250000',
    cidade: 'Porto Alegre',
    uf: 'RS',
    logradouro: 'Av. Assis Brasil',   // opcional
    numero: '2000',                   // opcional
);
```

`documento` aceita máscara — o package limpa e deriva `PESSOA_FISICA`/`PESSOA_JURIDICA`
pelo número de dígitos (11 = PF, 14 = PJ).

## `Encargo` (juros e multa)

```php
use DanielBBarcelos\Bancos\Data\Boleto\Encargo;
use DanielBBarcelos\Bancos\Enums\TipoValor;

multa: new Encargo(tipo: TipoValor::Percentual, valor: 2.0, dataInicio: '2026-07-31'),
juros: new Encargo(tipo: TipoValor::Valor,      valor: 0.50, dataInicio: '2026-07-31'),
```

`TipoValor`: `Valor` (R$ fixo) ou `Percentual`. `dataInicio` (`YYYY-MM-DD`) é opcional.

## `Desconto`

Até 3 faixas por data-limite:

```php
use DanielBBarcelos\Bancos\Data\Boleto\Desconto;
use DanielBBarcelos\Bancos\Enums\TipoValor;

desconto: new Desconto(tipo: TipoValor::Valor, faixas: [
    ['valor' => 10.0, 'data' => '2026-07-15'],
    ['valor' => 5.0,  'data' => '2026-07-20'],
]),
```

## Boleto híbrido (boleto + QR Code Pix)

```php
use DanielBBarcelos\Bancos\Enums\TipoCobrancaBoleto;

new Boleto(
    // ...
    tipoCobranca: TipoCobrancaBoleto::Hibrido,
);
```

A resposta virá com `qrCode` e `txid` preenchidos, além da linha digitável.

## `BoletoEmitido`

Resposta de `emitir()` e `consultar()`:

| Campo | Tipo | Observação |
|---|---|---|
| `nossoNumero` | `string` | |
| `linhaDigitavel` | `?string` | |
| `codigoBarras` | `?string` | |
| `cooperativa` / `posto` | `?string` | |
| `txid` | `?string` | boleto híbrido (Pix) |
| `qrCode` | `?string` | QR Code Pix do híbrido |
| `situacao` | `?string` | preenchido na consulta (ex.: `EM CARTEIRA`, `LIQUIDADO`) |
| `vencimento` | `?string` | consulta |
| `valor` | `?Valor` | consulta (`valorNominal`) |
| `bruto` | `array` | resposta original do PSP (todos os campos) |

> `bruto` guarda a resposta completa da API — use-a para campos que ainda não estão
> tipados (ex.: encargos e dados de liquidação retornados na consulta).
