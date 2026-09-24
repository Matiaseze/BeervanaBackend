<?php

namespace Tests\Feature;

use App\Models\Pedido;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreaDatos;
use Tests\TestCase;

class PanelAdminTest extends TestCase
{
    use RefreshDatabase;
    use CreaDatos;

    private function comprarComo($user, $cerveza, int $cantidad): void
    {
        $cabeceras = $this->tokenDe($user);

        $pedido = $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => $cantidad]],
            'metodo_entrega' => 'retiro',
        ], $cabeceras)->assertCreated()->json('pedido');

        $this->cobrarEnMostrador($pedido['id'])->assertCreated();
    }

    public function test_el_dashboard_carga_con_datos_del_modelo_nuevo(): void
    {
        $admin = $this->crearUsuario(['rol' => User::ROL_ADMIN]);
        $cliente = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['nombre' => 'Andes IPA', 'precio' => 2500.00, 'stock' => 20]);

        $this->comprarComo($cliente, $cerveza, 3);

        // Un pedido pendiente, que debe contarse como reserva y no como venta
        $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 2]],
        ], $this->tokenDe($cliente))->assertCreated();

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Andes IPA');
    }

    public function test_el_dashboard_no_deja_entrar_a_un_usuario_comun(): void
    {
        $comun = $this->crearUsuario(['rol' => User::ROL_CLIENTE]);

        $this->actingAs($comun)
            ->get('/dashboard')
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Acceso denegado.');
    }

    public function test_el_comando_de_limpieza_marca_los_pedidos_vencidos(): void
    {
        $user = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);

        $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 2]],
        ], $this->tokenDe($user))->assertCreated();

        $this->travel(Pedido::HORAS_VENCIMIENTO + 1)->hours();

        $this->assertSame(10, $cerveza->fresh()->disponible());
        $this->assertSame(Pedido::PENDIENTE, Pedido::first()->estado);

        $this->artisan('pedidos:limpiar')
            ->expectsOutputToContain('Se marcaron 1 pedidos como vencidos.')
            ->assertSuccessful();

        $this->assertSame(Pedido::VENCIDO, Pedido::first()->estado);
        $this->assertNull(Pedido::first()->expira_en);
    }

    public function test_el_comando_no_toca_las_reservas_vigentes(): void
    {
        $user = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);

        $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 2]],
        ], $this->tokenDe($user))->assertCreated();

        $this->artisan('pedidos:limpiar')->assertSuccessful();

        $this->assertSame(Pedido::PENDIENTE, Pedido::first()->estado);
        $this->assertSame(8, $cerveza->fresh()->disponible());
    }
}
