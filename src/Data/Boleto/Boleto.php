<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Data\Boleto;

use DanielBBarcelos\Bancos\Data\Shared\Valor;
use DanielBBarcelos\Bancos\Enums\TipoCobrancaBoleto;

/**
 * Requisição canônica para emitir um boleto registrado. Cobre o essencial +
 * encargos opcionais (juros, multa, desconto) e mensagens. tipoCobranca
 * Hibrido gera também o QR Code Pix do boleto.
 */
final readonly class Boleto
{
    /**
     * @param  list<string>  $mensagens     mensagens no corpo do boleto
     * @param  list<string>  $informativos  textos informativos
     */
    public function __construct(
        public Pessoa $pagador,
        public Valor $valor,
        public string $vencimento,                 // YYYY-MM-DD
        public string $seuNumero,
        public string $especieDocumento = 'DUPLICATA_MERCANTIL_INDICACAO',
        public TipoCobrancaBoleto $tipoCobranca = TipoCobrancaBoleto::Normal,
        public ?string $nossoNumero = null,        // omitido = PSP gera
        public ?Pessoa $beneficiarioFinal = null,
        public ?Encargo $juros = null,
        public ?Encargo $multa = null,
        public ?Desconto $desconto = null,
        public array $mensagens = [],
        public array $informativos = [],
    ) {
    }
}
