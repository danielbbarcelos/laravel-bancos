<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Drivers\Bacen\Mappers;

use DanielBBarcelos\Bancos\Data\Pix\Cobranca;
use DanielBBarcelos\Bancos\Data\Pix\CobrancaComVencimento;
use DanielBBarcelos\Bancos\Data\Shared\Valor;
use DanielBBarcelos\Bancos\Enums\StatusCobranca;

/**
 * De-para da cobrança com vencimento (cobv) no padrão BACEN. A resposta é
 * mapeada para o mesmo DTO Cobranca da cobrança imediata (txid, status, qrCode...),
 * acrescido da data de vencimento.
 */
class CobrancaVencimentoMapper
{
    /**
     * Canônico -> payload da API.
     *
     * @return array<string, mixed>
     */
    public function paraApi(CobrancaComVencimento $dados, ?string $chavePadrao = null): array
    {
        $valor = ['original' => $dados->valor->paraApi()];

        if ($dados->multa !== null) {
            $valor['multa'] = ['modalidade' => $dados->multa->modalidade, 'valorPerc' => $dados->multa->valorPerc];
        }

        if ($dados->juros !== null) {
            $valor['juros'] = ['modalidade' => $dados->juros->modalidade, 'valorPerc' => $dados->juros->valorPerc];
        }

        if ($dados->desconto !== null) {
            $desconto = ['modalidade' => $dados->desconto->modalidade];

            if ($dados->desconto->datasFixas !== []) {
                $desconto['descontoDataFixa'] = array_map(
                    fn ($d) => ['data' => $d->data, 'valorPerc' => $d->valorPerc],
                    $dados->desconto->datasFixas,
                );
            } elseif ($dados->desconto->valorPerc !== null) {
                $desconto['valorPerc'] = $dados->desconto->valorPerc;
            }

            $valor['desconto'] = $desconto;
        }

        $payload = [
            'calendario' => [
                'dataDeVencimento' => $dados->vencimento,
                'validadeAposVencimento' => $dados->validadeAposVencimento,
            ],
            'valor' => $valor,
            'chave' => $dados->chave ?? $chavePadrao,
        ];

        if ($dados->devedor !== null) {
            $payload['devedor'] = [
                'nome' => $dados->devedor->nome,
                ($dados->devedor->ehCnpj() ? 'cnpj' : 'cpf') => $dados->devedor->documentoLimpo(),
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
     * Resposta da API -> DTO canônico (Cobranca).
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
            vencimento: $resposta['calendario']['dataDeVencimento'] ?? null,
            bruto: $resposta,
        );
    }

    /**
     * Resposta da listagem (GET /cobv) -> lista canônica. O BACEN devolve as
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
            fn (array $cobv) => $this->paraDominio($cobv),
            $itens,
        ));
    }
}
