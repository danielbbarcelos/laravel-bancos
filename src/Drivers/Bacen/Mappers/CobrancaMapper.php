<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Drivers\Bacen\Mappers;

use DanielBBarcelos\Bancos\Data\Pix\Cobranca;
use DanielBBarcelos\Bancos\Data\Pix\CobrancaImediata;
use DanielBBarcelos\Bancos\Data\Shared\Valor;
use DanielBBarcelos\Bancos\Enums\StatusCobranca;

/**
 * De-para entre o DTO canônico e o payload de cobrança imediata (cob) do padrão
 * BACEN, adotado por Sicredi, C6 e a maioria dos PSPs. Se algum banco divergir
 * em algum campo, estenda este mapper no driver específico e sobrescreva o ponto.
 */
class CobrancaMapper
{
    /**
     * Canônico -> payload da API.
     *
     * @return array<string, mixed>
     */
    public function paraApi(CobrancaImediata $dados, ?string $chavePadrao = null): array
    {
        $payload = [
            'calendario' => ['expiracao' => $dados->expiracaoSegundos],
            'valor' => ['original' => $dados->valor->paraApi()],
            'chave' => $dados->chave ?? $chavePadrao,
        ];

        if ($dados->pagador !== null) {
            $payload['devedor'] = [
                'nome' => $dados->pagador->nome,
                ($dados->pagador->ehCnpj() ? 'cnpj' : 'cpf') => $dados->pagador->documentoLimpo(),
            ];
        }

        if ($dados->solicitacaoPagador !== null) {
            $payload['solicitacaoPagador'] = $dados->solicitacaoPagador;
        }

        if ($dados->infoAdicionais !== []) {
            $payload['infoAdicionais'] = array_map(
                fn ($info) => ['nome' => $info->nome, 'valor' => $info->valor],
                $dados->infoAdicionais,
            );
        }

        return $payload;
    }

    /**
     * Resposta da API -> DTO canônico.
     *
     * @param  array<string, mixed>  $resposta
     */
    public function paraDominio(array $resposta): Cobranca
    {
        return new Cobranca(
            txid: (string) ($resposta['txid'] ?? ''),
            status: StatusCobranca::from((string) ($resposta['status'] ?? 'ATIVA')),
            valor: Valor::reais((string) ($resposta['valor']['original'] ?? '0')),
            chave: $resposta['chave'] ?? null,
            qrCode: $resposta['pixCopiaECola'] ?? null,
            location: $resposta['location'] ?? ($resposta['loc']['location'] ?? null),
            criadoEm: $resposta['calendario']['criacao'] ?? null,
            expiracaoSegundos: isset($resposta['calendario']['expiracao'])
                ? (int) $resposta['calendario']['expiracao']
                : null,
            bruto: $resposta,
        );
    }

    /**
     * Resposta da listagem (GET /cob) -> lista canônica. O BACEN devolve as
     * cobranças no campo "cobs".
     *
     * @param  array<string, mixed>  $payload
     * @return list<Cobranca>
     */
    public function listaParaDominio(array $payload): array
    {
        $itens = $payload['cobs'] ?? [];

        if (! is_array($itens)) {
            return [];
        }

        return array_values(array_map(
            fn (array $cob) => $this->paraDominio($cob),
            $itens,
        ));
    }
}
