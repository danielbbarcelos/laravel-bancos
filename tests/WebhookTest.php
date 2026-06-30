<?php

declare(strict_types=1);

use DanielBBarcelos\Bancos\Data\Pix\Recebimento;
use DanielBBarcelos\Bancos\Events\PixRecebido;
use DanielBBarcelos\Bancos\Facades\Bancos;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake([
        '*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
    ]);
});

it('configura o webhook na chave Pix padrão da config', function () {
    Http::fake(['*/api/v2/webhook/*' => Http::response([], 204)]);

    Bancos::pix()->configurarWebhook('https://meuapp.com/webhooks/pix');

    Http::assertSent(fn ($r) => $r->method() === 'PUT'
        // chave padrão "chave@exemplo.com" vem do TestCase
        && str_ends_with($r->url(), '/api/v2/webhook/chave@exemplo.com')
        && $r->data() === ['webhookUrl' => 'https://meuapp.com/webhooks/pix']);
});

it('consulta o webhook e mapeia para o DTO', function () {
    Http::fake([
        '*/api/v2/webhook/*' => Http::response([
            'webhookUrl' => 'https://meuapp.com/webhooks/pix',
            'chave' => 'chave@exemplo.com',
            'criacao' => '2026-06-30T12:00:00Z',
        ]),
    ]);

    $webhook = Bancos::pix()->consultarWebhook();

    expect($webhook)->not->toBeNull()
        ->and($webhook->url)->toBe('https://meuapp.com/webhooks/pix')
        ->and($webhook->chave)->toBe('chave@exemplo.com');
});

it('devolve null quando não há webhook (404)', function () {
    Http::fake(['*/api/v2/webhook/*' => Http::response(['detail' => 'não encontrado'], 404)]);

    expect(Bancos::pix()->consultarWebhook())->toBeNull();
});

it('cancela o webhook via DELETE', function () {
    Http::fake(['*/api/v2/webhook/*' => Http::response([], 204)]);

    Bancos::pix()->cancelarWebhook();

    Http::assertSent(fn ($r) => $r->method() === 'DELETE'
        && str_ends_with($r->url(), '/api/v2/webhook/chave@exemplo.com'));
});

it('processa a notificação, dispara PixRecebido por item e devolve a lista', function () {
    Event::fake([PixRecebido::class]);

    // Payload no formato BACEN ({"pix": [...]}).
    $payload = [
        'pix' => [
            [
                'endToEndId' => 'E00038166201907091400',
                'txid' => 'TXID123',
                'valor' => '150.00',
                'chave' => 'chave@exemplo.com',
                'horario' => '2026-06-30T12:00:00Z',
                'infoPagador' => 'Pedido 42',
                'pagador' => ['cpf' => '00012312312', 'nome' => 'Fulano'],
            ],
            [
                'endToEndId' => 'E00038166201907091401',
                'valor' => '10.00', // Pix sem cobrança: sem txid
            ],
        ],
    ];

    $recebidos = Bancos::pix()->processarNotificacao($payload);

    expect($recebidos)->toHaveCount(2)
        ->and($recebidos[0])->toBeInstanceOf(Recebimento::class)
        ->and($recebidos[0]->txid)->toBe('TXID123')
        ->and($recebidos[0]->valor->paraApi())->toBe('150.00')
        ->and($recebidos[0]->pagadorDocumento)->toBe('00012312312')
        ->and($recebidos[1]->txid)->toBeNull();

    Event::assertDispatchedTimes(PixRecebido::class, 2);
    Event::assertDispatched(PixRecebido::class, fn ($e) => $e->banco === 'sicredi'
        && $e->pix->endToEndId === 'E00038166201907091400');
});

it('exige chave Pix quando não há padrão configurado', function () {
    config()->set('bancos.drivers.sem_chave', [
        'driver' => 'sicredi',
        'client_id' => 'x',
        'client_secret' => 'y',
        'base_url' => 'https://api-pix-h.sicredi.com.br',
        // sem chave_pix
    ]);

    Bancos::driver('sem_chave')->pix()->configurarWebhook('https://meuapp.com/pix');
})->throws(DanielBBarcelos\Bancos\Exceptions\BancoException::class);
