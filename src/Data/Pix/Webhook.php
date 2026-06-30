<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Data\Pix;

/** Configuração de webhook Pix registrada no PSP, atrelada a uma chave Pix. */
final readonly class Webhook
{
    /**
     * @param  array<string, mixed>  $bruto
     */
    public function __construct(
        public string $chave,
        public string $url,
        public ?string $criadoEm = null,
        public array $bruto = [],
    ) {
    }
}
