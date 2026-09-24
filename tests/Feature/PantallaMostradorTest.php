<?php

namespace Tests\Feature;

use App\Models\Pedido;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreaDatos;
use Tests\TestCase;

/**
 * Pantalla de cobro del panel.
 *
 * La usa quien atiende, que puede ser un empleado sin permisos de admin: cobrar
 * no requiere poder editar el catálogo ni ver la facturación del negocio.
 */
class PantallaMostradorTest extends TestCase
{
    use RefreshDatabase;
    use CreaDatos;

    private function pedidoDe($cliente, $cerveza, int $cantidad = 2): Pedido
    {
        $id = $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => $cantidad]],
            'metodo_entrega' => 'retiro',
        ], $this->tokenDe($cliente))->assertCreated()->json('pedido.id');

        $this->app['auth']->forgetGuards();

        return Pedido::find($id);
    }

    // ── Quién entra ──────────────────────────────────────────────────────────

    public function test_un_empleado_puede_usar_el_mostrador(): void
    {
        $empleado = $this->crearUsuario(['rol' => User::ROL_EMPLEADO]);

        $this->actingAs($empleado)->get('/mostrador')->assertOk()->assertSee('Mostrador');
    }

    public function test_un_admin_tambien(): void
    {
        $admin = $this->crearUsuario(['rol' => User::ROL_ADMIN]);

        $this->actingAs($admin)->get('/mostrador')->assertOk();
    }

    public function test_un_cliente_no_entra_al_mostrador(): void
    {
        $cliente = $this->crearUsuario(['rol' => User::ROL_CLIENTE]);

        $this->actingAs($cliente)->get('/mostrador')->assertRedirect(route('login'));
    }

    public function test_el_empleado_no_entra_al_dashboard(): void
    {
        // Cobrar sí, ver la facturación del negocio no: es el punto de separar
        // el rol del booleano is_admin que había antes.
        $empleado = $this->crearUsuario(['rol' => User::ROL_EMPLEADO]);

        // Lo devuelve al mostrador, que es su pantalla, y no a /login: rebotarlo
        // a la pantalla de login teniendo sesión abierta armaba un bucle.
        $this->actingAs($empleado)->get('/dashboard')->assertRedirect(route('mostrador.index'));

        $this->assertAuthenticatedAs($empleado);
    }

    // ── Los dos grupos de pedidos ────────────────────────────────────────────

    public function test_separa_los_confirmados_de_los_que_no_tomaron_accion(): void
    {
        $cerveza = $this->crearCerveza(['stock' => 50]);
        $vieneAPagar = $this->crearUsuario(['name' => 'Cliente Confirmado']);
        $indeciso = $this->crearUsuario(['name' => 'Cliente Indeciso']);

        $confirmado = $this->pedidoDe($vieneAPagar, $cerveza);
        $this->postJson('/my_api/pedidos/'.$confirmado->codigo.'/confirmar', [], $this->tokenDe($vieneAPagar))
            ->assertOk();
        $this->app['auth']->forgetGuards();

        $this->pedidoDe($indeciso, $cerveza);

        $empleado = $this->crearUsuario(['rol' => User::ROL_EMPLEADO]);
        $respuesta = $this->actingAs($empleado)->get('/mostrador')->assertOk();

        // Para quien atiende no es lo mismo: uno avisó que viene, el otro no
        $respuesta->assertSee('Esperando pago en el local')
            ->assertSee('Sin confirmar')
            ->assertSee('Cliente Confirmado')
            ->assertSee('Cliente Indeciso');
    }

    public function test_muestra_el_tiempo_que_le_queda_al_cliente(): void
    {
        $cliente = $this->crearUsuario();
        $this->pedidoDe($cliente, $this->crearCerveza(['stock' => 10]));

        $empleado = $this->crearUsuario(['rol' => User::ROL_EMPLEADO]);

        // 72 horas = 3 días
        $this->actingAs($empleado)->get('/mostrador')->assertOk()->assertSee('2d 23h');
    }

    public function test_los_pedidos_vencidos_no_aparecen(): void
    {
        $cliente = $this->crearUsuario(['name' => 'Cliente Vencido']);
        $this->pedidoDe($cliente, $this->crearCerveza(['stock' => 10]));

        $this->travel(Pedido::HORAS_VENCIMIENTO + 1)->hours();

        $empleado = $this->crearUsuario(['rol' => User::ROL_EMPLEADO]);

        // Ya no reservan stock, así que no hay nada que cobrar
        $this->actingAs($empleado)->get('/mostrador')->assertOk()->assertDontSee('Cliente Vencido');
    }

    // ── Buscar y cobrar ──────────────────────────────────────────────────────

    public function test_buscar_por_codigo_muestra_el_pedido_y_a_quien_pertenece(): void
    {
        $cliente = $this->crearUsuario(['name' => 'Matías Ezequiel']);
        $cerveza = $this->crearCerveza(['nombre' => 'Andes IPA', 'precio' => 2500.00, 'stock' => 10]);
        $pedido = $this->pedidoDe($cliente, $cerveza, 2);

        $empleado = $this->crearUsuario(['rol' => User::ROL_EMPLEADO]);

        $this->actingAs($empleado)
            ->get('/mostrador?codigo='.$pedido->codigo)
            ->assertOk()
            ->assertSee($pedido->codigo)
            ->assertSee('Matías Ezequiel')
            ->assertSee('Andes IPA')
            ->assertSee('5,000.00')
            // El importe que vale es el del sistema, no el del papel del cliente
            ->assertSee('Cobrá este importe, no el impreso en el comprobante.');
    }

    public function test_un_codigo_inexistente_avisa_y_no_rompe(): void
    {
        $empleado = $this->crearUsuario(['rol' => User::ROL_EMPLEADO]);

        $this->actingAs($empleado)
            ->get('/mostrador?codigo=BV-ZZZZZZ')
            ->assertOk()
            ->assertSee('No hay ningún pedido con el código');
    }

    public function test_cobrar_descuenta_stock_y_emite_la_factura(): void
    {
        $cliente = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['precio' => 2500.00, 'stock' => 10]);
        $pedido = $this->pedidoDe($cliente, $cerveza, 2);
        $empleado = $this->crearUsuario(['rol' => User::ROL_EMPLEADO]);

        $this->actingAs($empleado)
            ->post('/mostrador/pedidos/'.$pedido->id.'/cobrar')
            ->assertRedirect(route('mostrador.index'))
            ->assertSessionHas('exito');

        $this->assertSame(8, $cerveza->fresh()->stock);
        $this->assertSame(Pedido::PAGADO, $pedido->fresh()->estado);
        $this->assertDatabaseHas('facturas', [
            'pedido_id' => $pedido->id,
            'user_id' => $cliente->id, // a nombre del cliente, no del empleado
        ]);
    }

    public function test_se_puede_cobrar_un_pedido_confirmado(): void
    {
        $cliente = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);
        $pedido = $this->pedidoDe($cliente, $cerveza, 2);

        $this->postJson('/my_api/pedidos/'.$pedido->codigo.'/confirmar', [], $this->tokenDe($cliente))->assertOk();
        $this->app['auth']->forgetGuards();

        $empleado = $this->crearUsuario(['rol' => User::ROL_EMPLEADO]);
        $this->actingAs($empleado)
            ->post('/mostrador/pedidos/'.$pedido->id.'/cobrar')
            ->assertSessionHas('exito');

        $this->assertSame(Pedido::PAGADO, $pedido->fresh()->estado);
    }

    public function test_no_se_cobra_dos_veces(): void
    {
        $cliente = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);
        $pedido = $this->pedidoDe($cliente, $cerveza, 2);
        $empleado = $this->crearUsuario(['rol' => User::ROL_EMPLEADO]);

        $this->actingAs($empleado)->post('/mostrador/pedidos/'.$pedido->id.'/cobrar');
        $this->actingAs($empleado)
            ->post('/mostrador/pedidos/'.$pedido->id.'/cobrar')
            ->assertSessionHas('error');

        $this->assertSame(8, $cerveza->fresh()->stock);
        $this->assertDatabaseCount('facturas', 1);
    }

    public function test_no_se_cobra_una_reserva_vencida(): void
    {
        $cliente = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);
        $pedido = $this->pedidoDe($cliente, $cerveza, 2);
        $empleado = $this->crearUsuario(['rol' => User::ROL_EMPLEADO]);

        $this->travel(Pedido::HORAS_VENCIMIENTO + 1)->hours();

        $this->actingAs($empleado)
            ->post('/mostrador/pedidos/'.$pedido->id.'/cobrar')
            ->assertSessionHas('error');

        $this->assertDatabaseCount('facturas', 0);
        $this->assertSame(10, $cerveza->fresh()->stock);
    }

    public function test_un_cliente_no_puede_cobrar_desde_el_panel(): void
    {
        $cliente = $this->crearUsuario(['rol' => User::ROL_CLIENTE]);
        $cerveza = $this->crearCerveza(['stock' => 10]);
        $pedido = $this->pedidoDe($cliente, $cerveza, 2);

        $this->actingAs($cliente)
            ->post('/mostrador/pedidos/'.$pedido->id.'/cobrar')
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('facturas', 0);
        $this->assertSame(10, $cerveza->fresh()->stock);
    }
}
