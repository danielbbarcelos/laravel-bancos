<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Drivers\Bacen\Concerns;

use DanielBBarcelos\Bancos\Data\Shared\StatusConexao;
use DanielBBarcelos\Bancos\Exceptions\BancoApiException;
use Throwable;

/**
 * Diagnóstico de conexão compartilhado por drivers BACEN: força a autenticação
 * e traduz os erros mais comuns (mTLS, credenciais) em dicas acionáveis.
 *
 * A classe que usa o trait deve expor:
 *   - $this->http  (um ClienteHttpBacen)
 *   - $this->nome  (string)
 */
trait VerificaConexaoBacen
{
    public function verificarConexao(): StatusConexao
    {
        try {
            $dados = $this->http->autenticar();

            return StatusConexao::sucesso(
                mensagem: "Autenticação com [{$this->nome}] bem-sucedida.",
                expiraEm: isset($dados['expires_in']) ? (int) $dados['expires_in'] : null,
                scopes: $dados['scope'] ?? null,
            );
        } catch (BancoApiException $e) {
            return StatusConexao::falha($e->getMessage(), $this->dicaPara($e));
        } catch (Throwable $e) {
            return StatusConexao::falha(
                "Falha ao conectar em [{$this->nome}]: {$e->getMessage()}",
                'Verifique a base_url, a conectividade de rede e o certificado mTLS.',
            );
        }
    }

    protected function dicaPara(BancoApiException $e): ?string
    {
        return match ($e->statusHttp) {
            403 => 'Falha no mTLS: o certificado pode estar ausente, inválido ou não '
                .'corresponder às credenciais. Baixe o .cer + .key (sem senha) no portal e '
                .'aponte certificado/chave_privada na config.',
            401 => 'Credenciais inválidas: confira client_id e client_secret.',
            400 => 'Requisição recusada pelo PSP: revise os scopes e o ambiente (base_url).',
            default => null,
        };
    }
}
