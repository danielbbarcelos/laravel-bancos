<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Support;

/**
 * Mascara valores sensíveis de um array de config para exibição em __debugInfo(),
 * impedindo que credenciais apareçam em dd()/var_dump()/dumps de objeto.
 */
final class Segredo
{
    /** Chaves cujo valor nunca deve ser exibido. */
    private const SENSIVEIS = [
        'client_secret',
        'password',
        'x_api_key',
        'senha_cert',
        'chave_privada',
        'certificado',
    ];

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function mascarar(array $config): array
    {
        foreach ($config as $chave => $valor) {
            if (is_array($valor)) {
                $config[$chave] = self::mascarar($valor);

                continue;
            }

            if (in_array($chave, self::SENSIVEIS, true) && $valor !== null && $valor !== '') {
                $config[$chave] = '***';
            }
        }

        return $config;
    }
}
