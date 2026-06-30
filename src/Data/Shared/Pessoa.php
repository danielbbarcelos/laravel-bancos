<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Data\Shared;

/**
 * Pessoa (devedor/pagador) canônica. O documento é guardado só com dígitos;
 * cada mapper decide se emite o campo "cpf" ou "cnpj" conforme o tamanho.
 */
final readonly class Pessoa
{
    public function __construct(
        public string $nome,
        public string $documento,
    ) {
    }

    /** Apenas dígitos, como as APIs Pix exigem. */
    public function documentoLimpo(): string
    {
        return preg_replace('/\D/', '', $this->documento) ?? '';
    }

    public function ehCnpj(): bool
    {
        return strlen($this->documentoLimpo()) === 14;
    }
}
