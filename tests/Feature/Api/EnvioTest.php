<?php

namespace Tests\Feature\Api;

use App\Models\Pedido;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreaDatos;
use Tests\TestCase;

/**
 * El carrito mostraba "Envío: $200" en el total pero la factura no lo cobraba:
 * el usuario aceptaba $7.000 y la factura decía $6.800.
 */
class EnvioTest extends TestCase
{
    use RefreshDatabase;
    use CreaDatos;

    public function test_el_costo_de_envio_se_consulta_a_la_api(): void
    {
        // El frontend lo pide en vez de tener su propia copia. Si tuviera una,
        // cambiar el costo en el backend volvería a desincronizar las pantallas.
        $this->getJson('/my_api/costo-envio')
            ->assertOk()
            ->assertJsonPath('costo_envio', 200)
            ->assertJsonPath('metodos', ['envio', 'retiro']);
    }

    public function test_con_envio_a_domicilio_el_total_incluye_el_costo(): void
    {
        $user = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['precio' => 2500.00, 'stock' => 10]);

        $pedido = $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 2]],
            'metodo_entrega' => 'envio',
        ], $this->tokenDe($user))->assertCreated()->json('pedido');

        // 2 * 2500 = 5000 de mercadería + 200 de envío
        $this->assertEquals(5200, $pedido['precio_total']);
        $this->assertEquals(200, $pedido['envio']);
        $this->assertSame('envio', $pedido['metodo_entrega']);
    }

    public function test_con_retiro_en_el_local_no_se_cobra_envio(): void
    {
        $user = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['precio' => 2500.00, 'stock' => 10]);

        $pedido = $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 2]],
            'metodo_entrega' => 'retiro',
        ], $this->tokenDe($user))->assertCreated()->json('pedido');

        $this->assertEquals(5000, $pedido['precio_total']);
        $this->assertEquals(0, $pedido['envio']);
        $this->assertSame('retiro', $pedido['metodo_entrega']);
    }

    public function test_si_no_se_aclara_el_metodo_se_asume_envio(): void
    {
        $user = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['precio' => 2500.00, 'stock' => 10]);

        $pedido = $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 1]],
        ], $this->tokenDe($user))->assertCreated()->json('pedido');

        $this->assertSame('envio', $pedido['metodo_entrega']);
        $this->assertEquals(2700, $pedido['precio_total']);
    }

    public function test_el_metodo_de_entrega_tiene_que_ser_valido(): void
    {
        $user = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);

        $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 1]],
            'metodo_entrega' => 'drone',
        ], $this->tokenDe($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('metodo_entrega');

        $this->assertDatabaseCount('facturas', 0);
    }

    public function test_el_cliente_no_puede_elegir_cuanto_paga_de_envio(): void
    {
        $user = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['precio' => 2500.00, 'stock' => 10]);

        // Manda su propio costo de envío, igual que podría mandar su propio precio
        $pedido = $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 1]],
            'metodo_entrega' => 'envio',
            'envio' => 1,
            'precio_total' => 1,
        ], $this->tokenDe($user))->assertCreated()->json('pedido');

        $this->assertEquals(200, $pedido['envio']);
        $this->assertEquals(2700, $pedido['precio_total']);
    }

    public function test_el_envio_no_inventa_renglones_en_el_detalle(): void
    {
        $user = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);

        $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 1]],
            'metodo_entrega' => 'envio',
        ], $this->tokenDe($user))->assertCreated();

        // El envío va en su propia columna, no como un ítem falso del detalle
        $this->assertDatabaseCount('pedido_items', 1);
        $this->assertDatabaseHas('pedido_items', ['cerveza_id' => $cerveza->id]);
    }

    public function test_el_total_de_la_factura_es_el_que_vio_el_usuario(): void
    {
        $user = $this->crearUsuario();
        $ipa = $this->crearCerveza(['precio' => 2500.00, 'stock' => 10]);
        $rubia = $this->crearCerveza(['precio' => 1800.00, 'stock' => 10]);

        $pedido = $this->postJson('/my_api/pedidos', [
            'items' => [
                ['id' => $ipa->id, 'cantidad' => 2],
                ['id' => $rubia->id, 'cantidad' => 1],
            ],
            'metodo_entrega' => 'envio',
        ], $this->tokenDe($user))->assertCreated()->json('pedido');

        // Lo mismo que calcula el carrito: subtotal 6800 + envío 200 = 7000
        $subtotal = collect($pedido['items'])->sum(fn ($d) => (float) $d['subtotal']);

        $this->assertEquals(6800, $subtotal);
        $this->assertEquals(Pedido::COSTO_ENVIO, $pedido['envio']);
        $this->assertEquals($subtotal + $pedido['envio'], $pedido['precio_total']);
    }

    public function test_pagar_no_cambia_el_total_ni_el_envio(): void
    {
        $user = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['precio' => 2500.00, 'stock' => 10]);
        $cabeceras = $this->tokenDe($user);

        $pedido = $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 2]],
            'metodo_entrega' => 'envio',
        ], $cabeceras)->assertCreated()->json('pedido');

        $factura = $this->cobrarEnMostrador($pedido['id'])
            ->assertCreated()->json('factura');

        // La factura hereda el total del pedido, envío incluido
        $this->assertEquals(5200, $factura['precio_total']);
        $this->assertEquals(200, Pedido::find($pedido['id'])->envio);
    }
}
