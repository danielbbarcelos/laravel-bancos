<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Data\Boleto;

use DanielBBarcelos\Bancos\Data\Shared\Valor;

/**
 * Resposta canônica de um boleto — usada tanto na emissão (linha digitável,
 * código de barras) quanto na consulta (situação, vencimento, valor). Campos
 * de QR Code/txid vêm preenchidos quando o boleto é híbrido (Pix). $bruto
 * guarda a resposta original do PSP.
 */
final readonly class BoletoEmitido
{
    /**
     * @param  array<string, mixed>  $bruto
     */
    public function __construct(
        public string $nossoNumero,
        public ?string $linhaDigitavel = null,
        public ?string $codigoBarras = null,
        public ?string $cooperativa = null,
        public ?string $posto = null,
        public ?string $txid = null,        // boleto híbrido (Pix)
        public ?string $qrCode = null,      // QR Code Pix do boleto híbrido
        public ?string $situacao = null,    // consulta (ex.: "EM CARTEIRA")
        public ?string $vencimento = null,  // consulta
        public ?Valor $valor = null,        // consulta (valorNominal)
        public array $bruto = [],
    ) {
    }
}
