<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreaDatos;
use Tests\TestCase;

class CatalogoTest extends TestCase
{
    use RefreshDatabase;
    use CreaDatos;

    public function test_solo_se_listan_cervezas_con_stock(): void
    {
        $user = $this->crearUsuario();
        $disponible = $this->crearCerveza(['stock' => 5]);
        $agotada = $this->crearCerveza(['stock' => 0]);

        $respuesta = $this->getJson('/my_api/cervezas', $this->tokenDe($user))->assertOk();

        $ids = collect($respuesta->json())->pluck('id');
        $this->assertTrue($ids->contains($disponible->id));
        $this->assertFalse($ids->contains($agotada->id));
    }

    public function test_las_cervezas_vienen_con_marca_y_estilo(): void
    {
        $user = $this->crearUsuario();
        $this->crearCerveza(['marca' => 'Andes', 'estilo' => 'IPA']);

        $this->getJson('/my_api/cervezas', $this->tokenDe($user))
            ->assertOk()
            ->assertJsonPath('0.marca.nombre', 'Andes')
            ->assertJsonPath('0.estilo.nombre', 'IPA');
    }

    public function test_se_pueden_filtrar_por_marca(): void
    {
        $user = $this->crearUsuario();
        $andes = $this->crearCerveza(['marca' => 'Andes']);
        $this->crearCerveza(['marca' => 'Patagonia']);

        $respuesta = $this->getJson('/my_api/cervezas?marca_id='.$andes->marca_id, $this->tokenDe($user))
            ->assertOk()
            ->assertJsonCount(1);

        $this->assertSame($andes->id, $respuesta->json('0.id'));
    }

    public function test_se_pueden_filtrar_por_estilo(): void
    {
        $user = $this->crearUsuario();
        $ipa = $this->crearCerveza(['estilo' => 'IPA']);
        $this->crearCerveza(['estilo' => 'Stout']);

        $respuesta = $this->getJson('/my_api/cervezas?estilo_id='.$ipa->estilo_id, $this->tokenDe($user))
            ->assertOk()
            ->assertJsonCount(1);

        $this->assertSame($ipa->id, $respuesta->json('0.id'));
    }

    public function test_el_precio_viaja_como_string_con_dos_decimales(): void
    {
        $user = $this->crearUsuario();
        $this->crearCerveza(['precio' => 2500.00]);

        // Documenta el contrato real: la columna es decimal y PostgreSQL la
        // devuelve como string. El frontend tiene que hacer parseFloat, no
        // asumir number (nos rompió un .toFixed en la pantalla de pago).
        $respuesta = $this->getJson('/my_api/cervezas', $this->tokenDe($user))->assertOk();

        $this->assertIsString($respuesta->json('0.precio'));
        $this->assertSame('2500.00', $respuesta->json('0.precio'));
    }

    public function test_marcas_y_estilos_vienen_ordenados_por_nombre(): void
    {
        $this->crearCerveza(['marca' => 'Zeta', 'estilo' => 'Zwickel']);
        $this->crearCerveza(['marca' => 'Alfa', 'estilo' => 'Amber']);

        $marcas = collect($this->getJson('/my_api/marcas')->assertOk()->json())->pluck('nombre');
        $this->assertSame($marcas->sort()->values()->all(), $marcas->all());

        $estilos = collect($this->getJson('/my_api/estilos')->assertOk()->json())->pluck('nombre');
        $this->assertSame($estilos->sort()->values()->all(), $estilos->all());
    }
}
