<?php

namespace Tests\Feature\Api;

use App\Models\Pedido;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreaDatos;
use Tests\TestCase;

/**
 * El escenario que motivó todo el rediseño: muchos usuarios pidiendo la misma
 * cerveza con poco stock, sin pagar ninguno.
 *
 * Antes, "generar factura" no reservaba nada: los diez sacaban su comprobante
 * por 3 unidades de un stock de 3, y el problema recién aparecía al pagar,
 * después de que el usuario ya había visto su total. Ahora el corte es al
 * reservar y el stock bloqueado se libera solo cuando la reserva vence.
 */
class ReservaStockTest extends TestCase
{
    use RefreshDatabase;
    use CreaDatos;

    /** Intenta pedir y devuelve el código de respuesta. */
    private function pedir(int $cervezaId, int $cantidad, $user): int
    {
        return $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cervezaId, 'cantidad' => $cantidad]],
            'metodo_entrega' => 'retiro',
        ], $this->tokenDe($user))->getStatusCode();
    }

    public function test_diez_usuarios_pidiendo_una_cerveza_con_stock_tres(): void
    {
        $cerveza = $this->crearCerveza(['stock' => 3, 'precio' => 1000.00]);

        $aceptados = 0;
        $rechazados = 0;

        foreach (range(1, 10) as $i) {
            $codigo = $this->pedir($cerveza->id, 1, $this->crearUsuario());

            if ($codigo === 201) {
                $aceptados++;
            } else {
                $this->assertSame(409, $codigo, "El usuario {$i} debería haber sido rechazado por falta de stock");
                $rechazados++;
            }
        }

        // Entran exactamente las 3 unidades que hay; los otros 7 se van con las
        // manos vacías pero se enteran AL PEDIR, no después de ver un total.
        $this->assertSame(3, $aceptados);
        $this->assertSame(7, $rechazados);
        $this->assertDatabaseCount('pedidos', 3);

        // Nadie pagó: el stock físico está intacto pero todo reservado
        $fresca = $cerveza->fresh();
        $this->assertSame(3, $fresca->stock);
        $this->assertSame(3, $fresca->reservado());
        $this->assertSame(0, $fresca->disponible());
    }

    public function test_la_cerveza_sin_disponibilidad_desaparece_del_catalogo(): void
    {
        $agotada = $this->crearCerveza(['stock' => 2]);
        $conStock = $this->crearCerveza(['stock' => 5]);

        $comprador = $this->crearUsuario();
        $this->assertSame(201, $this->pedir($agotada->id, 2, $comprador));

        // Sigue habiendo 2 unidades físicas, pero están reservadas: para un
        // usuario nuevo la cerveza no se puede pedir, así que no se muestra.
        $respuesta = $this->getJson('/my_api/cervezas', $this->tokenDe($this->crearUsuario()))->assertOk();

        $ids = collect($respuesta->json())->pluck('id');
        $this->assertFalse($ids->contains($agotada->id));
        $this->assertTrue($ids->contains($conStock->id));
        $this->assertSame(2, $agotada->fresh()->stock);
    }

    public function test_al_vencer_las_reservas_el_stock_vuelve_a_estar_disponible(): void
    {
        $cerveza = $this->crearCerveza(['stock' => 3]);

        foreach (range(1, 3) as $i) {
            $this->assertSame(201, $this->pedir($cerveza->id, 1, $this->crearUsuario()));
        }

        $this->assertSame(0, $cerveza->fresh()->disponible());

        // Un usuario nuevo no puede pedir nada
        $tardio = $this->crearUsuario();
        $this->assertSame(409, $this->pedir($cerveza->id, 1, $tardio));

        // Pasa el tiempo de vida de las reservas
        $this->travel(Pedido::HORAS_VENCIMIENTO + 1)->hours();

        // Sin que corra ningún proceso: las reservas dejaron de contar solas
        $this->assertSame(0, $cerveza->fresh()->reservado());
        $this->assertSame(3, $cerveza->fresh()->disponible());

        // Y ahora sí puede pedir
        $this->assertSame(201, $this->pedir($cerveza->id, 2, $tardio));
        $this->assertSame(1, $cerveza->fresh()->disponible());
    }

    public function test_un_pedido_vencido_no_se_puede_pagar(): void
    {
        $user = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 5]);
        $cabeceras = $this->tokenDe($user);

        $pedido = $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 2]],
        ], $cabeceras)->assertCreated()->json('pedido');

        $this->travel(Pedido::HORAS_VENCIMIENTO + 1)->hours();

        $this->cobrarEnMostrador($pedido['id'])
            ->assertStatus(409)
            ->assertJsonPath('error', 'La reserva venció. Volvé a armar el pedido.');

        // Queda marcado como vencido y no se emitió factura ni se tocó el stock
        $this->assertSame(Pedido::VENCIDO, Pedido::find($pedido['id'])->estado);
        $this->assertDatabaseCount('facturas', 0);
        $this->assertSame(5, $cerveza->fresh()->stock);
    }

    public function test_el_pedido_vencido_se_informa_como_vencido_aunque_nadie_lo_haya_tocado(): void
    {
        $user = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 5]);
        $cabeceras = $this->tokenDe($user);

        $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 2]],
        ], $cabeceras)->assertCreated();

        $this->travel(Pedido::HORAS_VENCIMIENTO + 1)->hours();

        // En la base sigue diciendo 'pendiente' porque no corrió ninguna tarea,
        // pero la API no puede mentirle al usuario.
        $this->assertSame(Pedido::PENDIENTE, Pedido::first()->estado);
        $this->getJson('/my_api/pedidos', $cabeceras)
            ->assertOk()
            ->assertJsonPath('0.estado', Pedido::VENCIDO);
    }

    public function test_cancelar_libera_para_el_siguiente_de_la_fila(): void
    {
        $cerveza = $this->crearCerveza(['stock' => 2]);
        $primero = $this->crearUsuario();
        $segundo = $this->crearUsuario();

        $pedido = $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 2]],
        ], $this->tokenDe($primero))->assertCreated()->json('pedido');

        $this->assertSame(409, $this->pedir($cerveza->id, 1, $segundo));

        $this->postJson('/my_api/pedidos/'.$pedido['codigo'].'/cancelar', [], $this->tokenDe($primero))
            ->assertOk();

        $this->assertSame(201, $this->pedir($cerveza->id, 2, $segundo));
    }

    public function test_las_reservas_de_distintas_cervezas_no_se_pisan(): void
    {
        $ipa = $this->crearCerveza(['stock' => 2]);
        $rubia = $this->crearCerveza(['stock' => 5]);

        $this->assertSame(201, $this->pedir($ipa->id, 2, $this->crearUsuario()));

        // La IPA quedó sin disponibilidad, la rubia no se enteró
        $this->assertSame(0, $ipa->fresh()->disponible());
        $this->assertSame(5, $rubia->fresh()->disponible());
        $this->assertSame(0, $rubia->fresh()->reservado());
    }

    public function test_pagar_uno_no_libera_la_reserva_de_los_otros(): void
    {
        $cerveza = $this->crearCerveza(['stock' => 3]);
        $primero = $this->crearUsuario();
        $segundo = $this->crearUsuario();

        $pedidoPrimero = $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 1]],
        ], $this->tokenDe($primero))->assertCreated()->json('pedido');

        $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 2]],
        ], $this->tokenDe($segundo))->assertCreated();

        $this->cobrarEnMostrador($pedidoPrimero['id'])->assertCreated();

        // Stock físico 3 - 1 pagada = 2, y las 2 del segundo siguen reservadas
        $fresca = $cerveza->fresh();
        $this->assertSame(2, $fresca->stock);
        $this->assertSame(2, $fresca->reservado());
        $this->assertSame(0, $fresca->disponible());
    }
}
