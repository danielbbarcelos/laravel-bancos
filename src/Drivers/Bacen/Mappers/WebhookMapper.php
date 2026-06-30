<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Drivers\Bacen\Mappers;

use DanielBBarcelos\Bancos\Data\Pix\Webhook;

/**
 * De-para da configuração de webhook Pix no padrão BACEN. O parsing do payload
 * de notificação ({"pix": [...]}) fica no RecebimentoMapper, reutilizado também
 * pela consulta de Pix recebidos.
 */
class WebhookMapper
{
    /**
     * Resposta do GET /webhook/{chave} -> DTO canônico.
     *
     * @param  array<string, mixed>  $resposta
     */
    public function webhookParaDominio(array $resposta): Webhook
    {
        return new Webhook(
            chave: (string) ($resposta['chave'] ?? ''),
            url: (string) ($resposta['webhookUrl'] ?? ''),
            criadoEm: $resposta['criacao'] ?? null,
            bruto: $resposta,
        );
    }
}
