<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Drivers\Sicredi\Boleto;

use DanielBBarcelos\Bancos\Contracts\BoletoGateway;
use DanielBBarcelos\Bancos\Data\Boleto\Boleto;
use DanielBBarcelos\Bancos\Data\Boleto\BoletoEmitido;

/**
 * Boleto registrado do Sicredi (API de Cobrança v1). As rotas e o de-para são
 * específicos desta API; o codigoBeneficiario vem da config do banco.
 */
class SicrediBoletoGateway implements BoletoGateway
{
    protected const ROTA = '/cobranca/boleto/v1/boletos';

    public function __construct(
        protected SicrediBoletoConnector $http,
        protected BoletoMapper $mapper,
        protected string $codigoBeneficiario,
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

    public function baixar(string $nossoNumero): void
    {
        $this->http->patch(self::ROTA."/{$nossoNumero}/baixa", []);
    }
}
