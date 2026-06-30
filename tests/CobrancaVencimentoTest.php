<?php

declare(strict_types=1);

use DanielBBarcelos\Bancos\Data\Pix\CobrancaComVencimento;
use DanielBBarcelos\Bancos\Data\Pix\Desconto;
use DanielBBarcelos\Bancos\Data\Pix\DescontoData;
use DanielBBarcelos\Bancos\Data\Pix\Juros;
use DanielBBarcelos\Bancos\Data\Pix\Multa;
use DanielBBarcelos\Bancos\Data\Shared\Pessoa;
use DanielBBarcelos\Bancos\Data\Shared\Valor;
use DanielBBarcelos\Bancos\Enums\StatusCobranca;
use DanielBBarcelos\Bancos\Facades\Bancos;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake([
        '*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
    ]);
});

it('cria cobrança com vencimento via PUT com multa, juros e desconto', function () {
    Http::fake([
        '*/api/v2/cobv/*' => Http::response([
            'txid' => 'fatura2026070042000000000001',
            'status' => 'ATIVA',
            'calendario' => ['criacao' => '2026-06-30T12:00:00Z', 'dataDeVencimento' => '2026-07-30'],
            'valor' => ['original' => '0.30'],
            'chave' => 'chave@exemplo.com',
            'pixCopiaECola' => '00020...cobv',
        ]),
    ]);

    $cobranca = Bancos::pix()->cobrancaComVencimento(new CobrancaComVencimento(
        txid: 'fatura2026070042000000000001',
        valor: Valor::reais('0.30'),
        vencimento: '2026-07-30',
        validadeAposVencimento: 0,
        devedor: new Pessoa(nome: 'Fulano de Tal', documento: '000.123.123-12'),
        multa: new Multa(modalidade: 2, valorPerc: '15.00'),
        juros: new Juros(modalidade: 2, valorPerc: '2.00'),
        desconto: new Desconto(modalidade: 2, datasFixas: [
            new DescontoData(data: '2026-07-20', valorPerc: '30.00'),
        ]),
    ));

    expect($cobranca->txid)->toBe('fatura2026070042000000000001')
        ->and($cobranca->status)->toBe(StatusCobranca::Ativa)
        ->and($cobranca->vencimento)->toBe('2026-07-30')
        ->and($cobranca->qrCode)->toBe('00020...cobv');

    // De-para fiel ao payload da collection oficial do Sicredi.
    Http::assertSent(function ($r) {
        $b = $r->data();

        return $r->method() === 'PUT'
            && str_ends_with($r->url(), '/api/v2/cobv/fatura2026070042000000000001')
            && $b['calendario']['dataDeVencimento'] === '2026-07-30'
            && $b['calendario']['validadeAposVencimento'] === 0
            && $b['valor']['original'] === '0.30'
            && $b['valor']['multa'] === ['modalidade' => 2, 'valorPerc' => '15.00']
            && $b['valor']['juros'] === ['modalidade' => 2, 'valorPerc' => '2.00']
            && $b['valor']['desconto']['modalidade'] === 2
            && $b['valor']['desconto']['descontoDataFixa'][0] === ['data' => '2026-07-20', 'valorPerc' => '30.00']
            && $b['devedor']['cpf'] === '00012312312'
            && $b['chave'] === 'chave@exemplo.com';
    });
});

it('cria cobv mínima (sem encargos) e omite campos opcionais', function () {
    Http::fake(['*/api/v2/cobv/*' => Http::response(['txid' => 'x', 'status' => 'ATIVA', 'valor' => ['original' => '50.00']])]);

    Bancos::pix()->cobrancaComVencimento(new CobrancaComVencimento(
        txid: 'cobvminima0000000000000000001',
        valor: Valor::reais('50.00'),
        vencimento: '2026-12-31',
    ));

    Http::assertSent(function ($r) {
        if (! str_contains($r->url(), '/api/v2/cobv/')) {
            return false; // ignora a requisição do token
        }

        $b = $r->data();

        return ! array_key_exists('multa', $b['valor'])
            && ! array_key_exists('juros', $b['valor'])
            && ! array_key_exists('desconto', $b['valor'])
            && ! array_key_exists('devedor', $b);
    });
});

it('consulta uma cobrança com vencimento pelo txid', function () {
    Http::fake([
        '*/api/v2/cobv/*' => Http::response([
            'txid' => 'cobv-001',
            'status' => 'CONCLUIDA',
            'calendario' => ['dataDeVencimento' => '2026-07-30'],
            'valor' => ['original' => '0.30'],
        ]),
    ]);

    $cobranca = Bancos::pix()->consultarCobrancaVencimento('cobv-001');

    expect($cobranca->status)->toBe(StatusCobranca::Concluida)
        ->and($cobranca->paga())->toBeTrue()
        ->and($cobranca->vencimento)->toBe('2026-07-30');

    Http::assertSent(fn ($r) => $r->method() === 'GET'
        && str_ends_with($r->url(), '/api/v2/cobv/cobv-001'));
});

it('lista cobranças com vencimento num intervalo', function () {
    Http::fake([
        '*/api/v2/cobv?*' => Http::response([
            'parametros' => ['inicio' => '2026-06-01T00:00:00Z', 'fim' => '2026-07-31T23:59:59Z'],
            'cobs' => [
                ['txid' => 'A', 'status' => 'ATIVA', 'valor' => ['original' => '10.00'], 'calendario' => ['dataDeVencimento' => '2026-07-10']],
                ['txid' => 'B', 'status' => 'CONCLUIDA', 'valor' => ['original' => '20.00'], 'calendario' => ['dataDeVencimento' => '2026-07-20']],
            ],
        ]),
    ]);

    $lista = Bancos::pix()->listarCobrancasVencimento('2026-06-01T00:00:00Z', '2026-07-31T23:59:59Z');

    expect($lista)->toHaveCount(2)
        ->and($lista[0]->vencimento)->toBe('2026-07-10')
        ->and($lista[1]->paga())->toBeTrue();

    Http::assertSent(fn ($r) => $r->method() === 'GET' && str_contains($r->url(), '/api/v2/cobv?'));
});

it('revisa uma cobrança com vencimento via PATCH', function () {
    Http::fake([
        '*/api/v2/cobv/*' => Http::response([
            'txid' => 'fatura2026070042000000000001',
            'status' => 'ATIVA',
            'valor' => ['original' => '120.00'],
            'calendario' => ['dataDeVencimento' => '2026-07-30'],
        ]),
    ]);

    $cobranca = Bancos::pix()->revisarCobrancaVencimento('fatura2026070042000000000001', [
        'valor' => ['original' => '120.00'],
    ]);

    expect($cobranca->valor->paraApi())->toBe('120.00');

    Http::assertSent(fn ($r) => $r->method() === 'PATCH'
        && str_ends_with($r->url(), '/api/v2/cobv/fatura2026070042000000000001')
        && $r->data() === ['valor' => ['original' => '120.00']]);
});

it('inclui os scopes cobv no token', function () {
    Http::fake(['*/api/v2/cobv/*' => Http::response(['txid' => 'x', 'status' => 'ATIVA', 'valor' => ['original' => '1.00']])]);

    Bancos::pix()->consultarCobrancaVencimento('x');

    Http::assertSent(fn ($r) => ! str_contains($r->url(), '/oauth/token')
        || (str_contains($r['scope'], 'cobv.write') && str_contains($r['scope'], 'cobv.read')));
});
