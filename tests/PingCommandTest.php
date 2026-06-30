<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

beforeEach(fn () => Http::preventStrayRequests());

it('passa quando a autenticação funciona', function () {
    Http::fake([
        '*/oauth/token' => Http::response([
            'access_token' => 'tok',
            'expires_in' => 3599,
            'scope' => 'cob.read cob.write',
        ]),
    ]);

    $this->artisan('bancos:ping sicredi')
        ->expectsOutputToContain('Credenciais e base_url presentes')
        ->expectsOutputToContain('Autenticação com [sicredi] bem-sucedida')
        ->assertExitCode(0);
});

it('avisa sobre certificado mTLS ausente sem abortar', function () {
    // A config de teste tem certificado = null.
    Http::fake(['*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3599])]);

    $this->artisan('bancos:ping sicredi')
        ->expectsOutputToContain('Certificado mTLS não configurado')
        ->assertExitCode(0);
});

it('falha e orienta sobre certificado quando o PSP retorna 403', function () {
    Http::fake(['*/oauth/token' => Http::response(['detail' => 'mTLS requerido'], 403)]);

    $this->artisan('bancos:ping sicredi')
        ->expectsOutputToContain('mTLS')
        ->assertExitCode(1);
});

it('aborta sem tocar a rede quando faltam credenciais', function () {
    config()->set('bancos.drivers.sem_credenciais', [
        'driver' => 'sicredi',
        'client_id' => null,
        'client_secret' => null,
        'base_url' => 'https://api-pix.sicredi.com.br',
    ]);

    Http::fake(); // qualquer chamada de rede falharia o teste (preventStrayRequests)

    $this->artisan('bancos:ping sem_credenciais')
        ->expectsOutputToContain('Config obrigatória ausente: client_id')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('emite e consulta uma cobrança com --cobranca', function () {
    Http::fake([
        '*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
        '*/api/v3/cob' => Http::response(['txid' => 'PINGTXID', 'status' => 'ATIVA', 'valor' => ['original' => '0.01'], 'pixCopiaECola' => '00020...']),
        '*/api/v3/cob/PINGTXID' => Http::response(['txid' => 'PINGTXID', 'status' => 'ATIVA', 'valor' => ['original' => '0.01']]),
    ]);

    $this->artisan('bancos:ping sicredi --cobranca')
        ->expectsOutputToContain('Cobrança criada. txid: PINGTXID')
        ->expectsOutputToContain('Consulta OK')
        ->assertExitCode(0);
});
