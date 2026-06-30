<?php

declare(strict_types=1);

use DanielBBarcelos\Bancos\Data\Pix\CobrancaImediata;
use DanielBBarcelos\Bancos\Data\Shared\Valor;
use DanielBBarcelos\Bancos\Enums\StatusCobranca;
use DanielBBarcelos\Bancos\Facades\Bancos;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();

    Http::fake([
        '*/v1/auth/oauth/token' => Http::response([
            'access_token' => 'c6-token',
            'expires_in' => 3600,
        ]),
    ]);
});

it('roda o mesmo contrato canônico no C6, só mudando rota e auth', function () {
    Http::fake([
        '*/v2/cob' => Http::response([
            'txid' => 'C6TXID',
            'status' => 'ATIVA',
            'valor' => ['original' => '99.90'],
            'pixCopiaECola' => '00020...c6',
        ]),
    ]);

    // Código idêntico ao do Sicredi — só muda o driver selecionado.
    $cobranca = Bancos::driver('c6')->pix()->cobrancaImediata(new CobrancaImediata(
        valor: Valor::reais('99.90'),
    ));

    expect($cobranca->txid)->toBe('C6TXID')
        ->and($cobranca->status)->toBe(StatusCobranca::Ativa)
        ->and($cobranca->valor->paraApi())->toBe('99.90');

    // Prova do de-para por banco: C6 usa /v2/cob (Sicredi usaria /api/v3/cob),
    // chave default própria, e autentica no endpoint do C6.
    Http::assertSent(fn ($r) => $r->method() === 'POST'
        && str_ends_with($r->url(), '/v2/cob')
        && $r->data()['chave'] === 'chave-c6@exemplo.com');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/auth/oauth/token'));
});
