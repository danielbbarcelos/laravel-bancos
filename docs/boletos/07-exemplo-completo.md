# 7. Exemplo completo (Service + Controller)

Integração ponta a ponta numa API Laravel **multi-tenant**: cada tenant tem seu convênio
Sicredi; as credenciais ficam cifradas no banco e são injetadas em runtime.

## 1. Guardar as credenciais do tenant (cifradas)

```php
// database/migrations/xxxx_add_sicredi_boleto_to_tenants.php
Schema::table('tenants', function (Blueprint $table) {
    $table->text('sicredi_x_api_key')->nullable();
    $table->text('sicredi_username')->nullable();
    $table->text('sicredi_codigo_acesso')->nullable();
    $table->string('sicredi_cooperativa', 4)->nullable();
    $table->string('sicredi_posto', 2)->nullable();
    $table->string('sicredi_cod_beneficiario', 5)->nullable();
});
```

```php
// app/Models/Tenant.php
class Tenant extends Model
{
    protected $casts = [
        'sicredi_x_api_key'     => 'encrypted',
        'sicredi_username'      => 'encrypted',
        'sicredi_codigo_acesso' => 'encrypted',
    ];
}
```

## 2. Service que resolve o gateway do tenant

```php
// app/Services/BoletoSicredi.php
namespace App\Services;

use App\Models\Tenant;
use DanielBBarcelos\Bancos\Contracts\BoletoGateway;
use DanielBBarcelos\Bancos\Facades\Bancos;

class BoletoSicredi
{
    public function paraTenant(Tenant $tenant): BoletoGateway
    {
        return Bancos::build('sicredi', [
            'boleto' => [
                'x_api_key'           => $tenant->sicredi_x_api_key,
                'username'            => $tenant->sicredi_username,
                'password'            => $tenant->sicredi_codigo_acesso,
                'cooperativa'         => $tenant->sicredi_cooperativa,
                'posto'               => $tenant->sicredi_posto,
                'codigo_beneficiario' => $tenant->sicredi_cod_beneficiario,
                'base_url'            => config('services.sicredi.boleto_base_url'),
                'cache_key'           => "boleto:tenant:{$tenant->id}",  // isolamento por tenant
            ],
        ])->boleto();
    }
}
```

> `base_url` por ambiente: em produção `https://api-parceiro.sicredi.com.br`, em sandbox
> `https://api-parceiro.sicredi.com.br/sb`. Guarde em `config/services.php`.

## 3. Controller — emitir e baixar PDF

```php
// app/Http/Controllers/BoletoController.php
namespace App\Http\Controllers;

use App\Services\BoletoSicredi;
use DanielBBarcelos\Bancos\Data\Boleto\Boleto;
use DanielBBarcelos\Bancos\Data\Boleto\Pessoa;
use DanielBBarcelos\Bancos\Data\Shared\Valor;
use DanielBBarcelos\Bancos\Exceptions\BancoApiException;
use Illuminate\Http\Request;

class BoletoController extends Controller
{
    public function __construct(private BoletoSicredi $boletos) {}

    public function store(Request $request)
    {
        $dados = $request->validate([
            'valor'             => ['required', 'numeric', 'min:0.01'],
            'vencimento'        => ['required', 'date_format:Y-m-d'],
            'seu_numero'        => ['required', 'string', 'max:10'],
            'pagador.nome'      => ['required', 'string'],
            'pagador.documento' => ['required', 'string'],
            'pagador.cep'       => ['required', 'string'],
            'pagador.cidade'    => ['required', 'string'],
            'pagador.uf'        => ['required', 'string', 'size:2'],
        ]);

        $gateway = $this->boletos->paraTenant($request->user()->tenant);

        try {
            $emitido = $gateway->emitir(new Boleto(
                pagador: new Pessoa(
                    nome: $dados['pagador']['nome'],
                    documento: $dados['pagador']['documento'],
                    cep: $dados['pagador']['cep'],
                    cidade: $dados['pagador']['cidade'],
                    uf: $dados['pagador']['uf'],
                ),
                valor: Valor::reais($dados['valor']),
                vencimento: $dados['vencimento'],
                seuNumero: $dados['seu_numero'],
            ));
        } catch (BancoApiException $e) {
            report($e);
            return response()->json(['erro' => $e->getMessage()], 422);
        }

        // Persista o nossoNumero para consultas/conciliação futuras.
        $request->user()->tenant->cobrancas()->create([
            'nosso_numero'    => $emitido->nossoNumero,
            'seu_numero'      => $dados['seu_numero'],
            'linha_digitavel' => $emitido->linhaDigitavel,
            'status'          => 'EMITIDO',
        ]);

        return response()->json([
            'nosso_numero'    => $emitido->nossoNumero,
            'linha_digitavel' => $emitido->linhaDigitavel,
            'codigo_barras'   => $emitido->codigoBarras,
        ], 201);
    }

    public function pdf(Request $request, string $linhaDigitavel)
    {
        $gateway = $this->boletos->paraTenant($request->user()->tenant);
        $bytes = $gateway->pdf($linhaDigitavel);

        return response($bytes, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="boleto.pdf"',
        ]);
    }
}
```

## 4. Webhook — receber liquidações

```php
// routes/api.php
Route::post('/webhooks/sicredi/boleto/{tenant}', [WebhookController::class, 'boleto']);
```

```php
// app/Http/Controllers/WebhookController.php
public function boleto(Request $request, Tenant $tenant, BoletoSicredi $boletos)
{
    // Segurança na borda: mTLS do PSP / IP allowlist (o Sicredi não usa HMAC).
    $recebimento = $boletos->paraTenant($tenant)->processarNotificacao($request->all());

    // O evento BoletoLiquidado já foi disparado — o listener faz a baixa.
    return response()->noContent();
}
```

```php
// app/Providers/EventServiceProvider.php  (ou um Listener dedicado)
use DanielBBarcelos\Bancos\Events\BoletoLiquidado;
use Illuminate\Support\Facades\Event;

Event::listen(function (BoletoLiquidado $e) {
    $rec = $e->recebimento;

    $cobranca = Cobranca::where('nosso_numero', $rec->nossoNumero)->first();
    if (! $cobranca) {
        return;
    }

    if ($rec->pago()) {
        $cobranca->update([
            'status'     => 'LIQUIDADO',
            'valor_pago' => $rec->valorPago?->emReais(),
            'pago_em'    => now(),
        ]);
    } elseif ($rec->estornado()) {
        $cobranca->update(['status' => 'ESTORNADO']);
    }
});
```

## 5. Conciliação diária (opcional)

Como a API só lista **liquidados por dia**, um job diário fecha o ciclo para o que o
webhook eventualmente perdeu:

```php
// app/Console/Commands/ConciliarBoletos.php
public function handle(BoletoSicredi $boletos): void
{
    foreach (Tenant::whereNotNull('sicredi_x_api_key')->cursor() as $tenant) {
        $gateway = $boletos->paraTenant($tenant);
        $dia = now()->subDay()->format('Y-m-d');
        $pagina = 1;

        do {
            $p = $gateway->listarLiquidados($dia, pagina: $pagina);
            foreach ($p->itens as $liq) {
                Cobranca::where('nosso_numero', $liq->nossoNumero)
                    ->where('status', '!=', 'LIQUIDADO')
                    ->update([
                        'status'     => 'LIQUIDADO',
                        'valor_pago' => $liq->valorLiquidado?->emReais(),
                        'pago_em'    => $liq->dataPagamento,
                    ]);
            }
            $pagina++;
        } while ($p->temProxima);
    }
}
```

Pronto — emissão, PDF, webhook e conciliação, todos multi-tenant com credenciais em
runtime.
