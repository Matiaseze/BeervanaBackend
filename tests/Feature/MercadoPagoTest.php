<?php

namespace Tests\Feature;

use App\Models\Factura;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreaDatos;
use Tests\TestCase;

/**
 * Cobro por Mercado Pago.
 *
 * La API de MP se simula con Http::fake: los tests no pueden depender de una
 * cuenta externa, de internet ni de que el sandbox de MP esté de buen humor.
 * Lo que se prueba es lo nuestro: que un pago aprobado descuente stock y emita
 * factura una sola vez, y que nada de eso pase sin que MP lo confirme.
 */
class MercadoPagoTest extends TestCase
{
    use RefreshDatabase;
    use CreaDatos;

    protected function setUp(): void
    {
        parent::setUp();

        // Se fija el modo en vez de heredarlo del .env: este archivo prueba el
        // cliente que habla con la API de MP, así que tiene que correr igual
        // esté como esté configurado el entorno de quien lo ejecuta.
        config()->set('services.mercadopago.modo', 'real');
        config()->set('services.mercadopago.access_token', 'TEST-token-de-prueba');
        config()->set('services.mercadopago.url_retorno', 'http://localhost:3000');
        config()->set('services.mercadopago.url_webhook', null);
        // Sin caja: este archivo prueba la preferencia. El QR presencial tiene
        // su propio archivo, y dejarlo activo acá metería una segunda petición
        // a MP en cada test, que después hay que esquivar en cada aserción.
        config()->set('services.mercadopago.user_id', null);
        config()->set('services.mercadopago.caja', null);
    }

    /** Respuesta de MP al crear una preferencia. */
    private function fakePreferencia(): array
    {
        return [
            'id' => 'PREF-123',
            'init_point' => 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=PREF-123',
            'sandbox_init_point' => 'https://sandbox.mercadopago.com.ar/checkout?pref_id=PREF-123',
        ];
    }

    /** Respuesta de MP al consultar un pago. */
    private function fakePago(string $codigo, string $estado = 'approved'): array
    {
        return [
            'id' => 987654321,
            'status' => $estado,
            'status_detail' => $estado === 'approved' ? 'accredited' : 'cc_rejected_other_reason',
            'external_reference' => $codigo,
        ];
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

    // ── El cliente paga desde la tienda ──────────────────────────────────────

    public function test_el_cliente_obtiene_el_link_de_pago(): void
    {
        Http::fake(['api.mercadopago.com/checkout/preferences' => Http::response($this->fakePreferencia())]);

        $cliente = $this->crearUsuario();
        $pedido = $this->pedidoDe($cliente, $this->crearCerveza(['stock' => 10]));

        $this->postJson("/my_api/pedidos/{$pedido->codigo}/mercadopago", [], $this->tokenDe($cliente))
            ->assertCreated()
            ->assertJsonPath('pago.estado', Pago::PENDIENTE)
            ->assertJsonStructure(['init_point']);

        $this->assertDatabaseHas('pagos', [
            'pedido_id' => $pedido->id,
            'origen' => Pago::ORIGEN_TIENDA,
            'preference_id' => 'PREF-123',
        ]);
    }

    public function test_crear_el_link_no_cobra_nada_todavia(): void
    {
        // Es el punto de todo: la preferencia es una intención, no una venta.
        Http::fake(['api.mercadopago.com/checkout/preferences' => Http::response($this->fakePreferencia())]);

        $cliente = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);
        $pedido = $this->pedidoDe($cliente, $cerveza);

        $this->postJson("/my_api/pedidos/{$pedido->codigo}/mercadopago", [], $this->tokenDe($cliente))
            ->assertCreated();

        $this->assertSame(Pedido::PENDIENTE, $pedido->fresh()->estado);
        $this->assertSame(10, $cerveza->fresh()->stock);
        $this->assertDatabaseCount('facturas', 0);
    }

    public function test_no_se_puede_pagar_el_pedido_de_otro(): void
    {
        Http::fake(['api.mercadopago.com/*' => Http::response($this->fakePreferencia())]);

        $duenio = $this->crearUsuario();
        $pedido = $this->pedidoDe($duenio, $this->crearCerveza(['stock' => 10]));
        $ajeno = $this->crearUsuario();

        $this->postJson("/my_api/pedidos/{$pedido->codigo}/mercadopago", [], $this->tokenDe($ajeno))
            ->assertNotFound();

        $this->assertDatabaseCount('pagos', 0);
    }

    public function test_dos_clicks_seguidos_no_generan_dos_cobros(): void
    {
        // Dos preferencias abiertas para el mismo pedido es la receta para que
        // alguien termine pagando dos veces.
        Http::fake(['api.mercadopago.com/checkout/preferences' => Http::response($this->fakePreferencia())]);

        $cliente = $this->crearUsuario();
        $pedido = $this->pedidoDe($cliente, $this->crearCerveza(['stock' => 10]));
        $cabeceras = $this->tokenDe($cliente);

        $this->postJson("/my_api/pedidos/{$pedido->codigo}/mercadopago", [], $cabeceras)->assertCreated();
        $this->postJson("/my_api/pedidos/{$pedido->codigo}/mercadopago", [], $cabeceras)->assertCreated();

        $this->assertSame(1, Pago::where('pedido_id', $pedido->id)->count());
    }

    public function test_un_pedido_vencido_no_se_puede_pagar(): void
    {
        Http::fake(['api.mercadopago.com/*' => Http::response($this->fakePreferencia())]);

        $cliente = $this->crearUsuario();
        $pedido = $this->pedidoDe($cliente, $this->crearCerveza(['stock' => 10]));

        $this->travel(Pedido::HORAS_VENCIMIENTO + 1)->hours();

        $this->postJson("/my_api/pedidos/{$pedido->codigo}/mercadopago", [], $this->tokenDe($cliente))
            ->assertStatus(409);

        // No se llegó ni a pedirle una preferencia a MP
        $this->assertDatabaseCount('pagos', 0);
    }

    public function test_sin_credenciales_avisa_en_vez_de_romper(): void
    {
        config()->set('services.mercadopago.access_token', null);

        $cliente = $this->crearUsuario();
        $pedido = $this->pedidoDe($cliente, $this->crearCerveza(['stock' => 10]));

        $this->postJson("/my_api/pedidos/{$pedido->codigo}/mercadopago", [], $this->tokenDe($cliente))
            ->assertStatus(503)
            ->assertJsonPath('error', 'Mercado Pago no está configurado en este entorno.');
    }

    // ── Lo que se le manda a MP ──────────────────────────────────────────────

    public function test_en_local_no_se_mandan_back_urls(): void
    {
        // MP rechaza explícitamente localhost y 127.0.0.1 en back_urls, y no
        // ignora el campo: falla la preferencia entera. Mandarlas "por las
        // dudas" en desarrollo rompe el pago en vez de mejorarlo.
        config()->set('services.mercadopago.url_retorno', 'http://localhost:3000');

        Http::fake(['api.mercadopago.com/checkout/preferences' => Http::response($this->fakePreferencia())]);

        $cliente = $this->crearUsuario();
        $pedido = $this->pedidoDe($cliente, $this->crearCerveza(['stock' => 10]));

        $this->postJson("/my_api/pedidos/{$pedido->codigo}/mercadopago", [], $this->tokenDe($cliente))
            ->assertCreated();

        Http::assertSent(function ($peticion) {
            $cuerpo = $peticion->data();

            return ! isset($cuerpo['back_urls'])
                && ! isset($cuerpo['auto_return'])
                && ! isset($cuerpo['notification_url']);
        });
    }

    public function test_con_un_dominio_publico_si_se_mandan(): void
    {
        // Con ngrok o con el dominio de verdad, esto se activa solo
        config()->set('services.mercadopago.url_retorno', 'https://beervana.vercel.app');
        config()->set('services.mercadopago.url_webhook', 'https://abc123.ngrok-free.app/my_api/webhooks/mercadopago');

        Http::fake(['api.mercadopago.com/checkout/preferences' => Http::response($this->fakePreferencia())]);

        $cliente = $this->crearUsuario();
        $pedido = $this->pedidoDe($cliente, $this->crearCerveza(['stock' => 10]));

        $this->postJson("/my_api/pedidos/{$pedido->codigo}/mercadopago", [], $this->tokenDe($cliente))
            ->assertCreated();

        Http::assertSent(function ($peticion) use ($pedido) {
            $cuerpo = $peticion->data();

            return $cuerpo['back_urls']['success'] === "https://beervana.vercel.app/pedidos/{$pedido->codigo}?pago=exito"
                && $cuerpo['auto_return'] === 'approved'
                && $cuerpo['notification_url'] === 'https://abc123.ngrok-free.app/my_api/webhooks/mercadopago';
        });
    }

    public function test_por_defecto_se_usa_el_checkout_de_produccion(): void
    {
        // Es el camino de pagar como invitado con tarjeta de prueba: el
        // vendedor está en modo prueba por el token y no hay comprador logueado
        // que pueda no coincidir.
        config()->set('services.mercadopago.usar_sandbox', false);

        Http::fake(['api.mercadopago.com/checkout/preferences' => Http::response($this->fakePreferencia())]);

        $cliente = $this->crearUsuario();
        $pedido = $this->pedidoDe($cliente, $this->crearCerveza(['stock' => 10]));

        $this->postJson("/my_api/pedidos/{$pedido->codigo}/mercadopago", [], $this->tokenDe($cliente))
            ->assertCreated()
            ->assertJsonPath('init_point', 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=PREF-123');
    }

    public function test_con_usuarios_de_prueba_se_usa_el_sandbox(): void
    {
        // Las cuentas de prueba viven solo en sandbox.mercadopago.com. Este
        // camino además exige que el vendedor sea otro usuario de prueba.
        config()->set('services.mercadopago.usar_sandbox', true);

        Http::fake(['api.mercadopago.com/checkout/preferences' => Http::response($this->fakePreferencia())]);

        $cliente = $this->crearUsuario();
        $pedido = $this->pedidoDe($cliente, $this->crearCerveza(['stock' => 10]));

        $this->postJson("/my_api/pedidos/{$pedido->codigo}/mercadopago", [], $this->tokenDe($cliente))
            ->assertCreated()
            ->assertJsonPath('init_point', 'https://sandbox.mercadopago.com.ar/checkout?pref_id=PREF-123');
    }

    public function test_el_codigo_del_pedido_viaja_como_referencia_externa(): void
    {
        // Es lo que permite reconocer a qué venta corresponde un pago cuando MP
        // avisa, sin depender de nuestros ids internos.
        Http::fake(['api.mercadopago.com/checkout/preferences' => Http::response($this->fakePreferencia())]);

        $cliente = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10, 'precio' => 2500]);
        $pedido = $this->pedidoDe($cliente, $cerveza, 3);

        $this->postJson("/my_api/pedidos/{$pedido->codigo}/mercadopago", [], $this->tokenDe($cliente));

        Http::assertSent(function ($peticion) use ($pedido) {
            $cuerpo = $peticion->data();

            return $cuerpo['external_reference'] === $pedido->codigo
                && $cuerpo['items'][0]['quantity'] === 3
                && (float) $cuerpo['items'][0]['unit_price'] === 2500.0;
        });
    }

    // ── Un pago aprobado cierra la venta ─────────────────────────────────────

    public function test_el_pago_aprobado_descuenta_stock_y_emite_factura(): void
    {
        $cliente = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);
        $pedido = $this->pedidoDe($cliente, $cerveza, 3);

        Http::fake([
            'api.mercadopago.com/checkout/preferences' => Http::response($this->fakePreferencia()),
            'api.mercadopago.com/v1/payments/search*' => Http::response([
                'results' => [$this->fakePago($pedido->codigo)],
            ]),
        ]);

        $cabeceras = $this->tokenDe($cliente);
        $this->postJson("/my_api/pedidos/{$pedido->codigo}/mercadopago", [], $cabeceras)->assertCreated();

        // Es la consulta que hace el frontend al volver del checkout
        $this->getJson("/my_api/pedidos/{$pedido->codigo}/pago", $cabeceras)
            ->assertOk()
            ->assertJsonPath('pago.estado', Pago::APROBADO)
            ->assertJsonPath('pedido_estado', Pedido::PAGADO);

        $this->assertSame(7, $cerveza->fresh()->stock);
        $this->assertDatabaseHas('facturas', [
            'pedido_id' => $pedido->id,
            'medio_pago' => 'mercadopago',
            'cobrado_por' => null, // lo pagó el cliente solo, no atendió nadie
        ]);
    }

    public function test_consultar_dos_veces_no_emite_dos_facturas(): void
    {
        $cliente = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);
        $pedido = $this->pedidoDe($cliente, $cerveza, 2);

        Http::fake([
            'api.mercadopago.com/checkout/preferences' => Http::response($this->fakePreferencia()),
            'api.mercadopago.com/v1/payments/search*' => Http::response([
                'results' => [$this->fakePago($pedido->codigo)],
            ]),
        ]);

        $cabeceras = $this->tokenDe($cliente);
        $this->postJson("/my_api/pedidos/{$pedido->codigo}/mercadopago", [], $cabeceras);

        $this->getJson("/my_api/pedidos/{$pedido->codigo}/pago", $cabeceras)->assertOk();
        $this->getJson("/my_api/pedidos/{$pedido->codigo}/pago", $cabeceras)->assertOk();
        $this->getJson("/my_api/pedidos/{$pedido->codigo}/pago", $cabeceras)->assertOk();

        $this->assertSame(1, Factura::where('pedido_id', $pedido->id)->count());
        $this->assertSame(8, $cerveza->fresh()->stock);
    }

    public function test_un_pago_rechazado_no_cobra_nada(): void
    {
        $cliente = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);
        $pedido = $this->pedidoDe($cliente, $cerveza, 2);

        Http::fake([
            'api.mercadopago.com/checkout/preferences' => Http::response($this->fakePreferencia()),
            'api.mercadopago.com/v1/payments/search*' => Http::response([
                'results' => [$this->fakePago($pedido->codigo, 'rejected')],
            ]),
        ]);

        $cabeceras = $this->tokenDe($cliente);
        $this->postJson("/my_api/pedidos/{$pedido->codigo}/mercadopago", [], $cabeceras);

        $this->getJson("/my_api/pedidos/{$pedido->codigo}/pago", $cabeceras)
            ->assertOk()
            ->assertJsonPath('pago.estado', Pago::RECHAZADO)
            ->assertJsonPath('pedido_estado', Pedido::PENDIENTE);

        $this->assertSame(10, $cerveza->fresh()->stock);
        $this->assertDatabaseCount('facturas', 0);
    }

    public function test_un_pago_en_revision_todavia_no_cierra_la_venta(): void
    {
        $cliente = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);
        $pedido = $this->pedidoDe($cliente, $cerveza, 2);

        Http::fake([
            'api.mercadopago.com/checkout/preferences' => Http::response($this->fakePreferencia()),
            'api.mercadopago.com/v1/payments/search*' => Http::response([
                'results' => [$this->fakePago($pedido->codigo, 'in_process')],
            ]),
        ]);

        $cabeceras = $this->tokenDe($cliente);
        $this->postJson("/my_api/pedidos/{$pedido->codigo}/mercadopago", [], $cabeceras);

        $this->getJson("/my_api/pedidos/{$pedido->codigo}/pago", $cabeceras)
            ->assertJsonPath('pago.estado', Pago::EN_PROCESO);

        $this->assertSame(10, $cerveza->fresh()->stock);
        $this->assertDatabaseCount('facturas', 0);
    }

    // ── El webhook ───────────────────────────────────────────────────────────

    public function test_el_webhook_acredita_el_pago(): void
    {
        $cliente = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);
        $pedido = $this->pedidoDe($cliente, $cerveza, 2);

        Http::fake([
            'api.mercadopago.com/v1/payments/987654321' => Http::response($this->fakePago($pedido->codigo)),
        ]);

        $this->postJson('/my_api/webhooks/mercadopago', [
            'type' => 'payment',
            'data' => ['id' => '987654321'],
        ])->assertOk();

        $this->assertSame(Pedido::PAGADO, $pedido->fresh()->estado);
        $this->assertSame(8, $cerveza->fresh()->stock);
        $this->assertDatabaseCount('facturas', 1);
    }

    public function test_el_webhook_repetido_no_cobra_dos_veces(): void
    {
        // MP reintenta el mismo aviso varias veces. El segundo no puede volver a
        // descontar stock ni emitir otra factura.
        $cliente = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);
        $pedido = $this->pedidoDe($cliente, $cerveza, 2);

        Http::fake([
            'api.mercadopago.com/v1/payments/987654321' => Http::response($this->fakePago($pedido->codigo)),
        ]);

        $aviso = ['type' => 'payment', 'data' => ['id' => '987654321']];

        $this->postJson('/my_api/webhooks/mercadopago', $aviso)->assertOk();
        $this->postJson('/my_api/webhooks/mercadopago', $aviso)->assertOk();
        $this->postJson('/my_api/webhooks/mercadopago', $aviso)->assertOk();

        $this->assertSame(8, $cerveza->fresh()->stock);
        $this->assertSame(1, Factura::where('pedido_id', $pedido->id)->count());
    }

    public function test_el_webhook_no_le_cree_al_estado_que_le_mandan(): void
    {
        // Lo más importante de todo: la ruta es pública. Si alcanzara con mandar
        // un "approved" en el cuerpo, cualquiera se lleva la mercadería gratis.
        $cliente = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);
        $pedido = $this->pedidoDe($cliente, $cerveza, 2);

        // MP, consultado de verdad, dice que ese pago fue rechazado
        Http::fake([
            'api.mercadopago.com/v1/payments/987654321' => Http::response(
                $this->fakePago($pedido->codigo, 'rejected')
            ),
        ]);

        $this->postJson('/my_api/webhooks/mercadopago', [
            'type' => 'payment',
            'data' => ['id' => '987654321'],
            'status' => 'approved',           // mentira del atacante
            'external_reference' => $pedido->codigo,
        ])->assertOk();

        $this->assertSame(Pedido::PENDIENTE, $pedido->fresh()->estado);
        $this->assertSame(10, $cerveza->fresh()->stock);
        $this->assertDatabaseCount('facturas', 0);
    }

    public function test_un_aviso_de_un_pago_inexistente_no_rompe(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/*' => Http::response([], 404)]);

        $this->postJson('/my_api/webhooks/mercadopago', [
            'type' => 'payment',
            'data' => ['id' => '000'],
        ])->assertOk();

        $this->assertDatabaseCount('facturas', 0);
    }

    public function test_los_avisos_que_no_son_de_pago_se_ignoran(): void
    {
        Http::fake();

        $this->postJson('/my_api/webhooks/mercadopago', [
            'type' => 'merchant_order',
            'data' => ['id' => '123'],
        ])->assertOk();

        // Ni siquiera se le preguntó nada a MP
        Http::assertNothingSent();
    }

    // ── El cobro desde el mostrador ──────────────────────────────────────────

    public function test_el_cajero_genera_el_cobro_y_ve_la_pantalla_de_espera(): void
    {
        $cajero = $this->crearUsuario(['rol' => User::ROL_EMPLEADO]);
        $cliente = $this->crearUsuario();
        $pedido = $this->pedidoDe($cliente, $this->crearCerveza(['stock' => 10]));

        Http::fake(['api.mercadopago.com/checkout/preferences' => Http::response($this->fakePreferencia())]);

        $respuesta = $this->actingAs($cajero)
            ->post("/mostrador/pedidos/{$pedido->id}/mercadopago")
            ->assertRedirect();

        $this->actingAs($cajero)->get($respuesta->headers->get('Location'))
            ->assertOk()
            ->assertSee($pedido->codigo)
            ->assertSee('Esperando que el cliente pague');

        $this->assertDatabaseHas('pagos', [
            'pedido_id' => $pedido->id,
            'origen' => Pago::ORIGEN_MOSTRADOR,
            'iniciado_por' => $cajero->id,
        ]);
    }

    public function test_el_cobro_del_mostrador_queda_a_nombre_del_cajero(): void
    {
        // Es lo que hace que la venta aparezca en "Mis cobros" del turno: la
        // plata entró por MP, pero quien atendió fue él.
        $cajero = $this->crearUsuario(['rol' => User::ROL_EMPLEADO]);
        $cliente = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);
        $pedido = $this->pedidoDe($cliente, $cerveza, 2);

        Http::fake([
            'api.mercadopago.com/checkout/preferences' => Http::response($this->fakePreferencia()),
            'api.mercadopago.com/v1/payments/search*' => Http::response([
                'results' => [$this->fakePago($pedido->codigo)],
            ]),
        ]);

        $this->actingAs($cajero)->post("/mostrador/pedidos/{$pedido->id}/mercadopago");

        $pago = Pago::where('pedido_id', $pedido->id)->firstOrFail();

        $this->actingAs($cajero)->getJson(route('mostrador.mp.estado', $pago))
            ->assertOk()
            ->assertJsonPath('listo', true);

        $this->assertDatabaseHas('facturas', [
            'pedido_id' => $pedido->id,
            'cobrado_por' => $cajero->id,
            'medio_pago' => 'mercadopago',
        ]);
        $this->assertSame(8, $cerveza->fresh()->stock);
    }

    public function test_un_cliente_no_puede_generar_cobros_desde_el_mostrador(): void
    {
        $cliente = $this->crearUsuario();
        $pedido = $this->pedidoDe($cliente, $this->crearCerveza(['stock' => 10]));

        $this->actingAs($cliente)
            ->post("/mostrador/pedidos/{$pedido->id}/mercadopago")
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('pagos', 0);
    }

    public function test_cancelar_la_espera_no_toca_el_pedido(): void
    {
        // La preferencia sigue viva en MP: si el cliente ya escaneó y paga
        // igual, ese pago tiene que poder acreditarse.
        $cajero = $this->crearUsuario(['rol' => User::ROL_EMPLEADO]);
        $cliente = $this->crearUsuario();
        $pedido = $this->pedidoDe($cliente, $this->crearCerveza(['stock' => 10]));

        Http::fake(['api.mercadopago.com/checkout/preferences' => Http::response($this->fakePreferencia())]);

        $this->actingAs($cajero)->post("/mostrador/pedidos/{$pedido->id}/mercadopago");
        $pago = Pago::where('pedido_id', $pedido->id)->firstOrFail();

        $this->actingAs($cajero)->post(route('mostrador.mp.cancelar', $pago))
            ->assertRedirect(route('mostrador.index'))
            ->assertSessionHas('exito');

        $this->assertSame(Pago::CANCELADO, $pago->fresh()->estado);
        $this->assertSame(Pedido::PENDIENTE, $pedido->fresh()->estado);
    }

    public function test_el_mostrador_avisa_que_ya_se_pago_online(): void
    {
        // Para que el cajero no le cobre de nuevo a alguien que ya pagó
        $cajero = $this->crearUsuario(['rol' => User::ROL_EMPLEADO]);
        $cliente = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);
        $pedido = $this->pedidoDe($cliente, $cerveza, 2);

        Http::fake([
            'api.mercadopago.com/v1/payments/987654321' => Http::response($this->fakePago($pedido->codigo)),
        ]);

        $this->postJson('/my_api/webhooks/mercadopago', [
            'type' => 'payment',
            'data' => ['id' => '987654321'],
        ])->assertOk();

        $this->actingAs($cajero)
            ->get(route('mostrador.index', ['codigo' => $pedido->codigo]))
            ->assertOk()
            ->assertSee('Pagado con Mercado Pago')
            ->assertSee('No cobres nada');
    }
}
