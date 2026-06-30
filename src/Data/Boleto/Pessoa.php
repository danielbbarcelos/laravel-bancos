<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Data\Boleto;

/**
 * Pessoa de um boleto (pagador ou beneficiário final), com endereço — exigido
 * pela API de Cobrança. O tipoPessoa (PF/PJ) é derivado do documento.
 */
final readonly class Pessoa
{
    public function __construct(
        public string $nome,
        public string $documento,
        public string $cep,
        public string $cidade,
        public string $uf,
        public ?string $logradouro = null,
        public ?string $numero = null,
    ) {
    }

    public function documentoLimpo(): string
    {
        return preg_replace('/\D/', '', $this->documento) ?? '';
    }

    public function ehCnpj(): bool
    {
        return strlen($this->documentoLimpo()) === 14;
    }

    public function tipoPessoa(): string
    {
        return $this->ehCnpj() ? 'PESSOA_JURIDICA' : 'PESSOA_FISICA';
    }
}
