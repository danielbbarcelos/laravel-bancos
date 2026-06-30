<?php

declare(strict_types=1);

use DanielBBarcelos\Bancos\Exceptions\BancoException;
use DanielBBarcelos\Bancos\Facades\Bancos;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    Http::fake([
        '*/oauth/token' => Http::response(['access_token' => 'segredo-token', 'expires_in' => 3599]),
        '*/api/v3/cob/*' => Http::response(['txid' => 'A', 'status' => 'ATIVA', 'valor' => ['original' => '1.00']]),
    ]);
});

// --- Mascaramento de credenciais ---

it('não expõe credenciais em var_export/__debugInfo do banco', function () {
    $banco = Bancos::build('sicredi', [
        'client_id' => 'cliente-x',
        'client_secret' => 'SEGREDO-SUPER',
        'base_url' => 'https://api-pix.sicredi.com.br',
    ]);

    // var_dump() e dd()/dump() (Symfony VarDumper) respeitam __debugInfo().
    ob_start();
    var_dump($banco);
    $dump = (string) ob_get_clean();

    expect($dump)->not->toContain('SEGREDO-SUPER')
        ->and($dump)->toContain('***')
        ->and($dump)->toContain('api-pix.sicredi.com.br'); // base_url não é segredo
});

// --- HTTPS forçado (anti-MITM) ---

it('rejeita base_url http:// (anti-MITM)', function () {
    Bancos::build('sicredi', [
        'client_id' => 'x', 'client_secret' => 'y',
        'base_url' => 'http://api-pix.sicredi.com.br',
    ])->pix()->consultarCobranca('TXID0000000000000000000000001');
})->throws(BancoException::class, 'exige https');

it('permite http quando permitir_http=true (dev)', function () {
    Http::fake([
        'http://local.test/*' => Http::response(['access_token' => 't', 'expires_in' => 3599]),
    ]);
    Http::fake([
        'http://local.test/api/v3/cob/*' => Http::response(['txid' => 'A', 'status' => 'ATIVA', 'valor' => ['original' => '1.00']]),
    ]);

    $cobranca = Bancos::build('sicredi', [
        'client_id' => 'x', 'client_secret' => 'y',
        'base_url' => 'http://local.test', 'permitir_http' => true,
    ])->pix()->consultarCobranca('A');

    expect($cobranca->txid)->toBe('A');
});

// --- Token cifrado no cache ---

it('guarda o token cifrado no cache (não em texto) e o reutiliza', function () {
    Bancos::pix()->consultarCobranca('A'); // dispara auth + cacheia

    // Chave derivada como em ClienteHttpBacen::chaveCacheToken() para a conexão de teste.
    $chave = 'bancos:token:'.sha1('sicredi|cliente-teste|https://api-pix-h.sicredi.com.br');
    $cacheado = Cache::get($chave);

    expect($cacheado)->not->toBeNull()
        ->and($cacheado)->not->toBe('segredo-token')          // não está em texto
        ->and(Crypt::decrypt($cacheado))->toBe('segredo-token'); // mas decifra no token

    // Reconsulta reaproveita o cache (só 1 emissão de token).
    Bancos::pix()->consultarCobranca('A');
    Http::assertSentCount(3); // 1 token + 2 consultas
});

// --- Webhook https (anti-SSRF) ---

it('rejeita webhookUrl não-https', function () {
    Bancos::pix()->configurarWebhook('http://meuapp.com/webhooks/pix');
})->throws(BancoException::class, 'exige https');

it('rejeita webhookUrl malformada', function () {
    Bancos::pix()->configurarWebhook('nao-e-url');
})->throws(BancoException::class);

// --- Limite de payload de webhook (anti-DoS) ---

it('rejeita notificação acima do limite de itens', function () {
    config()->set('bancos.drivers.sicredi.webhook_max_itens', 2);

    $payload = ['pix' => array_fill(0, 3, ['endToEndId' => 'E', 'valor' => '1.00'])];

    Bancos::driver('sicredi')->pix()->processarNotificacao($payload);
})->throws(BancoException::class, 'excede o limite');
