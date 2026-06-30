<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Data\Shared;

/**
 * Resultado de um diagnóstico de conexão com o banco (autenticação). Usado pelo
 * comando bancos:ping e por quem quiser checar a saúde da integração em runtime.
 */
final readonly class StatusConexao
{
    public function __construct(
        public bool $ok,
        public string $mensagem,
        public ?int $expiraEm = null,   // segundos de validade do token
        public ?string $scopes = null,  // scopes concedidos pelo PSP
        public ?string $dica = null,    // orientação quando ok = false
    ) {
    }

    public static function sucesso(string $mensagem, ?int $expiraEm = null, ?string $scopes = null): self
    {
        return new self(ok: true, mensagem: $mensagem, expiraEm: $expiraEm, scopes: $scopes);
    }

    public static function falha(string $mensagem, ?string $dica = null): self
    {
        return new self(ok: false, mensagem: $mensagem, dica: $dica);
    }
}
