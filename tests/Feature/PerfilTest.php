<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\CreaDatos;
use Tests\TestCase;

/**
 * Los datos propios de quien está logueado.
 *
 * Vive fuera de /usuarios porque no requiere ser administrador: un empleado no
 * entra a la pantalla de usuarios pero sí tiene que poder cambiarse la clave.
 */
class PerfilTest extends TestCase
{
    use RefreshDatabase;
    use CreaDatos;

    private function empleado(array $attrs = []): User
    {
        return $this->crearUsuario(array_merge(['rol' => User::ROL_EMPLEADO], $attrs));
    }

    // ── Acceso ───────────────────────────────────────────────────────────────

    public function test_un_empleado_entra_a_su_perfil(): void
    {
        $empleado = $this->empleado(['name' => 'Cajero Turno']);

        $this->actingAs($empleado)
            ->get('/perfil')
            ->assertOk()
            ->assertSee('Cajero Turno')
            ->assertSee('Mi perfil');
    }

    public function test_un_cliente_no_entra_al_perfil_del_panel(): void
    {
        // El panel es para el personal; el cliente tiene su cuenta en la tienda
        $this->actingAs($this->crearUsuario())->get('/perfil')->assertRedirect(route('login'));
    }

    public function test_sin_sesion_no_hay_perfil(): void
    {
        $this->get('/perfil')->assertRedirect(route('login'));
    }

    // ── Editar los datos ─────────────────────────────────────────────────────

    public function test_se_puede_corregir_el_nombre(): void
    {
        $empleado = $this->empleado(['name' => 'Mal Escrito']);

        $this->actingAs($empleado)->put('/perfil', [
            'name' => 'Bien Escrito',
            'email' => $empleado->email,
        ])->assertSessionHas('exito');

        $this->assertSame('Bien Escrito', $empleado->fresh()->name);
    }

    public function test_el_email_nuevo_sirve_para_entrar(): void
    {
        $empleado = $this->empleado();

        $this->actingAs($empleado)->put('/perfil', [
            'name' => $empleado->name,
            'email' => 'nuevomail@beervana.test',
        ])->assertSessionHas('exito');

        $this->post('/logout');

        $this->post('/login', [
            'email' => 'nuevomail@beervana.test',
            'password' => 'secret123',
        ])->assertRedirect(route('mostrador.index'));
    }

    public function test_no_se_puede_tomar_el_email_de_otro(): void
    {
        $this->crearUsuario(['email' => 'ocupado@beervana.test']);
        $empleado = $this->empleado();

        $this->actingAs($empleado)->put('/perfil', [
            'name' => $empleado->name,
            'email' => 'ocupado@beervana.test',
        ])->assertSessionHasErrors('email');

        $this->assertNotSame('ocupado@beervana.test', $empleado->fresh()->email);
    }

    public function test_dejar_el_mismo_email_no_es_un_duplicado(): void
    {
        // La regla unique tiene que ignorar la propia fila, si no cambiar solo
        // el nombre daría "email ya en uso" contra uno mismo.
        $empleado = $this->empleado();

        $this->actingAs($empleado)->put('/perfil', [
            'name' => 'Otro Nombre',
            'email' => $empleado->email,
        ])->assertSessionHasNoErrors();
    }

    // ── Cambiar la contraseña ────────────────────────────────────────────────

    public function test_se_puede_cambiar_la_password_con_la_actual(): void
    {
        $empleado = $this->empleado();

        $this->actingAs($empleado)->put('/perfil', [
            'name' => $empleado->name,
            'email' => $empleado->email,
            'password_actual' => 'secret123',
            'password' => 'clave-nueva-9',
            'password_confirmation' => 'clave-nueva-9',
        ])->assertSessionHas('exito');

        $this->assertTrue(Hash::check('clave-nueva-9', $empleado->fresh()->password));
    }

    public function test_sin_la_password_actual_no_se_cambia(): void
    {
        // Una sesión olvidada abierta en la máquina del mostrador no tiene que
        // alcanzar para quedarse con la cuenta.
        $empleado = $this->empleado();

        $this->actingAs($empleado)->put('/perfil', [
            'name' => $empleado->name,
            'email' => $empleado->email,
            'password' => 'clave-nueva-9',
            'password_confirmation' => 'clave-nueva-9',
        ])->assertSessionHasErrors('password_actual');

        $this->assertTrue(Hash::check('secret123', $empleado->fresh()->password));
    }

    public function test_con_la_password_actual_equivocada_tampoco(): void
    {
        $empleado = $this->empleado();

        $this->actingAs($empleado)->put('/perfil', [
            'name' => $empleado->name,
            'email' => $empleado->email,
            'password_actual' => 'no-es-esta',
            'password' => 'clave-nueva-9',
            'password_confirmation' => 'clave-nueva-9',
        ])->assertSessionHasErrors('password_actual');

        $this->assertTrue(Hash::check('secret123', $empleado->fresh()->password));
    }

    public function test_dejar_la_password_vacia_no_la_toca(): void
    {
        $empleado = $this->empleado();
        $hashOriginal = $empleado->password;

        $this->actingAs($empleado)->put('/perfil', [
            'name' => 'Solo El Nombre',
            'email' => $empleado->email,
            'password' => '',
        ])->assertSessionHas('exito');

        $this->assertSame($hashOriginal, $empleado->fresh()->password);
    }

    public function test_la_password_nueva_tiene_que_confirmarse(): void
    {
        $empleado = $this->empleado();

        $this->actingAs($empleado)->put('/perfil', [
            'name' => $empleado->name,
            'email' => $empleado->email,
            'password_actual' => 'secret123',
            'password' => 'clave-nueva-9',
            'password_confirmation' => 'otra-distinta',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('secret123', $empleado->fresh()->password));
    }

    // ── Lo que no se puede desde el perfil ───────────────────────────────────

    public function test_nadie_se_asciende_desde_su_propio_perfil(): void
    {
        // El rol es un permiso, no un dato personal. Si se colara por acá, un
        // empleado se haría admin solo.
        $empleado = $this->empleado();

        $this->actingAs($empleado)->put('/perfil', [
            'name' => $empleado->name,
            'email' => $empleado->email,
            'rol' => User::ROL_ADMIN,
        ])->assertSessionHas('exito');

        $this->assertSame(User::ROL_EMPLEADO, $empleado->fresh()->rol);
    }

    public function test_nadie_se_hace_super_admin_desde_su_perfil(): void
    {
        $empleado = $this->empleado();

        $this->actingAs($empleado)->put('/perfil', [
            'name' => $empleado->name,
            'email' => $empleado->email,
            'super_admin' => true,
        ])->assertSessionHas('exito');

        $this->assertFalse($empleado->fresh()->esSuperAdmin());
    }

    public function test_nadie_se_desbloquea_desde_su_perfil(): void
    {
        $empleado = $this->empleado();
        $empleado->bloquear();

        // Ni siquiera llega: el middleware lo frena antes
        $this->actingAs($empleado->fresh())->put('/perfil', [
            'name' => $empleado->name,
            'email' => $empleado->email,
            'bloqueado_en' => null,
        ])->assertRedirect(route('login'));

        $this->assertTrue($empleado->fresh()->estaBloqueado());
    }
}
