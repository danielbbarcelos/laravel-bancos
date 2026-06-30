<?php

declare(strict_types=1);

use DanielBBarcelos\Bancos\Data\Shared\Valor;

it('formata reais no padrão da API Pix', function () {
    expect(Valor::reais('150')->paraApi())->toBe('150.00')
        ->and(Valor::reais('150,5')->paraApi())->toBe('150.50')
        ->and(Valor::reais(3.0)->paraApi())->toBe('3.00')
        ->and(Valor::centavos(12345)->paraApi())->toBe('123.45');
});

it('guarda centavos como inteiro sem perda de precisão', function () {
    expect(Valor::reais('0.30')->centavos)->toBe(30)
        ->and(Valor::reais('123.45')->centavos)->toBe(12345);
});

it('rejeita valor negativo', function () {
    Valor::reais('-1.00');
})->throws(InvalidArgumentException::class);
