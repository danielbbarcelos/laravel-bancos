<?php

declare(strict_types=1);

use DanielBBarcelos\Bancos\Data\Pix\CobrancaComVencimento;
use DanielBBarcelos\Bancos\Data\Pix\CobrancaImediata;
use DanielBBarcelos\Bancos\Exceptions\BancoApiException;
use DanielBBarcelos\Bancos\Exceptions\BancoException;
use DanielBBarcelos\Bancos\Facades\Bancos;
use DanielBBarcelos\Bancos\Support\Txid;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake([
        '*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
    ]);
});

// --- Listar cobranças imediatas ---

it('lista cobranças imediatas num intervalo', function () {
    Http::fake([
        '*/api/v3/cob?*' => Http::response([
            'parametros' => ['inicio' => '2026-06-01T00:00:00Z', 'fim' => '2026-06-30T23:59:59Z'],
            'cobs' => [
                ['txid' => 'A', 'status' => 'ATIVA', 'valor' => ['original' => '10.00']],
                ['txid' => 'B', 'status' => 'CONCLUIDA', 'valor' => ['original' => '20.00']],
            ],
        ]),
    ]);

    $lista = Bancos::pix()->listarCobrancas('2026-06-01T00:00:00Z', '2026-06-30T23:59:59Z', ['status' => 'ATIVA']);

    expect($lista)->toHaveCount(2)
        ->and($lista[0]->txid)->toBe('A')
        ->and($lista[1]->paga())->toBeTrue();

    Http::assertSent(fn ($r) => $r->method() === 'GET'
        && str_contains($r->url(), '/api/v3/cob?')
        && str_contains($r->url(), 'status=ATIVA'));
});

// --- Retry com backoff ---

it('re-tenta em erro 5xx transitório e tem sucesso', function () {
    Http::fake([
        '*/api/v3/cob/*' => Http::sequence()
            ->push(['erro' => 'instabilidade'], 503)        // 1ª tentativa: falha transitória
            ->push(['txid' => 'A', 'status' => 'ATIVA', 'valor' => ['original' => '1.00']], 200),
    ]);

    $cobranca = Bancos::pix()->consultarCobranca('A');

    expect($cobranca->txid)->toBe('A');
    Http::assertSentCount(3); // 1 token + 2 tentativas (503, 200)
});

it('NÃO re-tenta em erro 4xx (vira BancoApiException direto)', function () {
    Http::fake([
        '*/api/v3/cob/*' => Http::sequence()
            ->push(['title' => 'Não encontrada'], 404)
            ->push(['txid' => 'A', 'status' => 'ATIVA', 'valor' => ['original' => '1.00']], 200),
    ]);

    try {
        Bancos::pix()->consultarCobranca('A');
        $this->fail('Esperava BancoApiException.');
    } catch (BancoApiException $e) {
        expect($e->statusHttp)->toBe(404);
    }

    // Só 1 tentativa de cobrança (não re-tentou o 404), além do token.
    Http::assertSentCount(2);
});

// --- Validação de txid ---

it('valida o formato do txid (regra BACEN 26–35 alfanuméricos)', function () {
    expect(Txid::valido('pedido0042idempotente00000001'))->toBeTrue()    // 29 chars
        ->and(Txid::valido('curto'))->toBeFalse()
        ->and(Txid::valido('com-hifen-aaaaaaaaaaaaaaaaaaaaa'))->toBeFalse();
});

it('rejeita cobrança imediata com txid inválido antes de chamar a API', function () {
    Bancos::pix()->cobrancaImediata(new CobrancaImediata(
        valor: \DanielBBarcelos\Bancos\Data\Shared\Valor::reais('1.00'),
        txid: 'txid-invalido',
    ));
})->throws(BancoException::class, 'txid inválido');

it('rejeita cobv com txid inválido', function () {
    Bancos::pix()->cobrancaComVencimento(new CobrancaComVencimento(
        txid: 'curto',
        valor: \DanielBBarcelos\Bancos\Data\Shared\Valor::reais('1.00'),
        vencimento: '2026-12-31',
    ));
})->throws(BancoException::class, 'txid inválido');
