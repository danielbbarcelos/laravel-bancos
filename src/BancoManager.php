<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos;

use Closure;
use DanielBBarcelos\Bancos\Contracts\Banco;
use DanielBBarcelos\Bancos\Contracts\BoletoGateway;
use DanielBBarcelos\Bancos\Contracts\PixGateway;
use DanielBBarcelos\Bancos\Drivers\C6\C6Banco;
use DanielBBarcelos\Bancos\Drivers\Sicredi\SicrediBanco;
use DanielBBarcelos\Bancos\Exceptions\BancoException;
use Illuminate\Contracts\Container\Container;

/**
 * Resolve e cacheia bancos a partir de config/bancos.php. Segue o espírito do
 * Illuminate\Support\Manager, mas com "conexões nomeadas" (cada entrada de
 * "drivers" é uma instância configurada que aponta para um tipo de driver),
 * como faz o database/mail. Terceiros registram drivers próprios via extend().
 */
class BancoManager
{
    /** @var array<string, Banco> Instâncias já resolvidas, por nome de conexão. */
    protected array $bancos = [];

    /** @var array<string, Closure(array<string,mixed>, string): Banco> */
    protected array $criadores = [];

    public function __construct(protected Container $app)
    {
        $this->registrarDriversPadrao();
    }

    /** Resolve um banco pelo nome de conexão (ou o padrão da config). */
    public function banco(?string $nome = null): Banco
    {
        $nome ??= $this->bancoPadrao();

        return $this->bancos[$nome] ??= $this->resolver($nome);
    }

    /** Alias expressivo: Bancos::driver('sicredi'). */
    public function driver(?string $nome = null): Banco
    {
        return $this->banco($nome);
    }

    /**
     * Cria um banco com credenciais fornecidas em runtime (multi-tenant/SaaS),
     * mesclando $config sobre os defaults não-sensíveis (base_url, scopes,
     * timeout) do bloco base do config. Ao contrário de banco()/driver(), NÃO é
     * cacheado por nome — cada chamada devolve uma instância isolada, então
     * cada tenant tem suas próprias credenciais e seu próprio cache de token.
     *
     * @param  string  $driver  Tipo do driver e bloco base de defaults (ex.: 'sicredi').
     * @param  array<string, mixed>  $config  Credenciais/overrides do tenant. Use a chave
     *                                          'cache_key' para isolar o token do tenant.
     */
    public function build(string $driver, array $config = []): Banco
    {
        $base = $this->app['config']->get("bancos.drivers.{$driver}", []);
        $config = array_merge(is_array($base) ? $base : [], $config);

        $tipo = $config['driver'] ?? $driver;

        if (! isset($this->criadores[$tipo])) {
            throw new BancoException("Driver de banco [{$tipo}] não registrado.");
        }

        // $nome legível (= $driver) para mensagens de erro; o isolamento de
        // cache de token é resolvido pelo connector via 'cache_key'/credenciais.
        return ($this->criadores[$tipo])($config, $driver);
    }

    /** Registra um driver customizado (tipo => fábrica). */
    public function extend(string $driver, Closure $factory): static
    {
        $this->criadores[$driver] = $factory;

        return $this;
    }

    public function bancoPadrao(): string
    {
        return (string) $this->app['config']->get('bancos.default');
    }

    /** Atalho para o Pix do banco padrão: Bancos::pix()->cobrancaImediata(...). */
    public function pix(): PixGateway
    {
        return $this->banco()->pix();
    }

    /** Atalho para o boleto do banco padrão. */
    public function boleto(): BoletoGateway
    {
        return $this->banco()->boleto();
    }

    protected function resolver(string $nome): Banco
    {
        $config = $this->configDe($nome);

        $tipo = $config['driver'] ?? null;

        if ($tipo === null) {
            throw new BancoException("A conexão de banco [{$nome}] não define a chave 'driver'.");
        }

        if (! isset($this->criadores[$tipo])) {
            throw new BancoException("Driver de banco [{$tipo}] não registrado.");
        }

        return ($this->criadores[$tipo])($config, $nome);
    }

    /** @return array<string, mixed> */
    protected function configDe(string $nome): array
    {
        $config = $this->app['config']->get("bancos.drivers.{$nome}");

        if (! is_array($config)) {
            throw new BancoException("Banco [{$nome}] não configurado em bancos.drivers.");
        }

        return $config;
    }

    protected function registrarDriversPadrao(): void
    {
        $this->extend('sicredi', fn (array $config, string $nome) => new SicrediBanco($config, $nome));
        $this->extend('c6', fn (array $config, string $nome) => new C6Banco($config, $nome));
    }
}
