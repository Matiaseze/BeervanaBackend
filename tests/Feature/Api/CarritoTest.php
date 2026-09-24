<?php

namespace Tests\Feature\Api;

use App\Models\Carrito;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreaDatos;
use Tests\TestCase;

class CarritoTest extends TestCase
{
    use RefreshDatabase;
    use CreaDatos;

    public function test_ver_el_carrito_lo_crea_si_no_existe(): void
    {
        $user = $this->crearUsuario();

        $this->getJson('/my_api/carrito', $this->tokenDe($user))
            ->assertOk()
            ->assertJsonPath('user_id', $user->id)
            ->assertJsonPath('items', []);

        $this->assertDatabaseHas('carritos', ['user_id' => $user->id]);
    }

    public function test_agregar_acumula_la_cantidad_del_mismo_producto(): void
    {
        $user = $this->crearUsuario();
        $cerveza = $this->crearCerveza();
        $cabeceras = $this->tokenDe($user);

        $this->postJson('/my_api/carrito/agregar', ['cerveza_id' => $cerveza->id, 'cantidad' => 2], $cabeceras)
            ->assertOk();
        $this->postJson('/my_api/carrito/agregar', ['cerveza_id' => $cerveza->id, 'cantidad' => 3], $cabeceras)
            ->assertOk();

        $this->assertDatabaseCount('carrito_items', 1);
        $this->assertDatabaseHas('carrito_items', ['cerveza_id' => $cerveza->id, 'cantidad' => 5]);
    }

    public function test_agregar_valida_que_la_cerveza_exista(): void
    {
        $user = $this->crearUsuario();

        $this->postJson('/my_api/carrito/agregar', ['cerveza_id' => 999999, 'cantidad' => 1], $this->tokenDe($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('cerveza_id');
    }

    public function test_agregar_rechaza_cantidades_invalidas(): void
    {
        $user = $this->crearUsuario();
        $cerveza = $this->crearCerveza();

        $this->postJson('/my_api/carrito/agregar', ['cerveza_id' => $cerveza->id, 'cantidad' => 0], $this->tokenDe($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('cantidad');
    }

    public function test_quitar_elimina_solo_ese_producto(): void
    {
        $user = $this->crearUsuario();
        $unaCerveza = $this->crearCerveza();
        $otraCerveza = $this->crearCerveza();
        $cabeceras = $this->tokenDe($user);

        $this->postJson('/my_api/carrito/agregar', ['cerveza_id' => $unaCerveza->id, 'cantidad' => 1], $cabeceras);
        $this->postJson('/my_api/carrito/agregar', ['cerveza_id' => $otraCerveza->id, 'cantidad' => 1], $cabeceras);

        $this->deleteJson('/my_api/carrito/quitar/'.$unaCerveza->id, [], $cabeceras)->assertOk();

        $this->assertDatabaseMissing('carrito_items', ['cerveza_id' => $unaCerveza->id]);
        $this->assertDatabaseHas('carrito_items', ['cerveza_id' => $otraCerveza->id]);
    }

    public function test_sincronizar_reemplaza_el_carrito_en_vez_de_acumular(): void
    {
        $user = $this->crearUsuario();
        $cerveza = $this->crearCerveza();
        $cabeceras = $this->tokenDe($user);

        $this->postJson('/my_api/carrito/sincronizar', [
            'items' => [['cerveza_id' => $cerveza->id, 'cantidad' => 2]],
        ], $cabeceras)->assertOk();

        $this->postJson('/my_api/carrito/sincronizar', [
            'items' => [['cerveza_id' => $cerveza->id, 'cantidad' => 5]],
        ], $cabeceras)->assertOk();

        // El frontend manda el carrito completo en cada cambio: si esto acumulara
        // en vez de reemplazar, cada click en "+" duplicaría la cantidad.
        $this->assertDatabaseCount('carrito_items', 1);
        $this->assertDatabaseHas('carrito_items', ['cerveza_id' => $cerveza->id, 'cantidad' => 5]);
    }

    public function test_sincronizar_rechaza_una_lista_vacia(): void
    {
        $user = $this->crearUsuario();

        // Por esto el frontend usa /carrito/limpiar para vaciar, y no sincronizar
        // con un array vacío.
        $this->postJson('/my_api/carrito/sincronizar', ['items' => []], $this->tokenDe($user))
            ->assertStatus(400);
    }

    public function test_limpiar_vacia_el_carrito(): void
    {
        $user = $this->crearUsuario();
        $cerveza = $this->crearCerveza();
        $cabeceras = $this->tokenDe($user);

        $this->postJson('/my_api/carrito/agregar', ['cerveza_id' => $cerveza->id, 'cantidad' => 2], $cabeceras);
        $this->assertDatabaseCount('carrito_items', 1);

        $this->postJson('/my_api/carrito/limpiar', [], $cabeceras)->assertOk();

        $this->assertDatabaseCount('carrito_items', 0);
        // El carrito sigue existiendo, solo queda sin ítems
        $this->assertDatabaseHas('carritos', ['user_id' => $user->id]);
    }

    public function test_limpiar_solo_acepta_post(): void
    {
        $user = $this->crearUsuario();

        // El frontend mandaba DELETE contra esta ruta: el backend respondía 405,
        // el error se perdía en un catch y el carrito solo se vaciaba en el
        // navegador. Al recargar, los productos volvían.
        $this->deleteJson('/my_api/carrito/limpiar', [], $this->tokenDe($user))
            ->assertStatus(405);
    }

    public function test_el_carrito_de_un_usuario_no_se_mezcla_con_el_de_otro(): void
    {
        $unUsuario = $this->crearUsuario();
        $otroUsuario = $this->crearUsuario();
        $cerveza = $this->crearCerveza();

        $this->postJson('/my_api/carrito/agregar',
            ['cerveza_id' => $cerveza->id, 'cantidad' => 4],
            $this->tokenDe($unUsuario)
        )->assertOk();

        $this->getJson('/my_api/carrito', $this->tokenDe($otroUsuario))
            ->assertOk()
            ->assertJsonPath('items', []);
    }

    public function test_vaciar_el_carrito_no_afecta_al_de_otro_usuario(): void
    {
        $unUsuario = $this->crearUsuario();
        $otroUsuario = $this->crearUsuario();
        $cerveza = $this->crearCerveza();

        $this->postJson('/my_api/carrito/agregar', ['cerveza_id' => $cerveza->id, 'cantidad' => 1], $this->tokenDe($unUsuario));
        $this->postJson('/my_api/carrito/agregar', ['cerveza_id' => $cerveza->id, 'cantidad' => 1], $this->tokenDe($otroUsuario));
        $this->assertDatabaseCount('carrito_items', 2);

        $this->postJson('/my_api/carrito/limpiar', [], $this->tokenDe($unUsuario))->assertOk();

        $this->assertDatabaseCount('carrito_items', 1);
    }

    public function test_un_usuario_no_puede_tener_dos_carritos(): void
    {
        $user = $this->crearUsuario();
        Carrito::create(['user_id' => $user->id]);

        // El índice único cierra la puerta que dejaba abierta firstOrCreate:
        // dos requests en paralelo llegaron a crear dos carritos para el mismo
        // usuario, y después "ver" y "vaciar" podían apuntar a filas distintas.
        $this->expectException(QueryException::class);
        Carrito::create(['user_id' => $user->id]);
    }
}
