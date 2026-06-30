<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos\Drivers\Bacen;

use DanielBBarcelos\Bancos\Exceptions\BancoApiException;
use DanielBBarcelos\Bancos\Support\CertificadoTemporario;
use DanielBBarcelos\Bancos\Support\Segredo;
use DanielBBarcelos\Bancos\Support\Url;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

/**
 * Base HTTP para PSPs no padrão BACEN: base URL, timeout, certificado mTLS,
 * cache de access token e tratamento de erro — tudo sobre o cliente Http nativo
 * do Laravel. A única coisa que varia entre bancos é como emitir o token, então
 * cada driver implementa apenas emitirToken().
 */
abstract class ClienteHttpBacen implements BacenConnector
{
    /**
     * Certificados materializados de conteúdo PEM. Mantidos vivos enquanto o
     * connector existir; ao destruí-lo, os arquivos temporários são removidos.
     *
     * @var array<string, CertificadoTemporario>
     */
    protected array $temporarios = [];

    /**
     * Caminhos de cert/chave já resolvidos (memoização), para não materializar
     * o mesmo PEM a cada requisição.
     *
     * @var array<string, string|null>
     */
    protected array $arquivosResolvidos = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        #[SensitiveParameter]
        protected array $config,
        protected string $nome,
    ) {
    }

    /** Evita que credenciais apareçam em dd()/var_dump()/logs de objeto. */
    public function __debugInfo(): array
    {
        return ['nome' => $this->nome, 'config' => Segredo::mascarar($this->config)];
    }

    public function get(string $caminho, array $query = []): Response
    {
        return $this->enviar(fn () => $this->autenticado()->get($caminho, $query));
    }

    public function post(string $caminho, array $corpo): Response
    {
        return $this->enviar(fn () => $this->autenticado()->post($caminho, $corpo));
    }

    public function put(string $caminho, array $corpo): Response
    {
        return $this->enviar(fn () => $this->autenticado()->put($caminho, $corpo));
    }

    public function patch(string $caminho, array $corpo): Response
    {
        return $this->enviar(fn () => $this->autenticado()->patch($caminho, $corpo));
    }

    public function delete(string $caminho): Response
    {
        return $this->enviar(fn () => $this->autenticado()->delete($caminho));
    }

    /**
     * GET que retorna a resposta crua com um Accept específico (ex.: baixar um
     * PDF). Use ->body() para os bytes. Reaproveita auth/retry/erro.
     */
    public function getRaw(string $caminho, array $query = [], string $accept = '*/*'): Response
    {
        return $this->enviar(fn () => $this->autenticado()->accept($accept)->get($caminho, $query));
    }

    /**
     * Executa a requisição e normaliza o resultado. Se o PSP responder 401 (token
     * expirado no servidor antes do TTL local), descarta o token cacheado e tenta
     * de novo UMA vez. Com retry ativo, o cliente Http lança RequestException ao
     * esgotar/recusar tentativas; capturamos para sempre passar a resposta pelo
     * garantirOk → BancoApiException. ConnectionException (sem resposta) propaga.
     *
     * @param  callable(): Response  $callback
     */
    protected function enviar(callable $callback): Response
    {
        $resposta = $this->tentar($callback);

        if ($resposta->status() === 401) {
            Cache::forget($this->chaveCacheToken());
            $resposta = $this->tentar($callback);
        }

        return $this->garantirOk($resposta);
    }

    /** @param callable(): Response $callback */
    protected function tentar(callable $callback): Response
    {
        try {
            return $callback();
        } catch (RequestException $e) {
            if ($e->response === null) {
                throw $e;
            }

            return $e->response;
        }
    }

    /**
     * Emite um novo access token no PSP. Deve retornar ['access_token', 'expires_in'].
     * É o único ponto que difere entre bancos.
     *
     * @return array{access_token: string, expires_in?: int}
     */
    abstract protected function emitirToken(): array;

    /**
     * Força a emissão de um token (ignorando o cache) e devolve a resposta crua
     * do PSP. Usado para diagnóstico de conexão (bancos:ping). Propaga
     * BancoApiException em caso de falha (401/403/etc.).
     *
     * @return array<string, mixed>
     */
    public function autenticar(): array
    {
        return $this->emitirToken();
    }

    /** Cliente já com base URL, mTLS e Bearer token aplicados. */
    protected function autenticado(): PendingRequest
    {
        return $this->cliente()->withToken($this->token());
    }

    /** Cliente base: URL, timeout, JSON e certificado mTLS. Sem token. */
    protected function cliente(): PendingRequest
    {
        $req = Http::baseUrl($this->urlBase())
            ->timeout((int) ($this->config['timeout'] ?? 30))
            ->withOptions(['verify' => true]) // nunca desabilita a verificação TLS do servidor
            ->acceptJson()
            ->asJson();

        return $this->aplicarMtls($this->aplicarRetry($req));
    }

    /** base_url validada — exige https (anti-MITM), salvo 'permitir_http' em dev. */
    protected function urlBase(): string
    {
        $url = Url::exigirHttps(
            (string) ($this->config['base_url'] ?? ''),
            (bool) ($this->config['permitir_http'] ?? false),
        );

        return rtrim($url, '/');
    }

    /**
     * Retry com backoff em falhas transitórias (timeout/conexão e 5xx). Não
     * re-tenta 4xx (erro de negócio). Configurável: 'tentativas' (default 3) e
     * 'retry_intervalo_ms' (default 200). Use txid nos PUT para idempotência.
     */
    protected function aplicarRetry(PendingRequest $req): PendingRequest
    {
        $tentativas = (int) ($this->config['tentativas'] ?? 3);

        if ($tentativas <= 1) {
            return $req;
        }

        $intervalo = (int) ($this->config['retry_intervalo_ms'] ?? 200);

        return $req->retry($tentativas, $intervalo, function ($excecao) {
            return $excecao instanceof ConnectionException
                || ($excecao instanceof RequestException
                    && $excecao->response !== null
                    && $excecao->response->serverError());
        }, throw: false);
    }

    protected function aplicarMtls(PendingRequest $req): PendingRequest
    {
        $cert = $this->resolverArquivo('certificado', $this->config['certificado'] ?? null);
        $chave = $this->resolverArquivo('chave_privada', $this->config['chave_privada'] ?? null);
        $senha = $this->config['senha_cert'] ?? null;

        if ($cert === null) {
            return $req; // ambiente de teste/sandbox sem mTLS
        }

        $opcoes = ['cert' => $senha ? [$cert, $senha] : $cert];

        if ($chave !== null) {
            $opcoes['ssl_key'] = $chave;
        }

        return $req->withOptions($opcoes);
    }

    /**
     * Devolve um caminho de arquivo para cert/chave. Aceita tanto um caminho já
     * existente quanto o conteúdo PEM em memória (detectado por "-----BEGIN"),
     * que é materializado num arquivo temporário 0600 e memoizado.
     */
    protected function resolverArquivo(string $tipo, ?string $valor): ?string
    {
        if (array_key_exists($tipo, $this->arquivosResolvidos)) {
            return $this->arquivosResolvidos[$tipo];
        }

        if ($valor === null || $valor === '') {
            return $this->arquivosResolvidos[$tipo] = null;
        }

        if (str_contains($valor, '-----BEGIN')) {
            $this->temporarios[$tipo] = CertificadoTemporario::materializar($valor);

            return $this->arquivosResolvidos[$tipo] = $this->temporarios[$tipo]->caminho;
        }

        return $this->arquivosResolvidos[$tipo] = $valor; // caminho de arquivo
    }

    /** Access token válido — do cache (cifrado) ou recém-emitido. */
    protected function token(): string
    {
        $chaveCache = $this->chaveCacheToken();

        if ($cifrado = Cache::get($chaveCache)) {
            try {
                // Token guardado cifrado: vazar o store de cache não basta sem a APP_KEY.
                return (string) Crypt::decrypt($cifrado);
            } catch (DecryptException) {
                Cache::forget($chaveCache); // valor inválido/legado: re-emite
            }
        }

        $dados = $this->emitirToken();

        $token = (string) ($dados['access_token'] ?? '');
        $expiraEm = (int) ($dados['expires_in'] ?? 3600);

        // Renova 60s antes para evitar uso de token expirado em voo.
        Cache::put($chaveCache, Crypt::encrypt($token), max(60, $expiraEm - 60));

        return $token;
    }

    /**
     * Chave de cache do token, isolada por credencial. Em multi-tenant, dois
     * clientes do mesmo driver NÃO podem compartilhar token: por padrão a chave
     * deriva de driver+client_id+base_url (hash, sem vazar o client_id em claro);
     * a aplicação pode forçar um escopo próprio via config 'cache_key'.
     */
    protected function chaveCacheToken(): string
    {
        $escopo = $this->config['cache_key']
            ?? sha1($this->nome.'|'.($this->config['client_id'] ?? '').'|'.($this->config['base_url'] ?? ''));

        return "bancos:token:{$escopo}";
    }

    protected function garantirOk(Response $resposta): Response
    {
        if ($resposta->failed()) {
            throw BancoApiException::daResposta($this->nome, $resposta);
        }

        return $resposta;
    }
}
