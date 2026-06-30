<?php

declare(strict_types=1);

use DanielBBarcelos\Bancos\Data\Pix\CobrancaImediata;
use DanielBBarcelos\Bancos\Data\Pix\Devolucao;
use DanielBBarcelos\Bancos\Data\Pix\InfoAdicional;
use DanielBBarcelos\Bancos\Data\Shared\Pessoa;
use DanielBBarcelos\Bancos\Data\Shared\Valor;
use DanielBBarcelos\Bancos\Enums\Recurso;
use DanielBBarcelos\Bancos\Enums\StatusCobranca;
use DanielBBarcelos\Bancos\Exceptions\BancoApiException;
use DanielBBarcelos\Bancos\Facades\Bancos;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();

    // Token OAuth2 padrão para todos os testes.
    Http::fake([
        '*/oauth/token' => Http::response([
            'access_token' => 'token-fake-123',
            'expires_in' => 3600,
        ]),
    ]);
});

it('cria cobrança imediata via POST e mapeia para o DTO canônico', function () {
    Http::fake([
        '*/api/v3/cob' => Http::response([
            'txid' => 'TXID123',
            'status' => 'ATIVA',
            'calendario' => ['criacao' => '2026-06-30T12:00:00Z', 'expiracao' => 3600],
            'valor' => ['original' => '150.00'],
            'chave' => 'chave@exemplo.com',
            'location' => 'pix.sicredi.com.br/qr/v2/abc',
            'pixCopiaECola' => '00020126...6304ABCD',
        ]),
    ]);

    $cobranca = Bancos::pix()->cobrancaImediata(new CobrancaImediata(
        valor: Valor::reais('150.00'),
        expiracaoSegundos: 3600,
        pagador: new Pessoa(nome: 'Fulano de Tal', documento: '000.123.123-12'),
        solicitacaoPagador: 'Pagamento do pedido #42',
        infoAdicionais: [new InfoAdicional('Pedido', '42')],
    ));

    expect($cobranca->txid)->toBe('TXID123')
        ->and($cobranca->status)->toBe(StatusCobranca::Ativa)
        ->and($cobranca->valor->paraApi())->toBe('150.00')
        ->and($cobranca->qrCode)->toBe('00020126...6304ABCD')
        ->and($cobranca->paga())->toBeFalse();

    // De-para correto no envio: chave default da config + cpf detectado.
    Http::assertSent(function ($request) {
        $b = $request->data();

        return $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/v3/cob')
            && $b['valor']['original'] === '150.00'
            && $b['calendario']['expiracao'] === 3600
            && $b['chave'] === 'chave@exemplo.com'
            && $b['devedor']['cpf'] === '00012312312'
            && $b['devedor']['nome'] === 'Fulano de Tal'
            && $b['solicitacaoPagador'] === 'Pagamento do pedido #42'
            && $b['infoAdicionais'][0] === ['nome' => 'Pedido', 'valor' => '42'];
    });
});

it('usa PUT quando o txid é informado', function () {
    Http::fake([
        '*/api/v3/cob/*' => Http::response([
            'txid' => 'meu-txid-idempotente',
            'status' => 'ATIVA',
            'valor' => ['original' => '10.00'],
        ]),
    ]);

    Bancos::pix()->cobrancaImediata(new CobrancaImediata(
        valor: Valor::reais('10.00'),
        txid: 'pedido0042idempotente00000001', // txid BACEN: 26–35 alfanuméricos
    ));

    Http::assertSent(fn ($r) => $r->method() === 'PUT'
        && str_ends_with($r->url(), '/api/v3/cob/pedido0042idempotente00000001'));
});

it('emite cnpj quando o documento tem 14 dígitos', function () {
    Http::fake(['*/api/v3/cob' => Http::response(['txid' => 'X', 'status' => 'ATIVA', 'valor' => ['original' => '1.00']])]);

    Bancos::pix()->cobrancaImediata(new CobrancaImediata(
        valor: Valor::reais('1.00'),
        pagador: new Pessoa(nome: 'Empresa LTDA', documento: '12.345.678/0001-95'),
    ));

    Http::assertSent(fn ($r) => ($r->data()['devedor']['cnpj'] ?? null) === '12345678000195');
});

it('reaproveita o token em chamadas subsequentes', function () {
    Http::fake(['*/api/v3/cob/*' => Http::response(['txid' => 'A', 'status' => 'ATIVA', 'valor' => ['original' => '1.00']])]);

    Bancos::pix()->consultarCobranca('A');
    Bancos::pix()->consultarCobranca('A');

    // Apenas 1 requisição de token apesar de 2 consultas.
    Http::assertSentCount(3); // 1 token + 2 consultas
});

it('mapeia uma devolução de Pix', function () {
    Http::fake([
        '*/api/v2/pix/*/devolucao/*' => Http::response([
            'id' => 'dev-1',
            'rtrId' => 'RTR123',
            'valor' => '5.00',
            'status' => 'EM_PROCESSAMENTO',
        ]),
    ]);

    $devolucao = Bancos::pix()->devolver('E2E123', 'dev-1', new Devolucao(valor: Valor::reais('5.00')));

    expect($devolucao->id)->toBe('dev-1')
        ->and($devolucao->status)->toBe('EM_PROCESSAMENTO')
        ->and($devolucao->valor->paraApi())->toBe('5.00');

    Http::assertSent(fn ($r) => $r->method() === 'PUT'
        && $r->data() === ['valor' => '5.00']);
});

it('emite o token como form-urlencoded com grant_type e scopes', function () {
    Http::fake(['*/api/v3/cob/*' => Http::response(['txid' => 'A', 'status' => 'ATIVA', 'valor' => ['original' => '1.00']])]);

    Bancos::pix()->consultarCobranca('A'); // dispara a autenticação

    Http::assertSent(function ($r) {
        if (! str_contains($r->url(), '/oauth/token')) {
            return false;
        }

        return $r->isForm()
            && $r['grant_type'] === 'client_credentials'
            && str_contains($r['scope'], 'cob.write')
            && str_contains($r['scope'], 'pix.read');
    });
});

it('converte erro RFC 7807 do Sicredi em BancoApiException com violações', function () {
    // Corpo real conforme o Guia Técnico Pix Sicredi (Anexo IV).
    Http::fake([
        '*/api/v3/cob' => Http::response([
            'type' => 'https://pix.bcb.gov.br/api/v2/error/CobrancaInvalida',
            'title' => 'Cobrança inválida.',
            'status' => 400,
            'detail' => 'A requisição que busca criar uma cobrança está semanticamente errada.',
            'violacoes' => [
                ['razao' => 'Não foi localizada a chave informada', 'propriedades' => 'cob.chave'],
            ],
        ], 400),
    ]);

    try {
        Bancos::pix()->cobrancaImediata(new CobrancaImediata(valor: Valor::reais('1.00')));
        $this->fail('Esperava BancoApiException.');
    } catch (BancoApiException $e) {
        expect($e->statusHttp)->toBe(400)
            ->and($e->getMessage())->toContain('semanticamente errada')
            ->and($e->violacoes)->toHaveCount(1)
            ->and($e->violacoes[0]['propriedades'])->toBe('cob.chave');
    }
});

it('reporta os recursos suportados', function () {
    expect(Bancos::banco()->suporta(Recurso::Pix))->toBeTrue()
        ->and(Bancos::banco()->suporta(Recurso::Boleto))->toBeTrue()
        ->and(Bancos::banco()->suporta(Recurso::Pagamento))->toBeFalse();
});
