<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Data\Boleto;

/**
 * Resposta canônica de um comando de instrução sobre um boleto já emitido
 * (alteração de vencimento, desconto, juros, seu número, etc.). A instrução é
 * assíncrona: o PSP confirma o envio do movimento (statusComando) e o efetiva
 * depois. $bruto guarda a resposta original.
 */
final readonly class InstrucaoBoleto
{
    /**
     * @param  array<string, mixed>  $bruto
     */
    public function __construct(
        public string $nossoNumero,
        public ?string $statusComando = null,   // ex.: MOVIMENTO_ENVIADO
        public ?string $tipoMensagem = null,    // ex.: ALTERA_VENCIMENTO
        public ?string $transactionId = null,
        public ?string $dataHoraRegistro = null,
        public array $bruto = [],
    ) {
    }

    /** true quando o PSP aceitou e enfileirou o movimento de instrução. */
    public function enviado(): bool
    {
        return $this->statusComando === 'MOVIMENTO_ENVIADO';
    }
}
