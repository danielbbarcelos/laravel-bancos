<?php

declare(strict_types=1);

use DanielBBarcelos\Bancos\Data\Pix\CobrancaImediata;
use DanielBBarcelos\Bancos\Data\Shared\Valor;
use DanielBBarcelos\Bancos\Facades\Bancos;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    Http::fake([
        '*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
        '*/api/v3/cob/*' => Http::response(['txid' => 'X', 'status' => 'ATIVA', 'valor' => ['original' => '1.00']]),
        '*/api/v3/cob' => Http::response(['txid' => 'T', 'status' => 'ATIVA', 'valor' => ['original' => '1.00']]),
    ]);
});

it('mescla overrides do tenant sobre os defaults do config base', function () {
    // base_url e scopes vêm do bloco "sicredi" do config; o tenant troca client_id e chave.
    $banco = Bancos::build('sicredi', [
        'client_id' => 'tenant-A',
        'client_secret' => 'segredo-A',
        'chave_pix' => 'chave-tenant-A@pix.com',
    ]);

    $banco->pix()->cobrancaImediata(new CobrancaImediata(valor: Valor::reais('1.00')));

    Http::assertSent(fn ($r) => str_contains($r->url(), 'api-pix-h.sicredi.com.br')); // base do config
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/v3/cob')
        && $r->data()['chave'] === 'chave-tenant-A@pix.com');
});

it('NÃO compartilha token entre tenants com credenciais diferentes', function () {
    // Mesmo driver "sicredi", clientes distintos: cada um deve emitir o próprio token.
    Bancos::build('sicredi', ['client_id' => 'tenant-A', 'client_secret' => 's'])
        ->pix()->consultarCobranca('X');

    Bancos::build('sicredi', ['client_id' => 'tenant-B', 'client_secret' => 's'])
        ->pix()->consultarCobranca('X');

    // 2 tokens (um por tenant) + 2 consultas = nenhum reaproveitamento indevido.
    Http::assertSentCount(4);
});

it('reaproveita o token quando o mesmo tenant chama de novo', function () {
    $cfg = ['client_id' => 'tenant-A', 'client_secret' => 's'];

    Bancos::build('sicredi', $cfg)->pix()->consultarCobranca('X');
    Bancos::build('sicredi', $cfg)->pix()->consultarCobranca('X');

    // 1 token (cacheado por credencial) + 2 consultas.
    Http::assertSentCount(3);
});

it('isola o cache por cache_key explícito mesmo com o mesmo client', function () {
    $base = ['client_id' => 'mesmo', 'client_secret' => 's'];

    Bancos::build('sicredi', $base + ['cache_key' => 'empresa:1'])->pix()->consultarCobranca('X');
    Bancos::build('sicredi', $base + ['cache_key' => 'empresa:2'])->pix()->consultarCobranca('X');

    // cache_key diferente => 2 tokens + 2 consultas.
    Http::assertSentCount(4);
});
