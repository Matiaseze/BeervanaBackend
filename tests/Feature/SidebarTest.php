<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreaDatos;
use Tests\TestCase;

/**
 * El menú lateral.
 *
 * Lo que se prueba no es el diseño sino que el sidebar exista dos veces
 * (escritorio y offcanvas mobile) y que las dos copias digan lo mismo: es el
 * error fácil de este archivo, cambiar una y olvidarse de la otra.
 */
class SidebarTest extends TestCase
{
    use RefreshDatabase;
    use CreaDatos;

    private function personal(string $rol, array $attrs = []): User
    {
        return $this->crearUsuario(array_merge(['rol' => $rol], $attrs));
    }

    /** Cuántas veces aparece un texto en el HTML de la página. */
    private function veces(string $html, string $texto): int
    {
        return substr_count($html, $texto);
    }

    // ── La cuenta y la salida están al pie ───────────────────────────────────

    public function test_el_sidebar_muestra_la_cuenta_y_el_boton_de_salir(): void
    {
        $empleado = $this->personal(User::ROL_EMPLEADO, ['name' => 'Cajero Turno']);

        $this->actingAs($empleado)
            ->get('/mostrador')
            ->assertOk()
            ->assertSee('Cajero Turno')
            ->assertSee('Cerrar sesión')
            ->assertSee('Empleado')
            ->assertSee(route('perfil.edit'));
    }

    public function test_aparece_en_las_dos_copias_del_sidebar(): void
    {
        // Escritorio y offcanvas mobile. Si alguna vez una queda sin el pie,
        // en el celular no habría forma de cerrar sesión.
        $html = $this->actingAs($this->personal(User::ROL_EMPLEADO))
            ->get('/mostrador')->assertOk()->getContent();

        $this->assertSame(2, $this->veces($html, 'sidebar-cuenta mt-auto'));
        $this->assertSame(2, $this->veces($html, 'Cerrar sesión'));
    }

    public function test_el_logout_ya_no_esta_en_la_barra_de_arriba(): void
    {
        // Dos botones de cerrar sesión en la misma pantalla es una decisión de
        // más para algo que no tiene alternativas.
        $html = $this->actingAs($this->personal(User::ROL_ADMIN))
            ->get('/usuarios')->assertOk()->getContent();

        // Las dos apariciones son las del sidebar, ninguna del navbar superior
        $this->assertSame(2, $this->veces($html, route('logout')));
    }

    public function test_el_boton_de_salir_funciona(): void
    {
        // Que el formulario esté no alcanza: tiene que cerrar la sesión
        $empleado = $this->personal(User::ROL_EMPLEADO);

        $this->actingAs($empleado)->post('/logout');

        $this->assertGuest();
    }

    public function test_al_super_admin_se_lo_identifica_como_tal(): void
    {
        $super = $this->personal(User::ROL_ADMIN, ['super_admin' => true, 'name' => 'La Jefa']);

        $this->actingAs($super)
            ->get('/mostrador')
            ->assertOk()
            ->assertSee('La Jefa')
            ->assertSee('Super admin');
    }

    // ── Los links según el rol ───────────────────────────────────────────────

    public function test_el_empleado_no_ve_los_links_de_administracion(): void
    {
        $html = $this->actingAs($this->personal(User::ROL_EMPLEADO))
            ->get('/mostrador')->assertOk()->getContent();

        $this->assertStringContainsString(route('mostrador.index'), $html);
        $this->assertStringContainsString(route('mis-cobros.index'), $html);
        $this->assertStringNotContainsString(route('usuarios.index'), $html);
        $this->assertStringNotContainsString(route('cervezas.index'), $html);
    }

    public function test_el_admin_ve_todo_el_menu(): void
    {
        $html = $this->actingAs($this->personal(User::ROL_ADMIN))
            ->get('/mostrador')->assertOk()->getContent();

        foreach (['mostrador.index', 'mis-cobros.index', 'usuarios.index', 'cervezas.index'] as $ruta) {
            $this->assertStringContainsString(route($ruta), $html);
        }
    }

    public function test_el_logo_lleva_a_una_pantalla_que_el_usuario_puede_ver(): void
    {
        // Apuntaba fijo a /home, que es solo para admins: al empleado lo mandaba
        // a rebotar en vez de llevarlo a algún lado.
        $empleado = $this->personal(User::ROL_EMPLEADO);

        $html = $this->actingAs($empleado)->get('/mostrador')->assertOk()->getContent();

        $this->assertStringContainsString(route('mostrador.index'), $html);

        $this->actingAs($empleado)->get(route('mostrador.index'))->assertOk();
    }
}
