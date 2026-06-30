<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Console;

use DanielBBarcelos\Bancos\BancoManager;
use DanielBBarcelos\Bancos\Data\Pix\CobrancaImediata;
use DanielBBarcelos\Bancos\Data\Shared\Valor;
use Illuminate\Console\Command;
use Throwable;

/**
 * Diagnóstico de uma conexão de banco: valida a config, inspeciona o certificado
 * mTLS e testa a autenticação no PSP — traduzindo falhas comuns em dicas. Com
 * --cobranca, emite e consulta uma cobrança de R$ 0,01 (use só em homologação).
 */
class PingCommand extends Command
{
    protected $signature = 'bancos:ping
        {conexao? : Nome da conexão em bancos.drivers (padrão: bancos.default)}
        {--cobranca : Emite e consulta uma cobrança Pix de R$ 0,01 para validar ponta-a-ponta}';

    protected $description = 'Diagnostica a conexão com um banco (config, certificado mTLS e autenticação).';

    public function handle(BancoManager $manager): int
    {
        $conexao = $this->argument('conexao') ?? $manager->bancoPadrao();

        $this->line("Diagnóstico da conexão: <info>{$conexao}</info>");

        $config = config("bancos.drivers.{$conexao}");

        if (! is_array($config)) {
            $this->error("✗ Conexão [{$conexao}] não encontrada em config/bancos.php (bancos.drivers).");

            return self::FAILURE;
        }

        if (! $this->validarCredenciais($config)) {
            return self::FAILURE;
        }

        $this->inspecionarCertificado($config);

        if (! $this->testarAutenticacao($manager, $conexao)) {
            return self::FAILURE;
        }

        if ($this->option('cobranca')) {
            return $this->testarCobranca($manager, $conexao);
        }

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $config */
    protected function validarCredenciais(array $config): bool
    {
        $ok = true;

        foreach (['client_id', 'client_secret', 'base_url'] as $campo) {
            if (empty($config[$campo])) {
                $this->error("✗ Config obrigatória ausente: {$campo}");
                $ok = false;
            }
        }

        if ($ok) {
            $this->line("✓ Credenciais e base_url presentes ({$config['base_url']}).");
        } else {
            $this->warn('  Preencha as variáveis no .env antes de tentar autenticar.');
        }

        return $ok;
    }

    /** @param array<string, mixed> $config */
    protected function inspecionarCertificado(array $config): void
    {
        $cert = $config['certificado'] ?? null;

        if (empty($cert)) {
            $this->warn('⚠ Certificado mTLS não configurado. O Sicredi exige mTLS em '
                .'produção — sem ele a autenticação retornará 403.');

            return;
        }

        if (! is_readable($cert)) {
            $this->error("✗ Certificado configurado mas ilegível: {$cert}");

            return;
        }

        $conteudo = (string) @file_get_contents($cert);

        if (! str_contains($conteudo, '-----BEGIN')) {
            $this->warn("⚠ Certificado [{$cert}] não parece estar em PEM (sem '-----BEGIN'). "
                .'O Sicredi espera .cer em PEM; converta se necessário.');

            return;
        }

        $this->line("✓ Certificado mTLS legível e em PEM: {$cert}");

        $chave = $config['chave_privada'] ?? null;
        if (! empty($chave) && ! is_readable($chave)) {
            $this->error("✗ Chave privada configurada mas ilegível: {$chave}");
        }
    }

    protected function testarAutenticacao(BancoManager $manager, string $conexao): bool
    {
        $status = $manager->driver($conexao)->verificarConexao();

        if ($status->ok) {
            $this->info("✓ {$status->mensagem}");
            if ($status->expiraEm !== null) {
                $this->line("  Token expira em {$status->expiraEm}s.");
            }
            if ($status->scopes !== null) {
                $this->line("  Scopes: {$status->scopes}");
            }

            return true;
        }

        $this->error("✗ {$status->mensagem}");
        if ($status->dica !== null) {
            $this->warn("  Dica: {$status->dica}");
        }

        return false;
    }

    protected function testarCobranca(BancoManager $manager, string $conexao): int
    {
        $this->line('Emitindo cobrança de teste (R$ 0,01)...');

        try {
            $pix = $manager->driver($conexao)->pix();

            $cobranca = $pix->cobrancaImediata(new CobrancaImediata(valor: Valor::reais('0.01')));

            $this->info("✓ Cobrança criada. txid: {$cobranca->txid} | status: {$cobranca->status->value}");
            $this->line('  QR Code: '.($cobranca->qrCode ?? '(não retornado na criação)'));

            $consulta = $pix->consultarCobranca($cobranca->txid);
            $this->info("✓ Consulta OK. status atual: {$consulta->status->value}");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error("✗ Falha na cobrança de teste: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
