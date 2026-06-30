<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Support;

/**
 * Materializa um certificado/chave em PEM (recebido em memória) num arquivo
 * temporário, porque o cliente HTTP (cURL/Guzzle) exige um caminho de arquivo
 * para mTLS. O arquivo nasce com permissão 0600 e é removido automaticamente
 * quando a instância é destruída (fim do request, normalmente).
 *
 * Útil em SaaS: a aplicação guarda o certificado do tenant criptografado e
 * passa o conteúdo PEM ao package, sem precisar gerenciar arquivos no disco.
 */
final class CertificadoTemporario
{
    private function __construct(
        public readonly string $caminho,
    ) {
    }

    public static function materializar(string $pem): self
    {
        $caminho = tempnam(sys_get_temp_dir(), 'bancos-cert-');

        @chmod($caminho, 0600);
        file_put_contents($caminho, $pem);

        // Rede de segurança: se o processo morrer (fatal) antes do __destruct,
        // o shutdown remove a chave privada do disco mesmo assim.
        register_shutdown_function(static function () use ($caminho): void {
            if (is_file($caminho)) {
                @unlink($caminho);
            }
        });

        return new self($caminho);
    }

    public function __destruct()
    {
        if (is_file($this->caminho)) {
            @unlink($this->caminho);
        }
    }
}
