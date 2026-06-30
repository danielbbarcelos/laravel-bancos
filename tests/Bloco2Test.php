<?php

declare(strict_types=1);

use DanielBBarcelos\Bancos\Data\Pix\Devolucao;
use DanielBBarcelos\Bancos\Data\Pix\Recebimento;
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

it('cancela uma cobrança via PATCH com status REMOVIDA', function () {
    Http::fake([
        '*/api/v3/cob/*' => Http::response([
            'txid' => 'TXID123',
            'status' => 'REMOVIDA_PELO_USUARIO_RECEBEDOR',
            'valor' => ['original' => '150.00'],
        ]),
    ]);

    $cobranca = Bancos::pix()->cancelarCobranca('TXID123');

    expect($cobranca->status)->toBe(StatusCobranca::RemovidaPeloUsuario);

    Http::assertSent(fn ($r) => $r->method() === 'PATCH'
        && str_ends_with($r->url(), '/api/v3/cob/TXID123')
        && $r->data() === ['status' => 'REMOVIDA_PELO_USUARIO_RECEBEDOR']);
});

it('consulta um Pix recebido pelo endToEndId', function () {
    Http::fake([
        '*/api/v2/pix/E2E123' => Http::response([
            'endToEndId' => 'E2E123',
            'txid' => 'TXID123',
            'valor' => '150.00',
            'horario' => '2026-06-30T12:00:00Z',
            'pagador' => ['cpf' => '00012312312', 'nome' => 'Fulano'],
        ]),
    ]);

    $pix = Bancos::pix()->consultarPix('E2E123');

    expect($pix)->toBeInstanceOf(Recebimento::class)
        ->and($pix->endToEndId)->toBe('E2E123')
        ->and($pix->txid)->toBe('TXID123')
        ->and($pix->valor->paraApi())->toBe('150.00')
        ->and($pix->pagadorDocumento)->toBe('00012312312');

    Http::assertSent(fn ($r) => $r->method() === 'GET'
        && str_ends_with($r->url(), '/api/v2/pix/E2E123'));
});

it('lista Pix recebidos num intervalo', function () {
    Http::fake([
        '*/api/v2/pix?*' => Http::response([
            'parametros' => ['inicio' => '2026-06-01T00:00:00Z', 'fim' => '2026-06-30T23:59:59Z'],
            'pix' => [
                ['endToEndId' => 'E1', 'valor' => '10.00'],
                ['endToEndId' => 'E2', 'valor' => '20.00', 'txid' => 'T2'],
            ],
        ]),
    ]);

    $lista = Bancos::pix()->listarPixRecebidos('2026-06-01T00:00:00Z', '2026-06-30T23:59:59Z');

    expect($lista)->toHaveCount(2)
        ->and($lista[0]->endToEndId)->toBe('E1')
        ->and($lista[1]->txid)->toBe('T2');

    Http::assertSent(fn ($r) => $r->method() === 'GET'
        && str_contains($r->url(), 'inicio=2026-06-01T00%3A00%3A00Z')
        && str_contains($r->url(), 'fim='));
});

it('consulta o status de uma devolução', function () {
    Http::fake([
        '*/api/v2/pix/*/devolucao/*' => Http::response([
            'id' => 'dev-1',
            'rtrId' => 'RTR123',
            'valor' => '5.00',
            'status' => 'DEVOLVIDO',
        ]),
    ]);

    $devolucao = Bancos::pix()->consultarDevolucao('E2E123', 'dev-1');

    expect($devolucao)->toBeInstanceOf(Devolucao::class)
        ->and($devolucao->status)->toBe('DEVOLVIDO')
        ->and($devolucao->valor->paraApi())->toBe('5.00');

    Http::assertSent(fn ($r) => $r->method() === 'GET'
        && str_ends_with($r->url(), '/api/v2/pix/E2E123/devolucao/dev-1'));
});
