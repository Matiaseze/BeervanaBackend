<?php

namespace Tests\Feature;

use App\Models\Factura;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreaDatos;
use Tests\TestCase;

/**
 * "Mis cobros": el arqueo de caja de quien atiende.
 *
 * Cada empleado ve solo lo que cobró él. El admin puede mirar la caja de
 * cualquiera, porque necesita el control del negocio.
 */
class MisCobrosTest extends TestCase
{
    use RefreshDatabase;
    use CreaDatos;

    /** Crea un pedido de un cliente y lo cobra como el empleado indicado. */
    private function cobrarComo(User $empleado, $cliente, $cerveza, int $cantidad = 2): Pedido
    {
        $id = $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => $cantidad]],
            'metodo_entrega' => 'retiro',
        ], $this->tokenDe($cliente))->assertCreated()->json('pedido.id');

        $this->app['auth']->forgetGuards();
        $this->actingAs($empleado)->post("/mostrador/pedidos/{$id}/cobrar");
        $this->app['auth']->forgetGuards();

        return Pedido::find($id);
    }

    public function test_la_factura_guarda_quien_cobro(): void
    {
        $empleado = $this->crearUsuario(['rol' => User::ROL_EMPLEADO]);
        $cliente = $this->crearUsuario();
        $pedido = $this->cobrarComo($empleado, $cliente, $this->crearCerveza(['stock' => 10]));

        $factura = Factura::where('pedido_id', $pedido->id)->first();

        // El cliente y quien atendió son personas distintas y las dos quedan
        // registradas: sin esto no hay arqueo de caja posible.
        $this->assertSame($cliente->id, $factura->user_id);
        $this->assertSame($empleado->id, $factura->cobrado_por);
    }

    public function test_un_empleado_ve_sus_cobros_con_el_total(): void
    {
        $empleado = $this->crearUsuario(['rol' => User::ROL_EMPLEADO]);
        $cliente = $this->crearUsuario(['name' => 'Cliente Uno']);
        $cerveza = $this->crearCerveza(['precio' => 2500.00, 'stock' => 20]);

        $this->cobrarComo($empleado, $cliente, $cerveza, 2); // 5000
        $this->cobrarComo($empleado, $this->crearUsuario(), $cerveza, 1); // 2500

        $this->actingAs($empleado)
            ->get('/mis-cobros')
            ->assertOk()
            ->assertSee('Mis cobros')
            ->assertSee('Cliente Uno')
            ->assertSee('7,500.00'); // total del turno
    }

    public function test_un_empleado_no_ve_los_cobros_de_otro(): void
    {
        $cerveza = $this->crearCerveza(['stock' => 20]);
        $unEmpleado = $this->crearUsuario(['rol' => User::ROL_EMPLEADO]);
        $otroEmpleado = $this->crearUsuario(['rol' => User::ROL_EMPLEADO]);

        $this->cobrarComo($otroEmpleado, $this->crearUsuario(['name' => 'Cliente Ajeno']), $cerveza);

        $this->actingAs($unEmpleado)
            ->get('/mis-cobros')
            ->assertOk()
            ->assertDontSee('Cliente Ajeno')
            ->assertSee('No registraste cobros en este período');
    }

    public function test_un_empleado_no_puede_espiar_pasando_el_id_de_otro(): void
    {
        $cerveza = $this->crearCerveza(['stock' => 20]);
        $unEmpleado = $this->crearUsuario(['rol' => User::ROL_EMPLEADO]);
        $otroEmpleado = $this->crearUsuario(['rol' => User::ROL_EMPLEADO]);

        $this->cobrarComo($otroEmpleado, $this->crearUsuario(['name' => 'Cliente Ajeno']), $cerveza);

        // El filtro por cobrador es solo para admins: un empleado que lo mande
        // igual sigue viendo la suya.
        $this->actingAs($unEmpleado)
            ->get('/mis-cobros?cobrador='.$otroEmpleado->id)
            ->assertOk()
            ->assertDontSee('Cliente Ajeno');
    }

    public function test_el_admin_puede_ver_la_caja_de_un_empleado(): void
    {
        $cerveza = $this->crearCerveza(['stock' => 20]);
        $empleado = $this->crearUsuario(['rol' => User::ROL_EMPLEADO, 'name' => 'Cajero Turno Tarde']);
        $admin = $this->crearUsuario(['rol' => User::ROL_ADMIN]);

        $this->cobrarComo($empleado, $this->crearUsuario(['name' => 'Cliente Del Turno']), $cerveza);

        $this->actingAs($admin)
            ->get('/mis-cobros?cobrador='.$empleado->id)
            ->assertOk()
            ->assertSee('Cliente Del Turno')
            ->assertSee('Cajero Turno Tarde');
    }

    public function test_un_cliente_no_entra(): void
    {
        $cliente = $this->crearUsuario(['rol' => User::ROL_CLIENTE]);

        $this->actingAs($cliente)->get('/mis-cobros')->assertRedirect(route('login'));
    }

    public function test_el_filtro_por_fecha_deja_afuera_los_cobros_viejos(): void
    {
        $empleado = $this->crearUsuario(['rol' => User::ROL_EMPLEADO]);
        $cerveza = $this->crearCerveza(['stock' => 20]);

        $this->cobrarComo($empleado, $this->crearUsuario(['name' => 'Cliente De Ayer']), $cerveza);

        // Por defecto muestra el día de hoy: el cobro de ayer no entra
        $this->travel(2)->days();

        $this->actingAs($empleado)
            ->get('/mis-cobros')
            ->assertOk()
            ->assertDontSee('Cliente De Ayer');
    }

    public function test_el_sidebar_del_empleado_no_ofrece_el_panel_de_admin(): void
    {
        $empleado = $this->crearUsuario(['rol' => User::ROL_EMPLEADO]);

        // Mostrar links que llevan a un redirect es peor que no mostrarlos
        $this->actingAs($empleado)
            ->get('/mostrador')
            ->assertOk()
            ->assertSee('Mis cobros')
            ->assertDontSee('Fermentaciones');
    }

    public function test_el_sidebar_del_admin_si_lo_ofrece(): void
    {
        $admin = $this->crearUsuario(['rol' => User::ROL_ADMIN]);

        $this->actingAs($admin)
            ->get('/mostrador')
            ->assertOk()
            ->assertSee('Mis cobros')
            ->assertSee('Fermentaciones');
    }
}
