<?php

declare(strict_types=1);

use DanielBBarcelos\Bancos\Facades\Bancos;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Http::preventStrayRequests());

it('reporta sucesso com expiração e scopes quando autentica', function () {
    Http::fake([
        '*/oauth/token' => Http::response([
            'access_token' => 'tok',
            'token_type' => 'Bearer',
            'expires_in' => 3599,
            'scope' => 'cob.read cob.write pix.read',
        ]),
    ]);

    $status = Bancos::driver('sicredi')->verificarConexao();

    expect($status->ok)->toBeTrue()
        ->and($status->expiraEm)->toBe(3599)
        ->and($status->scopes)->toContain('cob.write');
});

it('traduz 403 como problema de certificado mTLS', function () {
    Http::fake(['*/oauth/token' => Http::response(['detail' => 'mTLS requerido'], 403)]);

    $status = Bancos::driver('sicredi')->verificarConexao();

    expect($status->ok)->toBeFalse()
        ->and($status->dica)->toContain('mTLS');
});

it('traduz 401 como credenciais inválidas', function () {
    Http::fake(['*/oauth/token' => Http::response(['detail' => 'token inválido'], 401)]);

    $status = Bancos::driver('sicredi')->verificarConexao();

    expect($status->ok)->toBeFalse()
        ->and($status->dica)->toContain('client_id');
});
