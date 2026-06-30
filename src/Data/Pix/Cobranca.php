<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Data\Pix;

use DanielBBarcelos\Bancos\Data\Shared\Valor;
use DanielBBarcelos\Bancos\Enums\StatusCobranca;

/**
 * Resposta canônica de uma cobrança Pix. Forma idêntica para qualquer banco;
 * $bruto guarda a resposta original do PSP como escape hatch.
 */
final readonly class Cobranca
{
    /**
     * @param  array<string, mixed>  $bruto
     */
    public function __construct(
        public string $txid,
        public StatusCobranca $status,
        public Valor $valor,
        public ?string $chave = null,
        public ?string $qrCode = null,        // pixCopiaECola (EMV)
        public ?string $location = null,      // URL do payload do QR Code
        public ?string $criadoEm = null,      // ISO-8601
        public ?int $expiracaoSegundos = null, // cobrança imediata (cob)
        public ?string $vencimento = null,     // cobrança com vencimento (cobv), YYYY-MM-DD
        public array $bruto = [],
    ) {
    }

    public function paga(): bool
    {
        return $this->status->paga();
    }
}
