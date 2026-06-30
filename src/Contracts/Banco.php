<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Contracts;

use DanielBBarcelos\Bancos\Data\Shared\StatusConexao;
use DanielBBarcelos\Bancos\Enums\Recurso;

/**
 * Ponto de entrada de um banco. Expõe os gateways por capacidade (ISP):
 * nem todo banco implementa tudo, então consulte suporta() antes de chamar
 * um gateway que possa lançar RecursoNaoSuportadoException.
 */
interface Banco
{
    /** Identificador do driver (ex.: "sicredi"). */
    public function nome(): string;

    public function suporta(Recurso $recurso): bool;

    /** Testa a autenticação contra o PSP (sem efeitos colaterais). */
    public function verificarConexao(): StatusConexao;

    public function pix(): PixGateway;

    public function boleto(): BoletoGateway;
}
