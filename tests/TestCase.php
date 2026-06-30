<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Tests;

use DanielBBarcelos\Bancos\BancosServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [BancosServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Necessário para o Crypt (token cifrado no cache).
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

        $app['config']->set('bancos.default', 'sicredi');
        $app['config']->set('bancos.drivers.sicredi', [
            'driver' => 'sicredi',
            'client_id' => 'cliente-teste',
            'client_secret' => 'segredo-teste',
            'chave_pix' => 'chave@exemplo.com',
            'certificado' => null, // sem mTLS nos testes
            'base_url' => 'https://api-pix-h.sicredi.com.br',
            'timeout' => 10,
            'retry_intervalo_ms' => 0, // sem sleep real nos testes de retry
            'boleto' => [
                'x_api_key' => 'api-key-teste',
                'username' => '123456789',
                'password' => 'codigo-acesso-teste',
                'cooperativa' => '0512',
                'posto' => '03',
                'codigo_beneficiario' => '12345',
                'base_url' => 'https://api-parceiro.sicredi.com.br/sb',
            ],
        ]);

        $app['config']->set('bancos.drivers.c6', [
            'driver' => 'c6',
            'client_id' => 'c6-cliente',
            'client_secret' => 'c6-segredo',
            'chave_pix' => 'chave-c6@exemplo.com',
            'certificado' => null,
            'base_url' => 'https://baas-api-pix.c6bank.info',
            'timeout' => 10,
        ]);
    }
}
