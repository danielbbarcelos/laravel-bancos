<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Exceptions;

use Illuminate\Http\Client\Response;

/**
 * Lançada quando a API do banco responde com erro (4xx/5xx).
 *
 * ⚠️ $corpo guarda a resposta crua do PSP, que pode conter dados do pagador
 * (PII). Evite logar a exceção crua; o getMessage() expõe só detail/title.
 */
class BancoApiException extends BancoException
{
    /** @var array<string, mixed> */
    public array $corpo = [];

    public ?int $statusHttp = null;

    /**
     * Violações específicas do padrão BACEN (RFC 7807), cada uma com
     * "razao" e "propriedades".
     *
     * @var list<array<string, mixed>>
     */
    public array $violacoes = [];

    public static function daResposta(string $banco, Response $resposta): self
    {
        $corpo = $resposta->json() ?? [];
        $corpo = is_array($corpo) ? $corpo : [];

        // Formato de erro do padrão BACEN (RFC 7807): title/detail/violacoes.
        // BACEN (RFC 7807): detail/title; OAuth/boleto Sicredi: error_description/
        // message/mensagem (a API de Cobrança usa "mensagem" em PT).
        $detalhe = $corpo['detail']
            ?? $corpo['title']
            ?? $corpo['mensagem']
            ?? $corpo['message']
            ?? $corpo['error_description']
            ?? $resposta->reason();

        $e = new self("[{$banco}] Erro {$resposta->status()}: {$detalhe}");
        $e->statusHttp = $resposta->status();
        $e->corpo = $corpo;
        // O Sicredi usa "violacoes" (PT); outros PSPs usam "violations".
        $e->violacoes = $corpo['violacoes'] ?? $corpo['violations'] ?? [];

        return $e;
    }
}
