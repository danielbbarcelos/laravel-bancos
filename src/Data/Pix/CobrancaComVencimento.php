<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Data\Pix;

use DanielBBarcelos\Bancos\Data\Shared\Pessoa;
use DanielBBarcelos\Bancos\Data\Shared\Valor;

/**
 * Requisição canônica de cobrança Pix COM vencimento (cobv) — o "boleto-Pix".
 * Diferente da imediata, o txid é sempre fornecido pelo recebedor (a API usa
 * PUT /cobv/{txid}) e há data de vencimento + multa/juros/desconto opcionais.
 */
final readonly class CobrancaComVencimento
{
    /**
     * @param  list<InfoAdicional>  $infoAdicionais
     */
    public function __construct(
        public string $txid,
        public Valor $valor,
        public string $vencimento,              // YYYY-MM-DD
        public ?string $chave = null,
        public int $validadeAposVencimento = 0, // dias que a cobrança aceita pagamento após vencer
        public ?Pessoa $devedor = null,
        public ?Multa $multa = null,
        public ?Juros $juros = null,
        public ?Desconto $desconto = null,
        public ?string $solicitacaoPagador = null,
        public array $infoAdicionais = [],
    ) {
    }
}
