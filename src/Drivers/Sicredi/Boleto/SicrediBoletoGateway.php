<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Drivers\Sicredi\Boleto;

use DanielBBarcelos\Bancos\Contracts\BoletoGateway;
use DanielBBarcelos\Bancos\Data\Boleto\Boleto;
use DanielBBarcelos\Bancos\Data\Boleto\BoletoEmitido;
use DanielBBarcelos\Bancos\Data\Boleto\ContratoWebhook;
use DanielBBarcelos\Bancos\Data\Boleto\InstrucaoBoleto;
use DanielBBarcelos\Bancos\Data\Boleto\PaginaLiquidacoes;
use DanielBBarcelos\Bancos\Data\Boleto\RecebimentoBoleto;
use DanielBBarcelos\Bancos\Events\BoletoLiquidado;

/**
 * Boleto registrado do Sicredi (API de Cobrança v1). As rotas e o de-para são
 * específicos desta API; cooperativa/posto/codigoBeneficiario vêm da config.
 */
class SicrediBoletoGateway implements BoletoGateway
{
    protected const ROTA = '/cobranca/boleto/v1/boletos';

    protected const ROTA_WEBHOOK = '/cobranca/boleto/v1/webhook/contrato';

    protected const ROTA_WEBHOOKS = '/cobranca/boleto/v1/webhook/contratos';

    public function __construct(
        protected SicrediBoletoConnector $http,
        protected BoletoMapper $mapper,
        protected string $codigoBeneficiario,
        protected string $banco = 'sicredi',
        protected string $cooperativa = '',
        protected string $posto = '',
    ) {
    }

    public function emitir(Boleto $dados): BoletoEmitido
    {
        $resposta = $this->http->post(self::ROTA, $this->mapper->paraApi($dados, $this->codigoBeneficiario));

        return $this->mapper->emitidoParaDominio($resposta->json());
    }

    public function consultar(string $nossoNumero): BoletoEmitido
    {
        $resposta = $this->http->get(self::ROTA, [
            'codigoBeneficiario' => $this->codigoBeneficiario,
            'nossoNumero' => $nossoNumero,
        ]);

        return $this->mapper->emitidoParaDominio($resposta->json());
    }

    public function pdf(string $linhaDigitavel): string
    {
        $resposta = $this->http->getRaw(
            self::ROTA.'/pdf',
            ['linhaDigitavel' => $linhaDigitavel],
            'application/pdf',
        );

        return $resposta->body();
    }

    public function baixar(string $nossoNumero): void
    {
        $this->http->patch(self::ROTA."/{$nossoNumero}/baixa", []);
    }

    public function alterarVencimento(string $nossoNumero, string $vencimento): InstrucaoBoleto
    {
        return $this->instruir($nossoNumero, 'data-vencimento', ['dataVencimento' => $vencimento]);
    }

    public function alterarDesconto(string $nossoNumero, array $valores): InstrucaoBoleto
    {
        $corpo = [];
        foreach (array_slice(array_values($valores), 0, 3) as $i => $valor) {
            $corpo['valorDesconto'.($i + 1)] = $valor;
        }

        return $this->instruir($nossoNumero, 'desconto', $corpo);
    }

    public function alterarDataDesconto(string $nossoNumero, array $datas): InstrucaoBoleto
    {
        $corpo = [];
        foreach (array_slice(array_values($datas), 0, 3) as $i => $data) {
            $corpo['data'.($i + 1)] = $data;
        }

        return $this->instruir($nossoNumero, 'data-desconto', $corpo);
    }

    public function alterarJuros(string $nossoNumero, string $valorOuPercentual): InstrucaoBoleto
    {
        return $this->instruir($nossoNumero, 'juros', ['valorOuPercentual' => $valorOuPercentual]);
    }

    public function alterarSeuNumero(string $nossoNumero, string $seuNumero): InstrucaoBoleto
    {
        return $this->instruir($nossoNumero, 'seu-numero', ['seuNumero' => $seuNumero]);
    }

    public function listarLiquidados(string $dia, ?string $cpfCnpjBeneficiarioFinal = null, int $pagina = 1): PaginaLiquidacoes
    {
        $resposta = $this->http->get(self::ROTA.'/liquidados/dia', array_filter([
            'codigoBeneficiario' => $this->codigoBeneficiario,
            'dia' => $this->mapper->diaParaConsulta($dia),
            'cpfCnpjBeneficiarioFinal' => $cpfCnpjBeneficiarioFinal,
            'pagina' => $pagina,
        ], fn ($v) => $v !== null && $v !== ''));

        return $this->mapper->paginaLiquidacoesParaDominio($resposta->json() ?? [], $pagina);
    }

    public function registrarWebhook(string $url, array $eventos = ['LIQUIDACAO']): ContratoWebhook
    {
        $resposta = $this->http->post(self::ROTA_WEBHOOK.'/', $this->payloadContrato($url, $eventos));

        return $this->mapper->contratoParaDominio($resposta->json());
    }

    public function consultarWebhook(): ?ContratoWebhook
    {
        $resposta = $this->http->get(self::ROTA_WEBHOOKS.'/', [
            'cooperativa' => $this->cooperativa,
            'posto' => $this->posto,
            'beneficiario' => $this->codigoBeneficiario,
        ]);

        $dados = $resposta->json();

        // A API pode devolver um único contrato ou uma lista; normalizamos.
        if (isset($dados[0])) {
            $dados = $dados[0];
        }

        return empty($dados) ? null : $this->mapper->contratoParaDominio($dados);
    }

    public function alterarWebhook(string $idContrato, string $url, array $eventos = ['LIQUIDACAO']): ContratoWebhook
    {
        $resposta = $this->http->put(
            self::ROTA_WEBHOOK."/{$idContrato}",
            $this->payloadContrato($url, $eventos),
        );

        return $this->mapper->contratoParaDominio($resposta->json());
    }

    public function processarNotificacao(array $payload): RecebimentoBoleto
    {
        $recebimento = $this->mapper->notificacaoParaDominio($payload);

        event(new BoletoLiquidado($this->banco, $recebimento));

        return $recebimento;
    }

    /**
     * Emite um comando de instrução (PATCH) sobre um boleto emitido.
     *
     * @param  array<string, mixed>  $corpo
     */
    protected function instruir(string $nossoNumero, string $comando, array $corpo): InstrucaoBoleto
    {
        $resposta = $this->http->patch(self::ROTA."/{$nossoNumero}/{$comando}", $corpo);

        return $this->mapper->instrucaoParaDominio($resposta->json() ?? []);
    }

    /**
     * @param  list<string>  $eventos
     * @return array<string, mixed>
     */
    protected function payloadContrato(string $url, array $eventos): array
    {
        return [
            'cooperativa' => $this->cooperativa,
            'posto' => $this->posto,
            'codBeneficiario' => $this->codigoBeneficiario,
            'eventos' => $eventos,
            'url' => $url,
            'urlStatus' => 'ATIVO',
            'contratoStatus' => 'ATIVO',
        ];
    }
}
