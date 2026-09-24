<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreaDatos;
use Tests\TestCase;

/**
 * Recorre el camino completo del usuario, en el mismo orden y con los mismos
 * endpoints que usa el frontend. Los otros tests cubren cada pieza por
 * separado; este verifica que encajen entre sí.
 */
class FlujoCompraTest extends TestCase
{
    use RefreshDatabase;
    use CreaDatos;

    public function test_de_registrarse_a_comprar(): void
    {
        $ipa = $this->crearCerveza(['precio' => 2500.00, 'stock' => 10]);
        $rubia = $this->crearCerveza(['precio' => 1800.00, 'stock' => 10]);

        // 1. Registro
        $this->postJson('/my_api/register', [
            'name' => 'Cliente',
            'email' => 'cliente@beervana.test',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertCreated();

        // 2. Login (el registro no devuelve token, hay que pedirlo aparte)
        $token = $this->postJson('/my_api/login', [
            'email' => 'cliente@beervana.test',
            'password' => 'secret123',
        ])->assertOk()->json('token');

        $cabeceras = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];

        // 3. Ve el catálogo
        $this->getJson('/my_api/cervezas', $cabeceras)->assertOk()->assertJsonCount(2);

        // 4. Arma el carrito (el frontend sincroniza el carrito completo)
        $this->postJson('/my_api/carrito/sincronizar', [
            'items' => [
                ['cerveza_id' => $ipa->id, 'cantidad' => 2],
                ['cerveza_id' => $rubia->id, 'cantidad' => 1],
            ],
        ], $cabeceras)->assertOk();

        // 5. Lo recupera al volver a entrar
        $carrito = $this->getJson('/my_api/carrito', $cabeceras)->assertOk()->json();
        $this->assertCount(2, $carrito['items']);

        // 6. Elige envío a domicilio y hace el pedido, que reserva el stock.
        //    Mercadería: 2 * 2500 + 1 * 1800 = 6800. Envío: 200. Total: 7000.
        $pedido = $this->postJson('/my_api/pedidos', [
            'items' => [
                ['id' => $ipa->id, 'cantidad' => 2],
                ['id' => $rubia->id, 'cantidad' => 1],
            ],
            'metodo_entrega' => 'envio',
        ], $cabeceras)->assertCreated()->json('pedido');

        // El total tiene que ser el mismo que le mostró el carrito
        $this->assertEquals(200, $pedido['envio']);
        $this->assertEquals(7000, $pedido['precio_total']);
        $this->assertSame('pendiente', $pedido['estado']);

        // Reservado pero todavía sin descontar del stock físico
        $this->assertSame(10, $ipa->fresh()->stock);
        $this->assertSame(8, $ipa->fresh()->disponible());

        // 7. Paga: recién acá se emite la factura
        $factura = $this->cobrarEnMostrador($pedido['id'])
            ->assertCreated()->json('factura');
        $this->assertEquals(7000, $factura['precio_total']);

        // 8. El stock bajó
        $this->assertSame(8, $ipa->fresh()->stock);
        $this->assertSame(9, $rubia->fresh()->stock);

        // 9. Vacía el carrito (lo que hace el frontend después de pagar)
        $this->postJson('/my_api/carrito/limpiar', [], $cabeceras)->assertOk();
        $this->getJson('/my_api/carrito', $cabeceras)->assertOk()->assertJsonPath('items', []);

        // 10. La compra queda en su historial
        $this->getJson('/my_api/facturas', $cabeceras)
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.pedido.estado', 'pagado');
    }

    public function test_el_carrito_sobrevive_al_logout_y_vuelve_al_reingresar(): void
    {
        $user = $this->crearUsuario(['email' => 'vuelve@beervana.test']);
        $cerveza = $this->crearCerveza(['stock' => 10]);
        $cabeceras = $this->tokenDe($user);

        // Deja productos y cierra sesión. El frontend sincroniza antes de salir
        // y limpia solo el navegador, sin vaciar el carrito del servidor.
        $this->postJson('/my_api/carrito/sincronizar', [
            'items' => [['cerveza_id' => $cerveza->id, 'cantidad' => 3]],
        ], $cabeceras)->assertOk();

        $this->postJson('/my_api/logout', [], $cabeceras)->assertOk();

        // Vuelve a entrar con un token nuevo
        $nuevasCabeceras = $this->tokenDe($user->fresh());

        $this->getJson('/my_api/carrito', $nuevasCabeceras)
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.cantidad', 3);
    }

    public function test_dos_usuarios_comprando_la_misma_cerveza(): void
    {
        $cerveza = $this->crearCerveza(['stock' => 5]);
        $primero = $this->crearUsuario();
        $segundo = $this->crearUsuario();

        // El primero pide 3 de las 5 unidades y las reserva
        $pedidoPrimero = $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 3]],
        ], $this->tokenDe($primero))->assertCreated()->json('pedido');

        // El segundo quiere otras 3: solo quedan 2 disponibles y se entera ACÁ,
        // al pedir, no después de haber visto un resumen con su total.
        $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 3]],
        ], $this->tokenDe($segundo))->assertStatus(409);

        // Puede llevarse lo que queda
        $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 2]],
        ], $this->tokenDe($segundo))->assertCreated();

        $this->cobrarEnMostrador($pedidoPrimero['id'])->assertCreated();

        $this->assertSame(2, $cerveza->fresh()->stock);   // 5 - 3 pagadas
        $this->assertSame(2, $cerveza->fresh()->reservado()); // las del segundo
        $this->assertSame(0, $cerveza->fresh()->disponible());
    }
}
