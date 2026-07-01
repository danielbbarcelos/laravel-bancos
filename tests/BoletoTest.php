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

// --- Alteração de boleto emitido (comandos de instrução) ---

it('altera o vencimento via PATCH data-vencimento', function () {
    Http::fake(['*/cobranca/boleto/v1/boletos/251006142/data-vencimento' => Http::response([
        'transactionId' => 'tx-1', 'nossoNumero' => '251006142',
        'statusComando' => 'MOVIMENTO_ENVIADO', 'tipoMensagem' => 'ALTERA_VENCIMENTO',
    ])]);

    $instrucao = Bancos::driver('sicredi')->boleto()->alterarVencimento('251006142', '2026-09-30');

    expect($instrucao->enviado())->toBeTrue()
        ->and($instrucao->tipoMensagem)->toBe('ALTERA_VENCIMENTO')
        ->and($instrucao->nossoNumero)->toBe('251006142');

    Http::assertSent(fn ($r) => $r->method() === 'PATCH'
        && str_ends_with($r->url(), '/cobranca/boleto/v1/boletos/251006142/data-vencimento')
        && $r->data() === ['dataVencimento' => '2026-09-30']);
});

it('altera desconto expandindo valorDesconto1..3', function () {
    Http::fake(['*/cobranca/boleto/v1/boletos/*/desconto' => Http::response([
        'nossoNumero' => '251006142', 'statusComando' => 'MOVIMENTO_ENVIADO',
    ])]);

    Bancos::driver('sicredi')->boleto()->alterarDesconto('251006142', [10.0, 5.0]);

    Http::assertSent(fn ($r) => $r->method() === 'PATCH'
        && str_ends_with($r->url(), '/251006142/desconto')
        && $r->data() === ['valorDesconto1' => 10.0, 'valorDesconto2' => 5.0]);
});

it('altera data de desconto expandindo data1..3', function () {
    Http::fake(['*/cobranca/boleto/v1/boletos/*/data-desconto' => Http::response([
        'nossoNumero' => '251006142', 'statusComando' => 'MOVIMENTO_ENVIADO',
    ])]);

    Bancos::driver('sicredi')->boleto()->alterarDataDesconto('251006142', ['2026-08-01']);

    Http::assertSent(fn ($r) => $r->method() === 'PATCH'
        && str_ends_with($r->url(), '/251006142/data-desconto')
        && $r->data() === ['data1' => '2026-08-01']);
});

it('altera juros via valorOuPercentual', function () {
    Http::fake(['*/cobranca/boleto/v1/boletos/*/juros' => Http::response([
        'nossoNumero' => '251006142', 'statusComando' => 'MOVIMENTO_ENVIADO',
    ])]);

    Bancos::driver('sicredi')->boleto()->alterarJuros('251006142', '2.50');

    Http::assertSent(fn ($r) => $r->method() === 'PATCH'
        && str_ends_with($r->url(), '/251006142/juros')
        && $r->data() === ['valorOuPercentual' => '2.50']);
});

it('altera o seu número', function () {
    Http::fake(['*/cobranca/boleto/v1/boletos/*/seu-numero' => Http::response([
        'nossoNumero' => '251006142', 'statusComando' => 'MOVIMENTO_ENVIADO',
    ])]);

    Bancos::driver('sicredi')->boleto()->alterarSeuNumero('251006142', 'PEDIDO-99');

    Http::assertSent(fn ($r) => $r->method() === 'PATCH'
        && str_ends_with($r->url(), '/251006142/seu-numero')
        && $r->data() === ['seuNumero' => 'PEDIDO-99']);
});

// --- Listagem: boletos liquidados por dia ---

it('lista boletos liquidados por dia convertendo a data e mapeando itens', function () {
    Http::fake(['*/cobranca/boleto/v1/boletos/liquidados/dia*' => Http::response([
        'items' => [
            [
                'nossoNumero' => '251006142', 'seuNumero' => 'PEDIDO-42',
                'dataPagamento' => '2026-07-30', 'valor' => 150.00, 'valorLiquidado' => 148.00,
                'jurosLiquido' => 0.00, 'descontoLiquido' => 2.00, 'multaLiquida' => 0.00,
                'tipoLiquidacao' => 'PIX', 'tipoCarteira' => 'CARTEIRA_SIMPLES',
            ],
        ],
        'hasNext' => 'false',
    ])]);

    $pagina = Bancos::driver('sicredi')->boleto()->listarLiquidados('2026-07-30');

    expect($pagina->temProxima)->toBeFalse()
        ->and($pagina->pagina)->toBe(1)
        ->and($pagina->itens)->toHaveCount(1)
        ->and($pagina->itens[0]->nossoNumero)->toBe('251006142')
        ->and($pagina->itens[0]->tipoLiquidacao)->toBe('PIX')
        ->and($pagina->itens[0]->valorLiquidado->paraApi())->toBe('148.00')
        ->and($pagina->itens[0]->desconto->paraApi())->toBe('2.00');

    // dia canônico YYYY-MM-DD é convertido para DD/MM/YYYY na query.
    Http::assertSent(fn ($r) => $r->method() === 'GET'
        && str_contains($r->url(), '/boletos/liquidados/dia')
        && str_contains(urldecode($r->url()), 'dia=30/07/2026')
        && str_contains($r->url(), 'codigoBeneficiario=12345')
        && str_contains($r->url(), 'pagina=1'));
});

it('encaminha beneficiário final e página na consulta de liquidados', function () {
    Http::fake(['*/cobranca/boleto/v1/boletos/liquidados/dia*' => Http::response([
        'items' => [], 'hasNext' => 'true',
    ])]);

    $pagina = Bancos::driver('sicredi')->boleto()->listarLiquidados('2026-07-30', '12345678000199', 2);

    expect($pagina->temProxima)->toBeTrue()->and($pagina->pagina)->toBe(2);

    Http::assertSent(fn ($r) => str_contains($r->url(), 'cpfCnpjBeneficiarioFinal=12345678000199')
        && str_contains($r->url(), 'pagina=2'));
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
