<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Drivers\Bacen\Mappers;

use DanielBBarcelos\Bancos\Data\Pix\Recebimento;
use DanielBBarcelos\Bancos\Data\Shared\Valor;

/**
 * De-para de um Pix recebido no padrão BACEN. Reutilizado tanto na notificação
 * de webhook ({"pix": [...]}) quanto na consulta de Pix recebidos
 * (GET /pix/{e2eid} devolve um item; GET /pix?inicio=&fim= devolve a lista).
 */
class RecebimentoMapper
{
    /**
     * Um item de Pix recebido -> DTO canônico.
     *
     * @param  array<string, mixed>  $pix
     */
    public function umParaDominio(array $pix): Recebimento
    {
        return new Recebimento(
            endToEndId: (string) ($pix['endToEndId'] ?? ''),
            valor: Valor::reais((string) ($pix['valor'] ?? '0')),
            txid: $pix['txid'] ?? null,
            chave: $pix['chave'] ?? null,
            horario: $pix['horario'] ?? null,
            infoPagador: $pix['infoPagador'] ?? null,
            pagadorNome: $pix['pagador']['nome'] ?? null,
            pagadorDocumento: $pix['pagador']['cpf'] ?? $pix['pagador']['cnpj'] ?? null,
            bruto: $pix,
        );
    }

    /**
     * Payload com lista de Pix ({"pix": [...]}) -> lista canônica.
     *
     * @param  array<string, mixed>  $payload
     * @return list<Recebimento>
     */
    public function listaParaDominio(array $payload): array
    {
        $itens = $payload['pix'] ?? [];

        if (! is_array($itens)) {
            return [];
        }

        return array_values(array_map(
            fn (array $pix) => $this->umParaDominio($pix),
            $itens,
        ));
    }
}
