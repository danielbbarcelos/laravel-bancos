<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Drivers\Bacen\Mappers;

use DanielBBarcelos\Bancos\Data\Pix\Devolucao;
use DanielBBarcelos\Bancos\Data\Shared\Valor;

/** De-para da devolução de Pix (PUT /pix/{e2eid}/devolucao/{id}) no padrão BACEN. */
class DevolucaoMapper
{
    /** @return array<string, mixed> */
    public function paraApi(Devolucao $dados): array
    {
        return ['valor' => $dados->valor->paraApi()];
    }

    /** @param  array<string, mixed>  $resposta */
    public function paraDominio(array $resposta): Devolucao
    {
        return new Devolucao(
            valor: Valor::reais((string) ($resposta['valor'] ?? '0')),
            id: $resposta['id'] ?? null,
            status: $resposta['status'] ?? null,
            rtrId: $resposta['rtrId'] ?? null,
            bruto: $resposta,
        );
    }
}
