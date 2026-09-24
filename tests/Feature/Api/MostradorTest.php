<?php

namespace Tests\Feature\Api;

use App\Models\Pedido;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreaDatos;
use Tests\TestCase;

/**
 * Cobro presencial.
 *
 * El cliente genera un comprobante con el código de su pedido y lo lleva al
 * local; quien atiende busca por ese código, verifica y recién ahí registra el
 * cobro, que baja el stock y emite la factura.
 *
 * Lo que se prueba acá, sobre todo: el cliente NO puede registrar el cobro.
 * Antes podía, y eso significaba marcar el pedido como pagado y llevarse la
 * mercadería sin pagar.
 */
class MostradorTest extends TestCase
{
    use RefreshDatabase;
    use CreaDatos;

    private function crearPedidoDe($user, $cerveza, int $cantidad = 2): array
    {
        return $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => $cantidad]],
            'metodo_entrega' => 'retiro',
        ], $this->tokenDe($user))->assertCreated()->json('pedido');
    }

    // ── El código ────────────────────────────────────────────────────────────

    public function test_el_pedido_nace_con_un_codigo_para_el_mostrador(): void
    {
        $pedido = $this->crearPedidoDe($this->crearUsuario(), $this->crearCerveza(['stock' => 10]));

        $this->assertMatchesRegularExpression('/^BV-[A-Z2-9]{6}$/', $pedido['codigo']);
    }

    public function test_el_codigo_no_usa_caracteres_que_se_confunden(): void
    {
        $cerveza = $this->crearCerveza(['stock' => 100]);

        // El código se dicta en voz alta y se tipea desde un papel: 0/O y 1/I/L
        // se confunden y harían que el cajero busque un pedido que no existe.
        foreach (range(1, 15) as $i) {
            $pedido = $this->crearPedidoDe($this->crearUsuario(), $cerveza, 1);
            $this->assertDoesNotMatchRegularExpression('/[01OIL]/', substr($pedido['codigo'], 3));
        }
    }

    public function test_dos_pedidos_no_comparten_codigo(): void
    {
        $cerveza = $this->crearCerveza(['stock' => 100]);
        $codigos = [];

        foreach (range(1, 20) as $i) {
            $codigos[] = $this->crearPedidoDe($this->crearUsuario(), $cerveza, 1)['codigo'];
        }

        $this->assertCount(20, array_unique($codigos));
    }

    // ── Quién puede cobrar ───────────────────────────────────────────────────

    public function test_el_cliente_no_puede_registrar_el_cobro_de_su_propio_pedido(): void
    {
        $cliente = $this->crearUsuario(['rol' => User::ROL_CLIENTE]);
        $cerveza = $this->crearCerveza(['stock' => 10]);
        $pedido = $this->crearPedidoDe($cliente, $cerveza);

        // El agujero: si el cliente pudiera, marcaría el pedido como pagado y
        // se llevaría la mercadería sin haber pagado nada.
        $this->postJson('/my_api/mostrador/pedidos/'.$pedido['id'].'/cobrar', [], $this->tokenDe($cliente))
            ->assertForbidden();

        $this->assertSame(Pedido::PENDIENTE, Pedido::find($pedido['id'])->estado);
        $this->assertDatabaseCount('facturas', 0);
        $this->assertSame(10, $cerveza->fresh()->stock);
    }

    public function test_el_cliente_no_puede_buscar_pedidos_por_codigo(): void
    {
        $cliente = $this->crearUsuario(['rol' => User::ROL_CLIENTE]);
        $pedido = $this->crearPedidoDe($cliente, $this->crearCerveza(['stock' => 10]));

        // Ni siquiera el suyo: es una herramienta de mostrador
        $this->getJson('/my_api/mostrador/pedido?codigo='.$pedido['codigo'], $this->tokenDe($cliente))
            ->assertForbidden();
    }

    public function test_sin_sesion_tampoco(): void
    {
        $this->postJson('/my_api/mostrador/pedidos/1/cobrar')->assertUnauthorized();
        $this->getJson('/my_api/mostrador/pedido?codigo=BV-ABC234')->assertUnauthorized();
    }

    // ── Buscar por código ────────────────────────────────────────────────────

    public function test_el_personal_encuentra_el_pedido_por_su_codigo(): void
    {
        $cliente = $this->crearUsuario(['name' => 'Matías Ezequiel']);
        $cerveza = $this->crearCerveza(['precio' => 2500.00, 'stock' => 10]);
        $pedido = $this->crearPedidoDe($cliente, $cerveza, 2);
        $personal = $this->crearUsuario(['rol' => User::ROL_ADMIN]);

        // Todo lo que el cajero necesita para verificar antes de cobrar
        $this->getJson('/my_api/mostrador/pedido?codigo='.$pedido['codigo'], $this->tokenDe($personal))
            ->assertOk()
            ->assertJsonPath('id', $pedido['id'])
            ->assertJsonPath('estado', Pedido::PENDIENTE)
            ->assertJsonPath('user.name', 'Matías Ezequiel')
            ->assertJsonPath('precio_total', '5000.00')
            ->assertJsonPath('items.0.cerveza.nombre', $cerveza->nombre);
    }

    public function test_el_codigo_se_puede_tipear_en_minuscula_y_con_espacios(): void
    {
        $pedido = $this->crearPedidoDe($this->crearUsuario(), $this->crearCerveza(['stock' => 10]));
        $personal = $this->crearUsuario(['rol' => User::ROL_ADMIN]);

        $tipeado = '  '.strtolower($pedido['codigo']).' ';

        $this->getJson('/my_api/mostrador/pedido?codigo='.urlencode($tipeado), $this->tokenDe($personal))
            ->assertOk()
            ->assertJsonPath('id', $pedido['id']);
    }

    public function test_un_codigo_inexistente_da_404_y_no_revela_nada(): void
    {
        $personal = $this->crearUsuario(['rol' => User::ROL_ADMIN]);

        $this->getJson('/my_api/mostrador/pedido?codigo=BV-ZZZZZZ', $this->tokenDe($personal))
            ->assertNotFound()
            ->assertJsonPath('error', 'No hay ningún pedido con ese código');
    }

    // ── Cobrar ───────────────────────────────────────────────────────────────

    public function test_el_personal_registra_el_cobro_y_se_emite_la_factura(): void
    {
        $cliente = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['precio' => 2500.00, 'stock' => 10]);
        $pedido = $this->crearPedidoDe($cliente, $cerveza, 2);
        $personal = $this->crearUsuario(['rol' => User::ROL_ADMIN]);

        $factura = $this->postJson('/my_api/mostrador/pedidos/'.$pedido['id'].'/cobrar', [], $this->tokenDe($personal))
            ->assertCreated()->json('factura');

        $this->assertSame(8, $cerveza->fresh()->stock);
        $this->assertSame(Pedido::PAGADO, Pedido::find($pedido['id'])->estado);
        $this->assertEquals(5000, $factura['precio_total']);
        // La factura queda a nombre del cliente, no de quien cobró
        $this->assertSame($cliente->id, $factura['user_id']);
    }

    public function test_el_personal_puede_cobrar_el_pedido_de_cualquier_cliente(): void
    {
        $cliente = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);
        $pedido = $this->crearPedidoDe($cliente, $cerveza);
        $personal = $this->crearUsuario(['rol' => User::ROL_ADMIN]);

        // A diferencia del cliente, el personal no está limitado a sus pedidos
        $this->postJson('/my_api/mostrador/pedidos/'.$pedido['id'].'/cobrar', [], $this->tokenDe($personal))
            ->assertCreated();
    }

    public function test_no_se_cobra_dos_veces_el_mismo_pedido(): void
    {
        $cliente = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);
        $pedido = $this->crearPedidoDe($cliente, $cerveza, 2);
        $personal = $this->crearUsuario(['rol' => User::ROL_ADMIN]);
        $cabeceras = $this->tokenDe($personal);

        $this->postJson('/my_api/mostrador/pedidos/'.$pedido['id'].'/cobrar', [], $cabeceras)->assertCreated();
        $this->postJson('/my_api/mostrador/pedidos/'.$pedido['id'].'/cobrar', [], $cabeceras)->assertStatus(409);

        $this->assertSame(8, $cerveza->fresh()->stock);
        $this->assertDatabaseCount('facturas', 1);
    }

    public function test_no_se_cobra_un_pedido_con_la_reserva_vencida(): void
    {
        $cliente = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);
        $pedido = $this->crearPedidoDe($cliente, $cerveza, 2);
        $personal = $this->crearUsuario(['rol' => User::ROL_ADMIN]);

        $this->travel(Pedido::HORAS_VENCIMIENTO + 1)->hours();

        $this->postJson('/my_api/mostrador/pedidos/'.$pedido['id'].'/cobrar', [], $this->tokenDe($personal))
            ->assertStatus(409);

        $this->assertDatabaseCount('facturas', 0);
        $this->assertSame(10, $cerveza->fresh()->stock);
    }

    public function test_generar_el_pedido_no_emite_factura(): void
    {
        $cliente = $this->crearUsuario();
        $pedido = $this->crearPedidoDe($cliente, $this->crearCerveza(['stock' => 10]));

        // El comprobante que el cliente imprime sale del pedido; la factura no
        // existe hasta que alguien cobra.
        $this->assertDatabaseCount('facturas', 0);
        $this->assertSame(Pedido::PENDIENTE, Pedido::find($pedido['id'])->estado);
        $this->getJson('/my_api/facturas', $this->tokenDe($cliente))
            ->assertOk()
            ->assertJsonCount(0);
    }
}
