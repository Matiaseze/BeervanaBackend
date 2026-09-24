<?php

namespace Tests\Feature;

use App\Models\Factura;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\User;
use App\Services\MercadoPago;
use App\Services\MercadoPagoSimulado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreaDatos;
use Tests\TestCase;

/**
 * Modo simulado: el flujo entero sin credenciales y sin internet.
 *
 * Lo importante no es que la simulación "ande", sino que recorra el MISMO
 * camino que el modo real. Si tomara un atajo —marcando el pedido como pagado
 * a mano, por ejemplo— probar en simulado no diría nada sobre el modo real, que
 * es justamente para lo que existe.
 *
 * Por eso varios de estos tests verifican que no se saltee nada: que pase por
 * el webhook, que emita factura de verdad, que descuente stock.
 */
class CheckoutSimuladoTest extends TestCase
{
    use RefreshDatabase;
    use CreaDatos;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.mercadopago.modo', 'simulado');

        // Si algo intentara salir a internet, el test tiene que romper: es la
        // garantía de que el modo simulado no llama a nadie.
        Http::preventStrayRequests();
    }

    private function pedidoDe(User $cliente, $cerveza, int $cantidad = 2): Pedido
    {
        $datos = $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => $cantidad]],
            'metodo_entrega' => 'retiro',
        ], $this->tokenDe($cliente))->assertCreated()->json('pedido');

        $this->app['auth']->forgetGuards();

        return Pedido::find($datos['id']);
    }

    private function cajero(): User
    {
        return $this->crearUsuario(['rol' => User::ROL_EMPLEADO]);
    }

    // ── El cambio de modo ────────────────────────────────────────────────────

    public function test_en_modo_simulado_se_inyecta_el_cliente_falso(): void
    {
        $this->assertInstanceOf(MercadoPagoSimulado::class, app(MercadoPago::class));
    }

    public function test_en_modo_real_se_inyecta_el_de_verdad(): void
    {
        config()->set('services.mercadopago.modo', 'real');

        $mp = app(MercadoPago::class);

        $this->assertInstanceOf(MercadoPago::class, $mp);
        $this->assertNotInstanceOf(MercadoPagoSimulado::class, $mp);
    }

    public function test_el_checkout_falso_no_existe_en_modo_real(): void
    {
        // No alcanza con que nadie lo enlace: la URL no tiene que responder,
        // porque en producción sería una forma de marcar pedidos como pagados.
        config()->set('services.mercadopago.modo', 'real');

        $cliente = $this->crearUsuario();
        $pedido = $this->pedidoDe($cliente, $this->crearCerveza(['stock' => 10]));

        $this->get("/checkout-simulado/{$pedido->codigo}")->assertNotFound();
        $this->post("/checkout-simulado/{$pedido->codigo}", ['resultado' => 'aprobar'])
            ->assertNotFound();
    }

    public function test_no_hacen_falta_credenciales(): void
    {
        // Es el punto de todo: con el panel de MP caído, esto sigue andando
        config()->set('services.mercadopago.access_token', null);

        $cliente = $this->crearUsuario();
        $pedido = $this->pedidoDe($cliente, $this->crearCerveza(['stock' => 10]));

        $this->postJson("/my_api/pedidos/{$pedido->codigo}/mercadopago", [], $this->tokenDe($cliente))
            ->assertCreated()
            ->assertJsonStructure(['init_point']);
    }

    // ── El recorrido completo ────────────────────────────────────────────────

    public function test_el_cajero_cobra_y_el_pago_se_acredita(): void
    {
        $cajero = $this->cajero();
        $cliente = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);
        $pedido = $this->pedidoDe($cliente, $cerveza, 3);

        // 1. El cajero genera el cobro
        $this->actingAs($cajero)
            ->post("/mostrador/pedidos/{$pedido->id}/mercadopago")
            ->assertRedirect();

        $pago = Pago::where('pedido_id', $pedido->id)->firstOrFail();

        // Todavía no se cobró nada
        $this->assertSame(10, $cerveza->fresh()->stock);
        $this->assertDatabaseCount('facturas', 0);

        // 2. El cliente abre el checkout (sin login, como con el QR) y aprueba
        $this->post("/checkout-simulado/{$pedido->codigo}", ['resultado' => 'aprobar'])
            ->assertRedirect();

        // 3. Recién ahí se cierra la venta
        $this->assertSame(Pedido::PAGADO, $pedido->fresh()->estado);
        $this->assertSame(7, $cerveza->fresh()->stock);
        $this->assertDatabaseHas('facturas', [
            'pedido_id' => $pedido->id,
            'medio_pago' => 'mercadopago',
            'cobrado_por' => $cajero->id,
        ]);
    }

    public function test_la_pantalla_del_mostrador_se_entera(): void
    {
        $cajero = $this->cajero();
        $cliente = $this->crearUsuario();
        $pedido = $this->pedidoDe($cliente, $this->crearCerveza(['stock' => 10]));

        $this->actingAs($cajero)->post("/mostrador/pedidos/{$pedido->id}/mercadopago");
        $pago = Pago::where('pedido_id', $pedido->id)->firstOrFail();

        // Antes de pagar
        $this->actingAs($cajero)->getJson(route('mostrador.mp.estado', $pago))
            ->assertOk()
            ->assertJsonPath('listo', false);

        $this->post("/checkout-simulado/{$pedido->codigo}", ['resultado' => 'aprobar']);

        // Después
        $this->actingAs($cajero)->getJson(route('mostrador.mp.estado', $pago))
            ->assertOk()
            ->assertJsonPath('listo', true)
            ->assertJsonPath('estado', Pago::APROBADO);
    }

    public function test_el_cliente_tambien_puede_pagar_desde_la_tienda(): void
    {
        $cliente = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);
        $pedido = $this->pedidoDe($cliente, $cerveza, 2);
        $cabeceras = $this->tokenDe($cliente);

        $this->postJson("/my_api/pedidos/{$pedido->codigo}/mercadopago", [], $cabeceras)
            ->assertCreated();

        $this->post("/checkout-simulado/{$pedido->codigo}", ['resultado' => 'aprobar']);

        $this->getJson("/my_api/pedidos/{$pedido->codigo}/pago", $cabeceras)
            ->assertOk()
            ->assertJsonPath('pedido_estado', Pedido::PAGADO);

        // Sin cobrador: lo pagó el cliente solo, no atendió nadie
        $this->assertDatabaseHas('facturas', [
            'pedido_id' => $pedido->id,
            'medio_pago' => 'mercadopago',
            'cobrado_por' => null,
        ]);
    }

    // ── El rechazo ───────────────────────────────────────────────────────────

    public function test_un_rechazo_no_cobra_nada(): void
    {
        $cajero = $this->cajero();
        $cliente = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);
        $pedido = $this->pedidoDe($cliente, $cerveza, 2);

        $this->actingAs($cajero)->post("/mostrador/pedidos/{$pedido->id}/mercadopago");

        $this->post("/checkout-simulado/{$pedido->codigo}", ['resultado' => 'rechazar'])
            ->assertRedirect();

        $this->assertSame(Pedido::PENDIENTE, $pedido->fresh()->estado);
        $this->assertSame(10, $cerveza->fresh()->stock);
        $this->assertDatabaseCount('facturas', 0);

        $pago = Pago::where('pedido_id', $pedido->id)->firstOrFail();
        $this->assertSame(Pago::RECHAZADO, $pago->estado);
    }

    public function test_un_resultado_inventado_se_rechaza(): void
    {
        $cajero = $this->cajero();
        $cliente = $this->crearUsuario();
        $pedido = $this->pedidoDe($cliente, $this->crearCerveza(['stock' => 10]));

        $this->actingAs($cajero)->post("/mostrador/pedidos/{$pedido->id}/mercadopago");

        $this->post("/checkout-simulado/{$pedido->codigo}", ['resultado' => 'gratis'])
            ->assertSessionHasErrors('resultado');

        $this->assertSame(Pedido::PENDIENTE, $pedido->fresh()->estado);
    }

    // ── Que no tome atajos ───────────────────────────────────────────────────

    public function test_aprobar_dos_veces_no_emite_dos_facturas(): void
    {
        $cajero = $this->cajero();
        $cliente = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);
        $pedido = $this->pedidoDe($cliente, $cerveza, 2);

        $this->actingAs($cajero)->post("/mostrador/pedidos/{$pedido->id}/mercadopago");

        $this->post("/checkout-simulado/{$pedido->codigo}", ['resultado' => 'aprobar']);
        $this->post("/checkout-simulado/{$pedido->codigo}", ['resultado' => 'aprobar']);
        $this->post("/checkout-simulado/{$pedido->codigo}", ['resultado' => 'aprobar']);

        $this->assertSame(8, $cerveza->fresh()->stock);
        $this->assertSame(1, Factura::where('pedido_id', $pedido->id)->count());
    }

    public function test_sin_un_cobro_iniciado_el_checkout_no_existe(): void
    {
        // Nadie tiene que poder abrir el checkout de un pedido que el cajero
        // todavía no puso a cobrar.
        $cliente = $this->crearUsuario();
        $pedido = $this->pedidoDe($cliente, $this->crearCerveza(['stock' => 10]));

        $this->get("/checkout-simulado/{$pedido->codigo}")->assertNotFound();
    }

    public function test_un_pedido_inexistente_da_404(): void
    {
        $this->get('/checkout-simulado/BV-NADA00')->assertNotFound();
    }

    public function test_el_checkout_avisa_que_es_una_simulacion(): void
    {
        // No se tiene que poder confundir nunca con una pantalla de pago real
        $cajero = $this->cajero();
        $cliente = $this->crearUsuario();
        $pedido = $this->pedidoDe($cliente, $this->crearCerveza(['stock' => 10]));

        $this->actingAs($cajero)->post("/mostrador/pedidos/{$pedido->id}/mercadopago");

        $this->get("/checkout-simulado/{$pedido->codigo}")
            ->assertOk()
            ->assertSee('Pago simulado')
            ->assertSee('no es Mercado Pago');
    }
}
