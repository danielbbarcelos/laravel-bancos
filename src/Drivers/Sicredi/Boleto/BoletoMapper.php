<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Drivers\Sicredi\Boleto;

use DanielBBarcelos\Bancos\Data\Boleto\Boleto;
use DanielBBarcelos\Bancos\Data\Boleto\BoletoEmitido;
use DanielBBarcelos\Bancos\Data\Boleto\ContratoWebhook;
use DanielBBarcelos\Bancos\Data\Boleto\Encargo;
use DanielBBarcelos\Bancos\Data\Boleto\Pessoa;
use DanielBBarcelos\Bancos\Data\Boleto\RecebimentoBoleto;
use DanielBBarcelos\Bancos\Data\Shared\Valor;

/**
 * De-para entre os DTOs canônicos de boleto e o payload da API de Cobrança do
 * Sicredi. Único lugar que conhece os nomes de campo dessa API.
 */
class BoletoMapper
{
    /**
     * Canônico -> payload de emissão.
     *
     * @return array<string, mixed>
     */
    public function paraApi(Boleto $boleto, string $codigoBeneficiario): array
    {
        $payload = [
            'codigoBeneficiario' => $codigoBeneficiario,
            'tipoCobranca' => $boleto->tipoCobranca->value,
            'especieDocumento' => $boleto->especieDocumento,
            'dataVencimento' => $boleto->vencimento,
            'valor' => $boleto->valor->emReais(),
            'seuNumero' => $boleto->seuNumero,
            'pagador' => $this->pagador($boleto->pagador),
        ];

        if ($boleto->nossoNumero !== null) {
            $payload['nossoNumero'] = $boleto->nossoNumero;
        }

        if ($boleto->beneficiarioFinal !== null) {
            $payload['beneficiarioFinal'] = $this->beneficiario($boleto->beneficiarioFinal);
        }

        if ($boleto->juros !== null) {
            $payload += $this->encargo('Juros', $boleto->juros);
        }

        if ($boleto->multa !== null) {
            $payload += $this->encargo('Multa', $boleto->multa);
        }

        if ($boleto->desconto !== null) {
            $payload['tipoDesconto'] = $boleto->desconto->tipo->value;

            foreach (array_slice($boleto->desconto->faixas, 0, 3) as $i => $faixa) {
                $n = $i + 1;
                $payload["valorDesconto{$n}"] = $faixa['valor'];
                $payload["dataDesconto{$n}"] = $faixa['data'];
            }
        }

        if ($boleto->mensagens !== []) {
            $payload['mensagens'] = $boleto->mensagens;
        }

        if ($boleto->informativos !== []) {
            $payload['informativos'] = $boleto->informativos;
        }

        return $payload;
    }

    /**
     * Resposta (emissão ou consulta) -> DTO canônico.
     *
     * @param  array<string, mixed>  $resposta
     */
    public function emitidoParaDominio(array $resposta): BoletoEmitido
    {
        return new BoletoEmitido(
            nossoNumero: (string) ($resposta['nossoNumero'] ?? ''),
            linhaDigitavel: $resposta['linhaDigitavel'] ?? null,
            codigoBarras: $resposta['codigoBarras'] ?? null,
            cooperativa: $resposta['cooperativa'] ?? null,
            posto: $resposta['posto'] ?? null,
            txid: $resposta['txid'] ?? null,
            qrCode: $resposta['qrCode'] ?? $resposta['codigoQrCode'] ?? null,
            situacao: $resposta['situacao'] ?? null,
            vencimento: $resposta['dataVencimento'] ?? null,
            valor: isset($resposta['valorNominal'])
                ? Valor::reais((string) $resposta['valorNominal'])
                : null,
            bruto: $resposta,
        );
    }

    /**
     * Resposta de criar/consultar contrato de webhook -> DTO canônico.
     *
     * @param  array<string, mixed>  $resposta
     */
    public function contratoParaDominio(array $resposta): ContratoWebhook
    {
        return new ContratoWebhook(
            idContrato: isset($resposta['idContrato']) ? (string) $resposta['idContrato'] : null,
            url: (string) ($resposta['url'] ?? ''),
            eventos: $resposta['eventos'] ?? [],
            status: $resposta['contratoStatus'] ?? ($resposta['status'] ?? null),
            bruto: $resposta,
        );
    }

    /**
     * Payload da notificação de webhook de boleto -> DTO canônico.
     *
     * @param  array<string, mixed>  $payload
     */
    public function notificacaoParaDominio(array $payload): RecebimentoBoleto
    {
        $valor = static fn (string $chave): ?Valor => isset($payload[$chave]) && $payload[$chave] !== ''
            ? Valor::reais((string) $payload[$chave])
            : null;

        return new RecebimentoBoleto(
            nossoNumero: (string) ($payload['nossoNumero'] ?? ''),
            movimento: (string) ($payload['movimento'] ?? ''),
            valorPago: $valor('valorLiquidacao'),
            valorJuros: $valor('valorJuros'),
            valorMulta: $valor('valorMulta'),
            valorDesconto: $valor('valorDesconto'),
            beneficiario: $payload['beneficiario'] ?? null,
            agencia: $payload['agencia'] ?? null,
            posto: $payload['posto'] ?? null,
            bruto: $payload,
        );
    }

    /** Pagador usa o campo "endereco" (logradouro + número juntos). */
    private function pagador(Pessoa $p): array
    {
        return array_filter([
            'nome' => $p->nome,
            'documento' => $p->documentoLimpo(),
            'tipoPessoa' => $p->tipoPessoa(),
            'cep' => $p->cep,
            'cidade' => $p->cidade,
            'uf' => $p->uf,
            'endereco' => trim($p->logradouro.' '.($p->numero ?? '')) ?: null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** Beneficiário final separa "logradouro" e "numeroEndereco". */
    private function beneficiario(Pessoa $p): array
    {
        return array_filter([
            'nome' => $p->nome,
            'documento' => $p->documentoLimpo(),
            'tipoPessoa' => $p->tipoPessoa(),
            'cep' => $p->cep,
            'cidade' => $p->cidade,
            'uf' => $p->uf,
            'logradouro' => $p->logradouro,
            'numeroEndereco' => $p->numero,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** @return array<string, mixed> */
    private function encargo(string $sufixo, Encargo $e): array
    {
        $campos = [
            "tipo{$sufixo}" => $e->tipo->value,
            strtolower($sufixo) => $e->valor,
        ];

        if ($e->dataInicio !== null) {
            $campos["dataInicio{$sufixo}"] = $e->dataInicio;
        }

        return $campos;
    }
}
