<?php

namespace Tests\Feature\Api;

use App\Models\Pedido;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreaDatos;
use Tests\TestCase;

/**
 * El pedido reserva stock; la factura se emite recién al pagar.
 */
class PedidoTest extends TestCase
{
    use RefreshDatabase;
    use CreaDatos;

    // ── Crear el pedido ──────────────────────────────────────────────────────

    public function test_crear_un_pedido_calcula_el_total_y_los_renglones(): void
    {
        $user = $this->crearUsuario();
        $ipa = $this->crearCerveza(['precio' => 2500.00, 'stock' => 10]);
        $rubia = $this->crearCerveza(['precio' => 1800.00, 'stock' => 10]);

        $pedido = $this->postJson('/my_api/pedidos', [
            'items' => [
                ['id' => $ipa->id, 'cantidad' => 3],
                ['id' => $rubia->id, 'cantidad' => 2],
            ],
            'metodo_entrega' => 'retiro',
        ], $this->tokenDe($user))->assertCreated()->json('pedido');

        // 3 * 2500 + 2 * 1800 = 11100, sin envío porque es retiro
        $this->assertEquals(11100, $pedido['precio_total']);
        $this->assertSame('pendiente', $pedido['estado']);

        $this->assertDatabaseHas('pedido_items', [
            'cerveza_id' => $ipa->id,
            'cantidad' => 3,
            'precio_unitario' => 2500.00,
            'subtotal' => 7500.00,
        ]);
    }

    public function test_el_precio_sale_de_la_base_y_no_del_cliente(): void
    {
        $user = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['precio' => 2500.00, 'stock' => 10]);

        $pedido = $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 1, 'precio' => 1, 'subtotal' => 1]],
            'metodo_entrega' => 'retiro',
            'precio_total' => 1,
        ], $this->tokenDe($user))->assertCreated()->json('pedido');

        $this->assertEquals(2500, $pedido['precio_total']);
    }

    public function test_el_pedido_nace_con_fecha_de_vencimiento(): void
    {
        $user = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);

        $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 1]],
        ], $this->tokenDe($user))->assertCreated();

        $pedido = Pedido::first();
        $this->assertNotNull($pedido->expira_en);
        $this->assertEqualsWithDelta(
            Pedido::HORAS_VENCIMIENTO,
            now()->diffInHours($pedido->expira_en, false),
            1
        );
    }

    public function test_crear_un_pedido_no_toca_el_stock_fisico(): void
    {
        $user = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);

        $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 4]],
        ], $this->tokenDe($user))->assertCreated();

        // Reserva, no descuenta: el stock físico solo baja al pagar
        $fresca = $cerveza->fresh();
        $this->assertSame(10, $fresca->stock);
        $this->assertSame(4, $fresca->reservado());
        $this->assertSame(6, $fresca->disponible());
    }

    public function test_no_se_puede_pedir_mas_de_lo_disponible(): void
    {
        $user = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 3]);

        $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 5]],
        ], $this->tokenDe($user))->assertStatus(409);

        $this->assertDatabaseCount('pedidos', 0);
        $this->assertDatabaseCount('pedido_items', 0);
    }

    public function test_una_cerveza_inexistente_da_error_controlado(): void
    {
        $user = $this->crearUsuario();

        $this->postJson('/my_api/pedidos', [
            'items' => [['id' => 999999, 'cantidad' => 1]],
        ], $this->tokenDe($user))->assertStatus(422);

        $this->assertDatabaseCount('pedidos', 0);
    }

    public function test_el_rollback_alcanza_a_los_renglones_ya_procesados(): void
    {
        $user = $this->crearUsuario();
        $conStock = $this->crearCerveza(['stock' => 10]);
        $sinStock = $this->crearCerveza(['stock' => 0]);

        $this->postJson('/my_api/pedidos', [
            'items' => [
                ['id' => $conStock->id, 'cantidad' => 1],
                ['id' => $sinStock->id, 'cantidad' => 1],
            ],
        ], $this->tokenDe($user))->assertStatus(409);

        $this->assertDatabaseCount('pedidos', 0);
        $this->assertDatabaseCount('pedido_items', 0);
    }

    public function test_un_pedido_sin_items_se_rechaza(): void
    {
        $user = $this->crearUsuario();

        $this->postJson('/my_api/pedidos', ['items' => []], $this->tokenDe($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }

    // ── Cancelar ─────────────────────────────────────────────────────────────

    public function test_cancelar_libera_la_reserva(): void
    {
        $user = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 5]);
        $cabeceras = $this->tokenDe($user);

        $pedido = $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 5]],
        ], $cabeceras)->assertCreated()->json('pedido');

        $this->assertSame(0, $cerveza->fresh()->disponible());

        $this->postJson('/my_api/pedidos/'.$pedido['codigo'].'/cancelar', [], $cabeceras)->assertOk();

        $this->assertSame(5, $cerveza->fresh()->disponible());
        $this->assertSame(Pedido::CANCELADO, Pedido::find($pedido['id'])->estado);
    }

    public function test_no_se_puede_cancelar_un_pedido_pagado(): void
    {
        $user = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 5]);
        $cabeceras = $this->tokenDe($user);

        $pedido = $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 1]],
        ], $cabeceras)->assertCreated()->json('pedido');

        $this->cobrarEnMostrador($pedido['id'])->assertCreated();
        $this->postJson('/my_api/pedidos/'.$pedido['codigo'].'/cancelar', [], $cabeceras)->assertStatus(409);

        $this->assertSame(Pedido::PAGADO, Pedido::find($pedido['id'])->estado);
    }

    public function test_no_se_puede_cancelar_el_pedido_de_otro(): void
    {
        $dueno = $this->crearUsuario();
        $intruso = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 5]);

        $pedido = $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 2]],
        ], $this->tokenDe($dueno))->assertCreated()->json('pedido');

        $this->postJson('/my_api/pedidos/'.$pedido['codigo'].'/cancelar', [], $this->tokenDe($intruso))
            ->assertNotFound();

        $this->assertSame(Pedido::PENDIENTE, Pedido::find($pedido['id'])->estado);
    }

    // ── Pagar ────────────────────────────────────────────────────────────────

    public function test_pagar_descuenta_el_stock_y_emite_la_factura(): void
    {
        $user = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['precio' => 2500.00, 'stock' => 10]);
        $cabeceras = $this->tokenDe($user);

        $pedido = $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 3]],
            'metodo_entrega' => 'retiro',
        ], $cabeceras)->assertCreated()->json('pedido');

        $factura = $this->cobrarEnMostrador($pedido['id'])
            ->assertCreated()->json('factura');

        $this->assertSame(7, $cerveza->fresh()->stock);
        $this->assertSame(Pedido::PAGADO, Pedido::find($pedido['id'])->estado);
        $this->assertEquals(7500, $factura['precio_total']);
        $this->assertDatabaseCount('facturas', 1);
    }

    public function test_al_pagar_la_reserva_deja_de_contar(): void
    {
        $user = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);
        $cabeceras = $this->tokenDe($user);

        $pedido = $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 4]],
        ], $cabeceras)->assertCreated()->json('pedido');

        $this->cobrarEnMostrador($pedido['id'])->assertCreated();

        // Si la reserva siguiera contando después de pagar, esas 4 unidades se
        // descontarían dos veces y la cerveza quedaría con 2 disponibles.
        $fresca = $cerveza->fresh();
        $this->assertSame(6, $fresca->stock);
        $this->assertSame(0, $fresca->reservado());
        $this->assertSame(6, $fresca->disponible());
    }

    public function test_no_se_puede_pagar_dos_veces(): void
    {
        $user = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);
        $cabeceras = $this->tokenDe($user);

        $pedido = $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 3]],
        ], $cabeceras)->assertCreated()->json('pedido');

        $this->cobrarEnMostrador($pedido['id'])->assertCreated();
        $this->cobrarEnMostrador($pedido['id'])->assertStatus(409);

        $this->assertSame(7, $cerveza->fresh()->stock);
        $this->assertDatabaseCount('facturas', 1);
    }

    public function test_ningun_cliente_puede_registrar_un_cobro(): void
    {
        $dueno = $this->crearUsuario(['rol' => User::ROL_CLIENTE]);
        $intruso = $this->crearUsuario(['rol' => User::ROL_CLIENTE]);
        $cerveza = $this->crearCerveza(['stock' => 10]);

        $pedido = $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 3]],
        ], $this->tokenDe($dueno))->assertCreated()->json('pedido');

        // El cobro es presencial: ni el dueño del pedido ni un tercero pueden
        // registrarlo. Solo el personal, desde el mostrador.
        foreach ([$dueno, $intruso] as $cliente) {
            $this->postJson(
                '/my_api/mostrador/pedidos/'.$pedido['id'].'/cobrar',
                [],
                $this->tokenDe($cliente)
            )->assertForbidden();
        }

        $this->assertSame(10, $cerveza->fresh()->stock);
        $this->assertDatabaseCount('facturas', 0);
    }

    public function test_no_se_puede_pagar_un_pedido_cancelado(): void
    {
        $user = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);
        $cabeceras = $this->tokenDe($user);

        $pedido = $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 2]],
        ], $cabeceras)->assertCreated()->json('pedido');

        $this->postJson('/my_api/pedidos/'.$pedido['codigo'].'/cancelar', [], $cabeceras)->assertOk();
        $this->cobrarEnMostrador($pedido['id'])->assertStatus(409);

        $this->assertDatabaseCount('facturas', 0);
        $this->assertSame(10, $cerveza->fresh()->stock);
    }

    // ── Listar ───────────────────────────────────────────────────────────────

    public function test_cada_usuario_ve_solo_sus_pedidos(): void
    {
        $unUsuario = $this->crearUsuario();
        $otroUsuario = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 20]);

        $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 1]],
        ], $this->tokenDe($unUsuario))->assertCreated();

        $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 2]],
        ], $this->tokenDe($otroUsuario))->assertCreated();

        $this->getJson('/my_api/pedidos', $this->tokenDe($unUsuario))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.user_id', $unUsuario->id);
    }

    public function test_el_historial_de_facturas_solo_trae_compras_pagadas(): void
    {
        $user = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 20]);
        $cabeceras = $this->tokenDe($user);

        $pagado = $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 1]],
        ], $cabeceras)->json('pedido');
        $this->cobrarEnMostrador($pagado['id'])->assertCreated();

        // Este queda pendiente: no es una venta, no debe figurar en el historial
        $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 2]],
        ], $cabeceras)->assertCreated();

        $this->getJson('/my_api/facturas', $cabeceras)
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.pedido.estado', Pedido::PAGADO);
    }

    public function test_la_factura_trae_los_renglones_con_la_cerveza(): void
    {
        $user = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);
        $cabeceras = $this->tokenDe($user);

        $pedido = $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 2]],
        ], $cabeceras)->json('pedido');
        $this->cobrarEnMostrador($pedido['id'])->assertCreated();

        $this->getJson('/my_api/facturas', $cabeceras)
            ->assertOk()
            ->assertJsonPath('0.pedido.items.0.cerveza.nombre', $cerveza->nombre);
    }

    public function test_no_se_puede_ver_la_factura_de_otro(): void
    {
        $dueno = $this->crearUsuario();
        $intruso = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);
        $cabeceras = $this->tokenDe($dueno);

        $pedido = $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 1]],
        ], $cabeceras)->json('pedido');
        $factura = $this->cobrarEnMostrador($pedido['id'])
            ->json('factura');

        $this->getJson('/my_api/facturas/'.$factura['id'], $this->tokenDe($intruso))
            ->assertNotFound();
    }
}
