<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreaDatos;
use Tests\TestCase;

/**
 * Bloqueo de cuentas.
 *
 * A un cliente no se lo elimina: sus pedidos y facturas son ventas reales y
 * están con onDelete cascade, así que borrarlo se llevaría puesta la historia.
 * El bloqueo corta el acceso sin perder nada.
 *
 * Lo que hay que probar no es que la columna se escriba, sino que el bloqueo
 * efectivamente impida entrar y pagar. Eso es lo que se verifica acá.
 */
class BloqueoUsuariosTest extends TestCase
{
    use RefreshDatabase;
    use CreaDatos;

    private function admin(array $attrs = []): User
    {
        return $this->crearUsuario(array_merge(['rol' => User::ROL_ADMIN], $attrs));
    }

    private function empleado(array $attrs = []): User
    {
        return $this->crearUsuario(array_merge(['rol' => User::ROL_EMPLEADO], $attrs));
    }

    private function superAdmin(): User
    {
        return $this->crearUsuario(['rol' => User::ROL_ADMIN, 'super_admin' => true]);
    }

    private function bloquear(User $quien, User $aQuien)
    {
        return $this->actingAs($quien)->post("/usuarios/{$aQuien->id}/bloquear");
    }

    // ── Quién puede bloquear ─────────────────────────────────────────────────

    public function test_un_admin_bloquea_a_un_cliente(): void
    {
        $admin = $this->admin();
        $cliente = $this->crearUsuario();

        $this->bloquear($admin, $cliente)->assertSessionHas('exito');

        $this->assertTrue($cliente->fresh()->estaBloqueado());
    }

    public function test_un_empleado_no_puede_bloquear(): void
    {
        $empleado = $this->empleado();
        $cliente = $this->crearUsuario();

        $this->bloquear($empleado, $cliente)->assertRedirect(route('mostrador.index'));

        $this->assertFalse($cliente->fresh()->estaBloqueado());
    }

    public function test_al_super_admin_no_se_lo_bloquea(): void
    {
        $super = $this->superAdmin();
        $otroAdmin = $this->admin();

        $this->bloquear($otroAdmin, $super)
            ->assertSessionHas('error', 'Al super administrador no se lo puede bloquear.');

        $this->assertFalse($super->fresh()->estaBloqueado());
    }

    public function test_nadie_se_bloquea_a_si_mismo(): void
    {
        $admin = $this->admin();

        $this->bloquear($admin, $admin)
            ->assertSessionHas('error', 'No podés bloquear tu propia cuenta.');

        $this->assertFalse($admin->fresh()->estaBloqueado());
    }

    public function test_bloquear_dos_veces_avisa_en_vez_de_pisar_la_fecha(): void
    {
        // Si pisara la fecha, el "bloqueado desde" de la pantalla mentiría
        $admin = $this->admin();
        $cliente = $this->crearUsuario();

        $this->bloquear($admin, $cliente);
        $fecha = $cliente->fresh()->bloqueado_en;

        $this->bloquear($admin, $cliente)->assertSessionHas('error');

        $this->assertEquals($fecha, $cliente->fresh()->bloqueado_en);
    }

    // ── Qué efecto tiene ─────────────────────────────────────────────────────

    public function test_un_cliente_bloqueado_no_puede_iniciar_sesion_en_la_tienda(): void
    {
        $cliente = $this->crearUsuario(['email' => 'bloqueado@beervana.test']);
        $cliente->bloquear();

        $this->postJson('/my_api/login', [
            'email' => 'bloqueado@beervana.test',
            'password' => 'secret123',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_el_motivo_del_rechazo_se_dice_explicitamente(): void
    {
        // Devolver "credenciales inválidas" dejaría a la persona reintentando
        // una contraseña que en realidad está bien.
        $cliente = $this->crearUsuario(['email' => 'motivo@beervana.test']);
        $cliente->bloquear();

        $respuesta = $this->postJson('/my_api/login', [
            'email' => 'motivo@beervana.test',
            'password' => 'secret123',
        ]);

        $this->assertStringContainsString('bloqueada', $respuesta->json('errors.email.0'));
    }

    public function test_bloquear_corta_la_sesion_que_ya_estaba_abierta(): void
    {
        // Sin esto el bloqueo no haría nada hasta que la persona cerrara sesión
        // por su cuenta: el token ya emitido seguiría sirviendo para comprar.
        $cliente = $this->crearUsuario();
        $cabeceras = $this->tokenDe($cliente);

        $this->getJson('/my_api/carrito', $cabeceras)->assertOk();

        $this->bloquear($this->admin(), $cliente)->assertSessionHas('exito');

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->app['auth']->forgetGuards();
        $this->getJson('/my_api/carrito', $cabeceras)->assertUnauthorized();
    }

    public function test_un_cliente_bloqueado_no_puede_crear_un_pedido(): void
    {
        // El objetivo del bloqueo es que no pueda comprar ni pagar
        $cerveza = $this->crearCerveza(['stock' => 10]);
        $cliente = $this->crearUsuario();
        $cabeceras = $this->tokenDe($cliente);

        // El token se emite antes del bloqueo y no se borra a mano: se prueba
        // que el middleware lo frena igual, que es el cinturón además de los
        // tirantes por si algún token sobrevive.
        $cliente->forceFill(['bloqueado_en' => now()])->save();
        $this->app['auth']->forgetGuards();

        $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 1]],
            'metodo_entrega' => 'retiro',
        ], $cabeceras)->assertStatus(403);

        $this->assertDatabaseCount('pedidos', 0);
    }

    public function test_un_empleado_bloqueado_no_entra_al_panel(): void
    {
        $empleado = $this->empleado(['email' => 'exempleado@beervana.test']);
        $empleado->bloquear();

        $this->post('/login', [
            'email' => 'exempleado@beervana.test',
            'password' => 'secret123',
        ])->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_bloquear_a_alguien_que_ya_esta_adentro_lo_saca_en_el_click_siguiente(): void
    {
        $empleado = $this->empleado();

        $this->actingAs($empleado)->get('/mostrador')->assertOk();

        $empleado->bloquear();

        // Va a /login y no al mostrador: una cuenta bloqueada no tiene ninguna
        // pantalla adonde ir, así que se le cierra la sesión.
        $this->actingAs($empleado->fresh())->get('/mostrador')->assertRedirect(route('login'));

        $this->assertGuest();
    }

    // ── Volver atrás ─────────────────────────────────────────────────────────

    public function test_desbloquear_devuelve_el_acceso(): void
    {
        $admin = $this->admin();
        $cliente = $this->crearUsuario(['email' => 'vuelve@beervana.test']);

        $this->bloquear($admin, $cliente);

        $this->actingAs($admin)->post("/usuarios/{$cliente->id}/desbloquear")
            ->assertSessionHas('exito');

        $this->assertFalse($cliente->fresh()->estaBloqueado());

        $this->postJson('/my_api/login', [
            'email' => 'vuelve@beervana.test',
            'password' => 'secret123',
        ])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_desbloquear_a_alguien_que_no_esta_bloqueado_avisa(): void
    {
        $admin = $this->admin();
        $cliente = $this->crearUsuario();

        $this->actingAs($admin)->post("/usuarios/{$cliente->id}/desbloquear")
            ->assertSessionHas('error');
    }

    public function test_el_listado_muestra_el_estado_de_la_cuenta(): void
    {
        $admin = $this->admin();
        $cliente = $this->crearUsuario(['name' => 'Cliente Vetado']);
        $cliente->bloquear();

        $this->actingAs($admin)
            ->get('/usuarios')
            ->assertOk()
            ->assertSee('Cliente Vetado')
            ->assertSee('Bloqueado');
    }
}
