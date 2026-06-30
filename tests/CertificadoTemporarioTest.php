<?php

declare(strict_types=1);

use DanielBBarcelos\Bancos\Support\CertificadoTemporario;

it('materializa o PEM num arquivo 0600 e o remove ao destruir', function () {
    $pem = "-----BEGIN CERTIFICATE-----\nconteudo-fake\n-----END CERTIFICATE-----\n";

    $cert = CertificadoTemporario::materializar($pem);
    $caminho = $cert->caminho;

    expect(is_file($caminho))->toBeTrue()
        ->and(file_get_contents($caminho))->toBe($pem)
        // permissão 0600 (só dono lê/escreve)
        ->and(substr(sprintf('%o', fileperms($caminho)), -3))->toBe('600');

    unset($cert); // dispara o __destruct

    expect(is_file($caminho))->toBeFalse();
});
