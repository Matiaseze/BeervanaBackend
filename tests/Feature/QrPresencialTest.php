<?php

namespace Tests\Feature;

use App\Models\Pago;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreaDatos;
use Tests\TestCase;

/**
 * Cobro con el QR presencial de Mercado Pago.
 *
 * Es el QR que se escanea con la app de MP, el mismo que usan los locales: el
 * monto va adentro del código, así que el cliente apunta y confirma.
 *
 * Convive con la preferencia (el link para pagar con tarjeta en el navegador).
 * Son dos objetos distintos en MP para el mismo pedido, y los dos vuelven
 * etiquetados con el código, así que da igual por cuál entre la plata.
 */
class QrPresencialTest extends TestCase
{
    use RefreshDatabase;
    use CreaDatos;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.mercadopago.modo', 'real');
        config()->set('services.mercadopago.access_token', 'TEST-token-de-prueba');
        config()->set('services.mercadopago.url_retorno', 'http://localhost:3000');
        config()->set('services.mercadopago.url_webhook', null);
        config()->set('services.mercadopago.user_id', '999');
        config()->set('services.mercadopago.caja', 'CAJA01');
    }

    private function fakeTodo(): void
    {
        Http::fake([
            'api.mercadopago.com/checkout/preferences' => Http::response([
                'id' => 'PREF-123',
                'init_point' => 'https://www.mercadopago.com.ar/checkout?pref_id=PREF-123',
            ]),
            'api.mercadopago.com/instore/*' => Http::response([
                'in_store_order_id' => 'ORDER-ABC',
                'qr_data' => '00020101021243650016com.mercadolibre0201306367bfef',
            ]),
        ]);
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

    // ── Se genera junto con el link ──────────────────────────────────────────

    public function test_el_cobro_trae_qr_y_link_a_la_vez(): void
    {
        // Las dos puertas para el mismo pedido: la app de MP y el navegador
        $this->fakeTodo();

        $cliente = $this->crearUsuario();
        $pedido = $this->pedidoDe($cliente, $this->crearCerveza(['stock' => 10]));

        $this->postJson("/my_api/pedidos/{$pedido->codigo}/mercadopago", [], $this->tokenDe($cliente))
            ->assertCreated()
            ->assertJsonPath('pago.qr_data', '00020101021243650016com.mercadolibre0201306367bfef');

        $this->assertDatabaseHas('pagos', [
            'pedido_id' => $pedido->id,
            'qr_data' => '00020101021243650016com.mercadolibre0201306367bfef',
            'in_store_order_id' => 'ORDER-ABC',
        ]);
    }

    public function test_el_qr_lleva_el_codigo_del_pedido_y_el_monto(): void
    {
        // external_reference es lo que después permite reconocer la venta
        // cuando MP avisa, sin depender de ids internos.
        $this->fakeTodo();

        $cliente = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10, 'precio' => 2500]);
        $pedido = $this->pedidoDe($cliente, $cerveza, 3);

        $this->postJson("/my_api/pedidos/{$pedido->codigo}/mercadopago", [], $this->tokenDe($cliente));

        Http::assertSent(function ($peticion) use ($pedido) {
            if (! str_contains($peticion->url(), '/instore/')) {
                return false;
            }

            $cuerpo = $peticion->data();

            return $cuerpo['external_reference'] === $pedido->codigo
                && (float) $cuerpo['total_amount'] === 7500.0
                && $cuerpo['items'][0]['quantity'] === 3;
        });
    }

    public function test_con_envio_el_total_del_qr_cierra_con_los_items(): void
    {
        // MP valida que total_amount sea EXACTAMENTE la suma de los ítems y si
        // no cierra rechaza el QR entero con un 400. El envío está adentro de
        // precio_total, así que tiene que viajar como ítem o el QR nunca sale.
        //
        // Este test existe porque el campo se llamaba mal (costo_envio en vez
        // de envio), el envío nunca se agregaba, y el QR fallaba en silencio
        // para todo pedido con envío.
        $this->fakeTodo();

        $cliente = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10, 'precio' => 2500]);

        $datos = $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 3]],
            'metodo_entrega' => 'envio',
        ], $this->tokenDe($cliente))->assertCreated()->json('pedido');
        $this->app['auth']->forgetGuards();
        $pedido = Pedido::find($datos['id']);

        // 3 × 2500 + envío
        $this->assertEquals(7500 + Pedido::COSTO_ENVIO, (float) $pedido->precio_total);

        $this->postJson("/my_api/pedidos/{$pedido->codigo}/mercadopago", [], $this->tokenDe($cliente))
            ->assertCreated()
            ->assertJsonPath('pago.qr_data', '00020101021243650016com.mercadolibre0201306367bfef');

        Http::assertSent(function ($peticion) use ($pedido) {
            if (! str_contains($peticion->url(), '/instore/')) {
                return false;
            }

            $cuerpo = $peticion->data();
            $sumaItems = array_sum(array_column($cuerpo['items'], 'total_amount'));

            return (float) $cuerpo['total_amount'] === (float) $pedido->precio_total
                && $sumaItems === (float) $cuerpo['total_amount']
                && collect($cuerpo['items'])->contains('title', 'Envío a domicilio');
        });
    }

    public function test_con_envio_la_preferencia_tambien_lo_cobra(): void
    {
        // En la preferencia MP no valida la suma: arma el total desde los
        // ítems. Sin el envío como ítem, el cliente pagaba la mercadería sola
        // y el envío quedaba sin cobrar. Silencioso y con plata en el medio.
        $this->fakeTodo();

        $cliente = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10, 'precio' => 2500]);

        $datos = $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 2]],
            'metodo_entrega' => 'envio',
        ], $this->tokenDe($cliente))->assertCreated()->json('pedido');
        $this->app['auth']->forgetGuards();
        $pedido = Pedido::find($datos['id']);

        $this->postJson("/my_api/pedidos/{$pedido->codigo}/mercadopago", [], $this->tokenDe($cliente))
            ->assertCreated();

        Http::assertSent(function ($peticion) use ($pedido) {
            if (! str_contains($peticion->url(), '/checkout/preferences')) {
                return false;
            }

            $items = $peticion->data()['items'];
            $total = array_sum(array_map(fn ($i) => $i['unit_price'] * $i['quantity'], $items));

            return (float) $total === (float) $pedido->precio_total
                && collect($items)->contains('id', 'envio');
        });
    }

    public function test_con_retiro_no_se_cobra_envio(): void
    {
        $this->fakeTodo();

        $cliente = $this->crearUsuario();
        $pedido = $this->pedidoDe($cliente, $this->crearCerveza(['stock' => 10]));

        $this->postJson("/my_api/pedidos/{$pedido->codigo}/mercadopago", [], $this->tokenDe($cliente));

        Http::assertSent(function ($peticion) {
            if (! str_contains($peticion->url(), '/instore/')) {
                return false;
            }

            return ! collect($peticion->data()['items'])->contains('title', 'Envío a domicilio');
        });
    }

    public function test_el_qr_va_a_la_caja_configurada(): void
    {
        $this->fakeTodo();

        $cliente = $this->crearUsuario();
        $pedido = $this->pedidoDe($cliente, $this->crearCerveza(['stock' => 10]));

        $this->postJson("/my_api/pedidos/{$pedido->codigo}/mercadopago", [], $this->tokenDe($cliente));

        Http::assertSent(fn ($peticion) => str_contains(
            $peticion->url(),
            '/instore/orders/qr/seller/collectors/999/pos/CAJA01/qrs'
        ));
    }

    // ── Que la falta de QR no voltee el cobro ────────────────────────────────

    public function test_sin_caja_configurada_el_cobro_sigue_funcionando(): void
    {
        // El QR es un extra: sin caja, queda el link de tarjeta y se cobra igual
        config()->set('services.mercadopago.caja', null);

        $this->fakeTodo();

        $cliente = $this->crearUsuario();
        $pedido = $this->pedidoDe($cliente, $this->crearCerveza(['stock' => 10]));

        $this->postJson("/my_api/pedidos/{$pedido->codigo}/mercadopago", [], $this->tokenDe($cliente))
            ->assertCreated()
            ->assertJsonPath('pago.qr_data', null)
            ->assertJsonStructure(['init_point']);

        // Ni se intentó pedirlo
        Http::assertNotSent(fn ($peticion) => str_contains($peticion->url(), '/instore/'));
    }

    public function test_si_mp_falla_al_dar_el_qr_el_cobro_no_se_cae(): void
    {
        // Que el QR falle no puede dejar al cliente sin poder pagar: le queda
        // el link del checkout, que es una vía completa por sí sola.
        Http::fake([
            'api.mercadopago.com/checkout/preferences' => Http::response([
                'id' => 'PREF-123',
                'init_point' => 'https://www.mercadopago.com.ar/checkout?pref_id=PREF-123',
            ]),
            'api.mercadopago.com/instore/*' => Http::response(['message' => 'error'], 500),
        ]);

        $cliente = $this->crearUsuario();
        $pedido = $this->pedidoDe($cliente, $this->crearCerveza(['stock' => 10]));

        $this->postJson("/my_api/pedidos/{$pedido->codigo}/mercadopago", [], $this->tokenDe($cliente))
            ->assertCreated()
            ->assertJsonPath('pago.qr_data', null)
            ->assertJsonPath('pago.init_point', 'https://www.mercadopago.com.ar/checkout?pref_id=PREF-123');
    }

    public function test_un_intento_que_quedo_sin_qr_lo_recupera_al_reintentar(): void
    {
        // El QR falló la primera vez (MP caído, caja mal configurada). Al
        // volver a apretar "pagar", el intento se reusa —sin crear otro— pero
        // se le completa el QR que faltaba.
        Http::fake([
            'api.mercadopago.com/checkout/preferences' => Http::response([
                'id' => 'PREF-123',
                'init_point' => 'https://www.mercadopago.com.ar/checkout?pref_id=PREF-123',
            ]),
            // El cliente HTTP reintenta 2 veces antes de rendirse, así que para
            // que el primer pedido falle de verdad hacen falta 3 respuestas malas.
            'api.mercadopago.com/instore/*' => Http::sequence()
                ->push(['message' => 'error'], 500)
                ->push(['message' => 'error'], 500)
                ->push(['message' => 'error'], 500)
                ->push(['in_store_order_id' => 'ORDER-2', 'qr_data' => 'TRAMA-RECUPERADA']),
        ]);

        $cliente = $this->crearUsuario();
        $pedido = $this->pedidoDe($cliente, $this->crearCerveza(['stock' => 10]));
        $cabeceras = $this->tokenDe($cliente);

        $this->postJson("/my_api/pedidos/{$pedido->codigo}/mercadopago", [], $cabeceras)
            ->assertCreated()
            ->assertJsonPath('pago.qr_data', null);

        $this->postJson("/my_api/pedidos/{$pedido->codigo}/mercadopago", [], $cabeceras)
            ->assertCreated()
            ->assertJsonPath('pago.qr_data', 'TRAMA-RECUPERADA');

        $this->assertSame(1, Pago::where('pedido_id', $pedido->id)->count());
    }

    // ── El mostrador ─────────────────────────────────────────────────────────

    public function test_el_mostrador_muestra_el_qr(): void
    {
        $this->fakeTodo();

        $cajero = $this->crearUsuario(['rol' => User::ROL_EMPLEADO]);
        $cliente = $this->crearUsuario();
        $pedido = $this->pedidoDe($cliente, $this->crearCerveza(['stock' => 10]));

        $this->actingAs($cajero)->post("/mostrador/pedidos/{$pedido->id}/mercadopago");

        $pago = Pago::where('pedido_id', $pedido->id)->firstOrFail();

        $this->actingAs($cajero)->get(route('mostrador.mp.esperar', $pago))
            ->assertOk()
            ->assertSee('app de Mercado Pago')
            ->assertSee('00020101021243650016com.mercadolibre0201306367bfef');
    }

    public function test_sin_qr_el_mostrador_explica_como_habilitarlo(): void
    {
        config()->set('services.mercadopago.caja', null);
        $this->fakeTodo();

        $cajero = $this->crearUsuario(['rol' => User::ROL_EMPLEADO]);
        $cliente = $this->crearUsuario();
        $pedido = $this->pedidoDe($cliente, $this->crearCerveza(['stock' => 10]));

        $this->actingAs($cajero)->post("/mostrador/pedidos/{$pedido->id}/mercadopago");
        $pago = Pago::where('pedido_id', $pedido->id)->firstOrFail();

        $this->actingAs($cajero)->get(route('mostrador.mp.esperar', $pago))
            ->assertOk()
            ->assertSee('mp:caja');
    }

    // ── El pago por QR se acredita igual ─────────────────────────────────────

    public function test_un_pago_hecho_por_qr_cierra_la_venta(): void
    {
        // Da igual por qué puerta entró: el pago vuelve con el código del
        // pedido y el resto del sistema no distingue.
        $cajero = $this->crearUsuario(['rol' => User::ROL_EMPLEADO]);
        $cliente = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);
        $pedido = $this->pedidoDe($cliente, $cerveza, 2);

        $this->fakeTodo();
        $this->actingAs($cajero)->post("/mostrador/pedidos/{$pedido->id}/mercadopago");

        Http::fake([
            'api.mercadopago.com/v1/payments/555' => Http::response([
                'id' => 555,
                'status' => 'approved',
                'status_detail' => 'accredited',
                'external_reference' => $pedido->codigo,
            ]),
        ]);

        $this->postJson('/my_api/webhooks/mercadopago', [
            'type' => 'payment',
            'data' => ['id' => '555'],
        ])->assertOk();

        $this->assertSame(Pedido::PAGADO, $pedido->fresh()->estado);
        $this->assertSame(8, $cerveza->fresh()->stock);
        $this->assertDatabaseHas('facturas', [
            'pedido_id' => $pedido->id,
            'medio_pago' => 'mercadopago',
            'cobrado_por' => $cajero->id,
        ]);
    }
}
