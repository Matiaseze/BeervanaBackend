<?php

namespace Tests\Feature\Api;

use App\Models\Pedido;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreaDatos;
use Tests\TestCase;

/**
 * Confirmar = el cliente avisa que va a pagar en el local.
 *
 * No cobra ni emite factura. Es la diferencia, para el mostrador, entre un
 * cliente que va a venir y un pedido que quedó armado sin que nadie hiciera
 * nada con él.
 */
class ConfirmacionTest extends TestCase
{
    use RefreshDatabase;
    use CreaDatos;

    private function crearPedido($user, $cerveza, int $cantidad = 2): array
    {
        return $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => $cantidad]],
            'metodo_entrega' => 'retiro',
        ], $this->tokenDe($user))->assertCreated()->json('pedido');
    }

    public function test_el_pedido_arranca_sin_confirmar(): void
    {
        $pedido = $this->crearPedido($this->crearUsuario(), $this->crearCerveza(['stock' => 10]));

        $this->assertSame(Pedido::PENDIENTE, $pedido['estado']);
    }

    public function test_confirmar_no_cobra_ni_emite_factura(): void
    {
        $user = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);
        $pedido = $this->crearPedido($user, $cerveza);

        $this->postJson('/my_api/pedidos/'.$pedido['codigo'].'/confirmar', [], $this->tokenDe($user))
            ->assertOk()
            ->assertJsonPath('pedido.estado', Pedido::CONFIRMADO);

        $this->assertDatabaseCount('facturas', 0);
        $this->assertSame(10, $cerveza->fresh()->stock);
    }

    public function test_un_pedido_confirmado_sigue_reservando_stock(): void
    {
        $user = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 5]);
        $pedido = $this->crearPedido($user, $cerveza, 5);

        $this->postJson('/my_api/pedidos/'.$pedido['codigo'].'/confirmar', [], $this->tokenDe($user))->assertOk();

        // Confirmar no libera nada: las unidades siguen apartadas
        $fresca = $cerveza->fresh();
        $this->assertSame(5, $fresca->reservado());
        $this->assertSame(0, $fresca->disponible());
    }

    public function test_confirmar_dos_veces_no_falla(): void
    {
        $user = $this->crearUsuario();
        $pedido = $this->crearPedido($user, $this->crearCerveza(['stock' => 10]));
        $cabeceras = $this->tokenDe($user);

        // El cliente puede volver a imprimir su comprobante sin que rompa
        $this->postJson('/my_api/pedidos/'.$pedido['codigo'].'/confirmar', [], $cabeceras)->assertOk();
        $this->postJson('/my_api/pedidos/'.$pedido['codigo'].'/confirmar', [], $cabeceras)->assertOk();

        $this->assertSame(Pedido::CONFIRMADO, Pedido::find($pedido['id'])->estado);
    }

    public function test_no_se_puede_confirmar_el_pedido_de_otro(): void
    {
        $dueno = $this->crearUsuario();
        $intruso = $this->crearUsuario();
        $pedido = $this->crearPedido($dueno, $this->crearCerveza(['stock' => 10]));

        $this->postJson('/my_api/pedidos/'.$pedido['codigo'].'/confirmar', [], $this->tokenDe($intruso))
            ->assertNotFound();

        $this->assertSame(Pedido::PENDIENTE, Pedido::find($pedido['id'])->estado);
    }

    public function test_no_se_confirma_una_reserva_vencida(): void
    {
        $user = $this->crearUsuario();
        $pedido = $this->crearPedido($user, $this->crearCerveza(['stock' => 10]));

        $this->travel(Pedido::HORAS_VENCIMIENTO + 1)->hours();

        $this->postJson('/my_api/pedidos/'.$pedido['codigo'].'/confirmar', [], $this->tokenDe($user))
            ->assertStatus(409);

        $this->assertSame(Pedido::VENCIDO, Pedido::find($pedido['id'])->estado);
    }

    public function test_un_pedido_confirmado_se_puede_cancelar(): void
    {
        $user = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 5]);
        $pedido = $this->crearPedido($user, $cerveza, 5);
        $cabeceras = $this->tokenDe($user);

        $this->postJson('/my_api/pedidos/'.$pedido['codigo'].'/confirmar', [], $cabeceras)->assertOk();
        $this->postJson('/my_api/pedidos/'.$pedido['codigo'].'/cancelar', [], $cabeceras)->assertOk();

        $this->assertSame(Pedido::CANCELADO, Pedido::find($pedido['id'])->estado);
        $this->assertSame(5, $cerveza->fresh()->disponible());
    }

    public function test_un_pedido_confirmado_tambien_vence(): void
    {
        $user = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 5]);
        $pedido = $this->crearPedido($user, $cerveza, 5);

        $this->postJson('/my_api/pedidos/'.$pedido['codigo'].'/confirmar', [], $this->tokenDe($user))->assertOk();

        $this->travel(Pedido::HORAS_VENCIMIENTO + 1)->hours();

        // Avisar que venís no congela la reserva para siempre
        $this->assertSame(5, $cerveza->fresh()->disponible());
        $this->assertSame(0, $cerveza->fresh()->reservado());
    }
}
