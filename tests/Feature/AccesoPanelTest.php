<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\CreaDatos;
use Tests\TestCase;

/**
 * Entrada al panel y rechazos por rol.
 *
 * El punto de este archivo es seguir los redirects hasta el final. Un
 * assertRedirect solo mira el Location y da por bueno un destino que a su vez
 * rebota: así pasó desapercibido que /home (solo para admins) mandaba al
 * empleado a /login, y /login lo devolvía a /home, sin cortar nunca.
 */
class AccesoPanelTest extends TestCase
{
    use RefreshDatabase;
    use CreaDatos;

    private function personal(string $rol): User
    {
        return $this->crearUsuario([
            'rol' => $rol,
            'email' => $rol.'@beervana.test',
            'password' => Hash::make('clave-larga-1'),
        ]);
    }

    private function login(string $email)
    {
        return $this->seguir(fn () => $this->post('/login', [
            'email' => $email,
            'password' => 'clave-larga-1',
        ]));
    }

    /**
     * Sigue los redirects con un tope, y falla si no corta.
     *
     * followingRedirects() de Laravel no tiene límite: contra un bucle no falla,
     * cuelga la suite entera hasta que se acaba el tiempo. Con un tope, el bug
     * aparece como un test rojo con un mensaje que se entiende.
     */
    private function seguir(\Closure $peticion, int $tope = 10)
    {
        $respuesta = $peticion();
        $visitadas = [];

        for ($i = 0; $i < $tope; $i++) {
            if (! $respuesta->isRedirect()) {
                return $respuesta;
            }

            $destino = $respuesta->headers->get('Location');
            $visitadas[] = $destino;
            $respuesta = $this->get($destino);
        }

        $this->fail("Bucle de redirects: ".implode(' -> ', $visitadas));
    }

    // ── El login termina en una pantalla usable ──────────────────────────────

    public function test_el_empleado_entra_y_cae_en_el_mostrador(): void
    {
        $this->personal(User::ROL_EMPLEADO);

        $this->login('empleado@beervana.test')
            ->assertOk()
            ->assertSee('Mostrador');
    }

    public function test_el_admin_entra_y_cae_en_su_pagina(): void
    {
        $this->personal(User::ROL_ADMIN);

        $this->login('admin@beervana.test')->assertOk();
    }

    public function test_un_cliente_no_entra_al_panel(): void
    {
        $this->crearUsuario([
            'email' => 'cliente@beervana.test',
            'password' => Hash::make('clave-larga-1'),
        ]);

        $this->login('cliente@beervana.test')->assertOk();

        // Y queda deslogueado, no a medio camino
        $this->assertGuest();
    }

    // ── El bucle que hay que evitar ──────────────────────────────────────────

    public function test_el_empleado_que_pisa_una_ruta_de_admin_no_queda_rebotando(): void
    {
        $empleado = $this->personal(User::ROL_EMPLEADO);

        // /home es del grupo admin. El empleado tiene que terminar en una
        // pantalla suya, no en un ida y vuelta contra /login.
        $this->actingAs($empleado);

        $this->seguir(fn () => $this->get('/home'))
            ->assertOk()
            ->assertSee('Mostrador');

        // Y sigue logueado: rebotarlo no es motivo para cerrarle la sesión
        $this->assertAuthenticatedAs($empleado);
    }

    public function test_el_rechazo_explica_el_motivo(): void
    {
        $empleado = $this->personal(User::ROL_EMPLEADO);

        $this->actingAs($empleado)->get('/usuarios')
            ->assertRedirect(route('mostrador.index'))
            ->assertSessionHas('error', 'Esa sección es solo para administradores.');
    }

    public function test_un_empleado_logueado_que_abre_login_va_a_su_pantalla(): void
    {
        // Este es el otro lado del bucle: /login rebota a los autenticados, y
        // si el destino es una ruta que no pueden ver, no se corta nunca.
        $empleado = $this->personal(User::ROL_EMPLEADO);

        $this->actingAs($empleado)->get('/login')
            ->assertRedirect(route('mostrador.index'));
    }

    public function test_un_admin_logueado_que_abre_login_va_a_home(): void
    {
        $admin = $this->personal(User::ROL_ADMIN);

        $this->actingAs($admin)->get('/login')->assertRedirect(route('home'));
    }

    // ── Cuentas bloqueadas ───────────────────────────────────────────────────

    public function test_un_empleado_bloqueado_no_entra_y_queda_deslogueado(): void
    {
        $empleado = $this->personal(User::ROL_EMPLEADO);
        $empleado->bloquear();

        $this->login('empleado@beervana.test')->assertOk();

        $this->assertGuest();
    }

    public function test_bloquear_a_un_admin_adentro_lo_saca_sin_bucle(): void
    {
        $admin = $this->personal(User::ROL_ADMIN);

        $this->actingAs($admin)->get('/usuarios')->assertOk();

        $admin->bloquear();

        $this->actingAs($admin->fresh());

        $this->seguir(fn () => $this->get('/usuarios'))
            ->assertOk()
            ->assertSee('Tu cuenta está bloqueada.');

        $this->assertGuest();
    }

    // ── Sin sesión ───────────────────────────────────────────────────────────

    public function test_sin_sesion_las_rutas_del_panel_mandan_al_login(): void
    {
        foreach (['/home', '/usuarios', '/mostrador', '/mis-cobros', '/perfil'] as $ruta) {
            $this->get($ruta)->assertRedirect(route('login'));
        }
    }
}
