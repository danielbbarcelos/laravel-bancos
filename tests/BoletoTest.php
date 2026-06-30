<?php

declare(strict_types=1);

use DanielBBarcelos\Bancos\Data\Boleto\Boleto;
use DanielBBarcelos\Bancos\Data\Boleto\BoletoEmitido;
use DanielBBarcelos\Bancos\Data\Boleto\Desconto;
use DanielBBarcelos\Bancos\Data\Boleto\Encargo;
use DanielBBarcelos\Bancos\Data\Boleto\Pessoa;
use DanielBBarcelos\Bancos\Data\Boleto\RecebimentoBoleto;
use DanielBBarcelos\Bancos\Data\Shared\Valor;
use DanielBBarcelos\Bancos\Enums\TipoCobrancaBoleto;
use DanielBBarcelos\Bancos\Enums\TipoValor;
use DanielBBarcelos\Bancos\Events\BoletoLiquidado;
use DanielBBarcelos\Bancos\Exceptions\BancoApiException;
use DanielBBarcelos\Bancos\Facades\Bancos;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    // Token da API de Cobrança (diferente do Pix): grant_type=password.
    Http::fake([
        '*/auth/openapi/token' => Http::response([
            'access_token' => 'tok-boleto',
            'token_type' => 'Bearer',
            'expires_in' => 300,
        ]),
    ]);
});

it('emite um boleto e mapeia a resposta', function () {
    Http::fake([
        '*/cobranca/boleto/v1/boletos' => Http::response([
            'txid' => null,
            'qrCode' => null,
            'linhaDigitavel' => '74891125110061420512803153351030188640000009 90',
            'codigoBarras' => '74891186400000099901125100614205120315335103',
            'cooperativa' => '0512',
            'posto' => '03',
            'nossoNumero' => '251006142',
        ]),
    ]);

    $boleto = Bancos::driver('sicredi')->boleto()->emitir(new Boleto(
        pagador: new Pessoa(
            nome: 'Rodrigo Oliveira',
            documento: '027.383.060-06',
            cep: '91250000',
            cidade: 'Porto Alegre',
            uf: 'RS',
            logradouro: 'Rua Doutor Vargas',
            numero: '150',
        ),
        valor: Valor::reais('50.00'),
        vencimento: '2026-07-30',
        seuNumero: 'PEDIDO-42',
        multa: new Encargo(tipo: TipoValor::Percentual, valor: 2.0, dataInicio: '2026-07-31'),
        desconto: new Desconto(tipo: TipoValor::Valor, faixas: [
            ['valor' => 10.0, 'data' => '2026-07-15'],
        ]),
    ));

    expect($boleto)->toBeInstanceOf(BoletoEmitido::class)
        ->and($boleto->nossoNumero)->toBe('251006142')
        ->and($boleto->codigoBarras)->toBe('74891186400000099901125100614205120315335103');

    // De-para fiel à API de Cobrança do Sicredi.
    Http::assertSent(function ($r) {
        if (! str_contains($r->url(), '/cobranca/boleto/v1/boletos')) {
            return false;
        }

        $b = $r->data();

        return $r->method() === 'POST'
            && $b['codigoBeneficiario'] === '12345'
            && $b['tipoCobranca'] === 'NORMAL'
            && $b['valor'] === 50.0
            && $b['seuNumero'] === 'PEDIDO-42'
            && $b['pagador']['tipoPessoa'] === 'PESSOA_FISICA'
            && $b['pagador']['endereco'] === 'Rua Doutor Vargas 150'
            && $b['tipoMulta'] === 'PERCENTUAL'
            && $b['multa'] === 2.0
            && $b['tipoDesconto'] === 'VALOR'
            && $b['valorDesconto1'] === 10.0
            && $b['dataDesconto1'] === '2026-07-15'
            // headers da API de Cobrança
            && $r->hasHeader('x-api-key', 'api-key-teste')
            && $r->hasHeader('cooperativa', '0512')
            && $r->hasHeader('posto', '03');
    });
});

it('autentica com grant_type=password no endpoint da API de Cobrança', function () {
    Http::fake(['*/cobranca/boleto/v1/boletos*' => Http::response(['nossoNumero' => '1'])]);

    Bancos::driver('sicredi')->boleto()->consultar('251006142');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/auth/openapi/token')
        && $r['grant_type'] === 'password'
        && $r['scope'] === 'cobranca'
        && $r->hasHeader('context', 'COBRANCA'));
});

it('consulta um boleto pelo nossoNumero', function () {
    Http::fake([
        '*/cobranca/boleto/v1/boletos*' => Http::response([
            'nossoNumero' => '211001292',
            'linhaDigitavel' => '748911...',
            'situacao' => 'EM CARTEIRA',
            'dataVencimento' => '2026-09-23',
            'valorNominal' => 150.00,
            'txid' => '7903187896e4032a89c59eb3f232cda',
            'codigoQrCode' => '00020126...',
        ]),
    ]);

    $boleto = Bancos::driver('sicredi')->boleto()->consultar('211001292');

    expect($boleto->situacao)->toBe('EM CARTEIRA')
        ->and($boleto->vencimento)->toBe('2026-09-23')
        ->and($boleto->valor->paraApi())->toBe('150.00')
        ->and($boleto->qrCode)->toBe('00020126...');   // codigoQrCode mapeado p/ qrCode

    Http::assertSent(fn ($r) => $r->method() === 'GET'
        && str_contains($r->url(), 'codigoBeneficiario=12345')
        && str_contains($r->url(), 'nossoNumero=211001292'));
});

it('baixa um boleto via PATCH', function () {
    Http::fake(['*/cobranca/boleto/v1/boletos/*/baixa' => Http::response([
        'statusComando' => 'MOVIMENTO_ENVIADO',
        'tipoMensagem' => 'BAIXA',
    ], 202)]);

    Bancos::driver('sicredi')->boleto()->baixar('251006142');

    Http::assertSent(fn ($r) => $r->method() === 'PATCH'
        && str_ends_with($r->url(), '/cobranca/boleto/v1/boletos/251006142/baixa'));
});

it('baixa o PDF do boleto com Accept application/pdf', function () {
    Http::fake([
        '*/cobranca/boleto/v1/boletos/pdf*' => Http::response('%PDF-1.7 conteudo', 200, ['Content-Type' => 'application/pdf']),
    ]);

    $bytes = Bancos::driver('sicredi')->boleto()->pdf('74891123456000000001600000000131876500001500');

    expect($bytes)->toStartWith('%PDF');

    Http::assertSent(fn ($r) => $r->method() === 'GET'
        && str_contains($r->url(), '/cobranca/boleto/v1/boletos/pdf')
        && str_contains($r->url(), 'linhaDigitavel=')
        && $r->hasHeader('Accept', 'application/pdf'));
});

it('traduz erro do boleto lido da chave "mensagem"', function () {
    Http::fake([
        '*/cobranca/boleto/v1/boletos' => Http::response(['mensagem' => 'Instrução inválida: título enviado para CAIXA'], 422),
    ]);

    try {
        Bancos::driver('sicredi')->boleto()->emitir(new Boleto(
            pagador: new Pessoa('X', '00012312312', '90000000', 'POA', 'RS'),
            valor: Valor::reais('1.00'),
            vencimento: '2026-07-30',
            seuNumero: 'X',
        ));
        $this->fail('Esperava BancoApiException.');
    } catch (BancoApiException $e) {
        expect($e->statusHttp)->toBe(422)
            ->and($e->getMessage())->toContain('Instrução inválida');
    }
});

// --- Webhook de boleto (contrato) ---

it('registra um contrato de webhook de boleto', function () {
    Http::fake([
        '*/cobranca/boleto/v1/webhook/contrato/' => Http::response([
            'idContrato' => 'CT-1', 'url' => 'https://meuapp.com/webhooks/boleto',
            'eventos' => ['LIQUIDACAO'], 'contratoStatus' => 'ATIVO',
        ]),
    ]);

    $contrato = Bancos::driver('sicredi')->boleto()->registrarWebhook('https://meuapp.com/webhooks/boleto');

    expect($contrato->idContrato)->toBe('CT-1')->and($contrato->status)->toBe('ATIVO');

    Http::assertSent(function ($r) {
        if (! str_contains($r->url(), '/webhook/contrato/')) {
            return false;
        }
        $b = $r->data();

        return $r->method() === 'POST'
            && $b['url'] === 'https://meuapp.com/webhooks/boleto'
            && $b['codBeneficiario'] === '12345'
            && $b['cooperativa'] === '0512'
            && $b['eventos'] === ['LIQUIDACAO'];
    });
});

it('processa notificação de liquidação e dispara BoletoLiquidado', function () {
    Event::fake([BoletoLiquidado::class]);

    $payload = [
        'agencia' => '0512', 'posto' => '03', 'beneficiario' => '12345',
        'nossoNumero' => '251006142', 'movimento' => 'LIQUIDACAO_PIX',
        'valorLiquidacao' => '150.00', 'valorJuros' => '0.00',
    ];

    $rec = Bancos::driver('sicredi')->boleto()->processarNotificacao($payload);

    expect($rec)->toBeInstanceOf(RecebimentoBoleto::class)
        ->and($rec->nossoNumero)->toBe('251006142')
        ->and($rec->pago())->toBeTrue()
        ->and($rec->estornado())->toBeFalse()
        ->and($rec->valorPago->paraApi())->toBe('150.00');

    Event::assertDispatched(BoletoLiquidado::class, fn ($e) => $e->banco === 'sicredi'
        && $e->recebimento->nossoNumero === '251006142');
});

it('reconhece estorno na notificação', function () {
    $rec = Bancos::driver('sicredi')->boleto()->processarNotificacao([
        'nossoNumero' => '1', 'movimento' => 'ESTORNO_LIQUIDACAO_REDE', 'valorLiquidacao' => '10.00',
    ]);

    expect($rec->estornado())->toBeTrue()->and($rec->pago())->toBeFalse();
});
