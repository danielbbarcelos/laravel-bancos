<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Support;

use DanielBBarcelos\Bancos\Exceptions\BancoException;

/**
 * Validação de URLs sensíveis. Exigir https evita MITM: credenciais e tokens
 * trafegariam em claro sobre http. O bypass só deve ser usado em dev local.
 */
final class Url
{
    public static function exigirHttps(string $url, bool $permitirHttp = false): string
    {
        $url = trim($url);

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new BancoException("URL inválida: [{$url}].");
        }

        $esquema = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($esquema === 'https') {
            return $url;
        }

        if ($esquema === 'http' && $permitirHttp) {
            return $url; // somente quando explicitamente permitido (dev)
        }

        throw new BancoException(
            "URL insegura [{$url}]: exige https (defina 'permitir_http' => true apenas em dev)."
        );
    }
}
