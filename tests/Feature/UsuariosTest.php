<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\CreaDatos;
use Tests\TestCase;

/**
 * Administración de usuarios: alta, edición y roles.
 *
 * Las reglas: solo los administradores reparten roles, al super admin no lo
 * toca nadie, nadie cambia el suyo propio y los clientes no se promueven.
 *
 * El bloqueo y la eliminación tienen su propio archivo (BloqueoUsuariosTest y
 * EliminarUsuariosTest) porque son bajas y no altas.
 */
class UsuariosTest extends TestCase
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

    private function cambiarRol(User $quien, User $aQuien, string $rol)
    {
        return $this->actingAs($quien)->patch("/usuarios/{$aQuien->id}/rol", ['rol' => $rol]);
    }

    // ── Acceso a la pantalla ─────────────────────────────────────────────────

    public function test_el_listado_separa_clientes_de_personal(): void
    {
        $admin = $this->admin(['name' => 'Jefa Admin']);
        $this->empleado(['name' => 'Cajero Turno']);
        $this->crearUsuario(['name' => 'Cliente Comun']);

        $this->actingAs($admin)
            ->get('/usuarios')
            ->assertOk()
            ->assertSee('Personal')
            ->assertSee('Clientes')
            ->assertSee('Cajero Turno')
            ->assertSee('Cliente Comun');
    }

    public function test_un_empleado_no_entra_a_usuarios(): void
    {
        // Cobrar no es repartir permisos
        $this->actingAs($this->empleado())->get('/usuarios')->assertRedirect(route('mostrador.index'));
    }

    public function test_un_cliente_no_entra_a_usuarios(): void
    {
        // Al cliente sí se lo manda a /login, porque no hay ninguna pantalla del
        // panel que pueda ver. Antes se le cierra la sesión: si no, /login lo
        // rebotaría de vuelta y quedaría dando vueltas.
        $this->actingAs($this->crearUsuario())->get('/usuarios')->assertRedirect(route('login'));

        $this->assertGuest();
    }

    // ── Alta de personal ─────────────────────────────────────────────────────

    public function test_un_admin_crea_una_cuenta_de_empleado(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/usuarios', [
            'name' => 'Cajera Nueva',
            'email' => 'cajera@beervana.test',
            'password' => 'clave-larga-1',
            'password_confirmation' => 'clave-larga-1',
            'rol' => User::ROL_EMPLEADO,
        ])->assertSessionHas('exito');

        $creada = User::where('email', 'cajera@beervana.test')->first();

        $this->assertNotNull($creada);
        $this->assertSame(User::ROL_EMPLEADO, $creada->rol);
        $this->assertFalse($creada->esSuperAdmin());
        // Hasheada, no en texto plano
        $this->assertNotSame('clave-larga-1', $creada->password);
        $this->assertTrue(Hash::check('clave-larga-1', $creada->password));
    }

    public function test_la_cuenta_creada_puede_entrar_al_panel(): void
    {
        // Que la fila exista no alcanza: la clave tiene que servir de verdad y
        // el destino del login tiene que ser una pantalla que pueda ver. Se
        // sigue el redirect hasta el final en vez de mirar solo el Location:
        // el bug que esto agarra es que /home rebotaba al empleado a /login y
        // /login lo devolvía a /home, en un bucle que un assertRedirect no ve.
        $this->actingAs($this->admin())->post('/usuarios', [
            'name' => 'Cajero Real',
            'email' => 'real@beervana.test',
            'password' => 'clave-larga-1',
            'password_confirmation' => 'clave-larga-1',
            'rol' => User::ROL_EMPLEADO,
        ]);

        $this->post('/logout');

        $respuesta = $this->post('/login', [
            'email' => 'real@beervana.test',
            'password' => 'clave-larga-1',
        ])->assertRedirect(route('mostrador.index'));

        // Y el destino se puede abrir de verdad. AccesoPanelTest cubre el caso
        // del bucle en detalle; acá alcanza con confirmar que la cuenta entra.
        $this->get($respuesta->headers->get('Location'))->assertOk();
    }

    public function test_no_se_puede_crear_un_cliente_desde_el_panel(): void
    {
        // Los clientes se registran solos en la tienda. Crearlos acá no aporta
        // nada y abriría la puerta a que "cliente" sea un rol asignable.
        $this->actingAs($this->admin())->post('/usuarios', [
            'name' => 'Cliente Colado',
            'email' => 'colado@beervana.test',
            'password' => 'clave-larga-1',
            'password_confirmation' => 'clave-larga-1',
            'rol' => User::ROL_CLIENTE,
        ])->assertSessionHasErrors('rol');

        $this->assertDatabaseMissing('users', ['email' => 'colado@beervana.test']);
    }

    public function test_el_alta_rechaza_un_email_repetido(): void
    {
        $this->crearUsuario(['email' => 'repetido@beervana.test']);

        $this->actingAs($this->admin())->post('/usuarios', [
            'name' => 'Otro',
            'email' => 'repetido@beervana.test',
            'password' => 'clave-larga-1',
            'password_confirmation' => 'clave-larga-1',
            'rol' => User::ROL_EMPLEADO,
        ])->assertSessionHasErrors('email');

        $this->assertSame(1, User::where('email', 'repetido@beervana.test')->count());
    }

    public function test_el_alta_exige_confirmar_la_password(): void
    {
        $this->actingAs($this->admin())->post('/usuarios', [
            'name' => 'Dedos Torpes',
            'email' => 'torpe@beervana.test',
            'password' => 'clave-larga-1',
            'password_confirmation' => 'otra-cosa-2',
            'rol' => User::ROL_EMPLEADO,
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'torpe@beervana.test']);
    }

    public function test_un_empleado_no_puede_crear_usuarios(): void
    {
        $this->actingAs($this->empleado())->post('/usuarios', [
            'name' => 'Amigo',
            'email' => 'amigo@beervana.test',
            'password' => 'clave-larga-1',
            'password_confirmation' => 'clave-larga-1',
            'rol' => User::ROL_ADMIN,
        ])->assertRedirect(route('mostrador.index'));

        $this->assertDatabaseMissing('users', ['email' => 'amigo@beervana.test']);
    }

    // ── Edición de datos ─────────────────────────────────────────────────────

    public function test_un_admin_edita_el_nombre_y_el_email_de_un_empleado(): void
    {
        $admin = $this->admin();
        $empleado = $this->empleado(['name' => 'Nombre Viejo', 'email' => 'viejo@beervana.test']);

        $this->actingAs($admin)->put("/usuarios/{$empleado->id}", [
            'name' => 'Nombre Nuevo',
            'email' => 'nuevo@beervana.test',
        ])->assertSessionHas('exito');

        $empleado->refresh();
        $this->assertSame('Nombre Nuevo', $empleado->name);
        $this->assertSame('nuevo@beervana.test', $empleado->email);
    }

    public function test_dejar_la_password_vacia_no_la_cambia(): void
    {
        $admin = $this->admin();
        $empleado = $this->empleado();
        $hashOriginal = $empleado->password;

        $this->actingAs($admin)->put("/usuarios/{$empleado->id}", [
            'name' => 'Solo Cambio El Nombre',
            'email' => $empleado->email,
            'password' => '',
        ])->assertSessionHas('exito');

        $this->assertSame($hashOriginal, $empleado->fresh()->password);
    }

    public function test_la_edicion_puede_cambiar_la_password(): void
    {
        $admin = $this->admin();
        $empleado = $this->empleado();

        $this->actingAs($admin)->put("/usuarios/{$empleado->id}", [
            'name' => $empleado->name,
            'email' => $empleado->email,
            'password' => 'clave-nueva-9',
            'password_confirmation' => 'clave-nueva-9',
        ])->assertSessionHas('exito');

        $this->assertTrue(Hash::check('clave-nueva-9', $empleado->fresh()->password));
    }

    public function test_la_edicion_no_cambia_el_rol(): void
    {
        // El rol tiene su propia acción con sus propias reglas. Si se colara
        // por acá, un admin podría autopromoverse esquivando el control.
        $admin = $this->admin();
        $empleado = $this->empleado();

        $this->actingAs($admin)->put("/usuarios/{$empleado->id}", [
            'name' => $empleado->name,
            'email' => $empleado->email,
            'rol' => User::ROL_ADMIN,
        ])->assertSessionHas('exito');

        $this->assertSame(User::ROL_EMPLEADO, $empleado->fresh()->rol);
    }

    public function test_la_edicion_no_permite_pisar_el_email_de_otro(): void
    {
        $admin = $this->admin();
        $ocupado = $this->empleado(['email' => 'ocupado@beervana.test']);
        $empleado = $this->empleado();

        $this->actingAs($admin)->put("/usuarios/{$empleado->id}", [
            'name' => $empleado->name,
            'email' => 'ocupado@beervana.test',
        ])->assertSessionHasErrors('email');

        $this->assertNotSame('ocupado@beervana.test', $empleado->fresh()->email);
        $this->assertSame('ocupado@beervana.test', $ocupado->fresh()->email);
    }

    public function test_nadie_edita_los_datos_del_super_admin(): void
    {
        // Cambiarle el email o la clave es quedarse con su cuenta. Protegerle
        // el rol y dejar esto abierto sería una puerta de atrás a lo mismo.
        $super = $this->superAdmin();
        $otroAdmin = $this->admin();

        $this->actingAs($otroAdmin)->put("/usuarios/{$super->id}", [
            'name' => 'Secuestrada',
            'email' => 'delotro@beervana.test',
            'password' => 'clave-larga-1',
            'password_confirmation' => 'clave-larga-1',
        ])->assertSessionHas('error', 'Los datos del super administrador solo los cambia él mismo.');

        $super->refresh();
        $this->assertNotSame('Secuestrada', $super->name);
        $this->assertNotSame('delotro@beervana.test', $super->email);
    }

    public function test_el_super_admin_si_edita_sus_propios_datos(): void
    {
        $super = $this->superAdmin();

        $this->actingAs($super)->put("/usuarios/{$super->id}", [
            'name' => 'Nombre Corregido',
            'email' => $super->email,
        ])->assertSessionHas('exito');

        $this->assertSame('Nombre Corregido', $super->fresh()->name);
    }

    public function test_un_empleado_no_puede_editar_a_nadie(): void
    {
        $empleado = $this->empleado();
        $otro = $this->empleado(['name' => 'Intacto']);

        $this->actingAs($empleado)->put("/usuarios/{$otro->id}", [
            'name' => 'Cambiado',
            'email' => $otro->email,
        ])->assertRedirect(route('mostrador.index'));

        $this->assertSame('Intacto', $otro->fresh()->name);
    }

    // ── Quién puede cambiar qué rol ──────────────────────────────────────────

    public function test_un_admin_promueve_un_empleado_a_admin(): void
    {
        $admin = $this->admin();
        $empleado = $this->empleado();

        $this->cambiarRol($admin, $empleado, User::ROL_ADMIN)->assertSessionHas('exito');

        $this->assertSame(User::ROL_ADMIN, $empleado->fresh()->rol);
    }

    public function test_un_admin_degrada_a_otro_admin_a_empleado(): void
    {
        $admin = $this->admin();
        $otro = $this->admin();

        $this->cambiarRol($admin, $otro, User::ROL_EMPLEADO)->assertSessionHas('exito');

        $this->assertSame(User::ROL_EMPLEADO, $otro->fresh()->rol);
    }

    public function test_un_cliente_no_se_puede_promover_a_empleado(): void
    {
        // Para atender el local hay que tener cuenta dada de alta. Si alcanzara
        // con promover a cualquiera que se registró en la tienda, el acceso al
        // mostrador quedaría a un click de una cuenta que nadie verificó.
        $admin = $this->admin();
        $cliente = $this->crearUsuario();

        $this->cambiarRol($admin, $cliente, User::ROL_EMPLEADO)
            ->assertSessionHas('error');

        $this->assertSame(User::ROL_CLIENTE, $cliente->fresh()->rol);
    }

    public function test_tampoco_se_puede_degradar_a_alguien_del_personal_a_cliente(): void
    {
        // El viaje de vuelta tampoco: sería una salida sin regreso, porque
        // después no hay forma de volver a promoverlo.
        $admin = $this->admin();
        $empleado = $this->empleado();

        $this->cambiarRol($admin, $empleado, User::ROL_CLIENTE)
            ->assertSessionHasErrors('rol');

        $this->assertSame(User::ROL_EMPLEADO, $empleado->fresh()->rol);
    }

    public function test_la_pantalla_explica_por_que_un_cliente_no_cambia_de_rol(): void
    {
        $admin = $this->admin();
        $this->crearUsuario(['name' => 'Cliente Fiel']);

        $this->actingAs($admin)
            ->get('/usuarios')
            ->assertOk()
            ->assertSee('no cambian de rol', false);
    }

    public function test_un_empleado_no_puede_cambiar_roles(): void
    {
        $empleado = $this->empleado();
        $otro = $this->empleado();

        // Ni siquiera llega a la pantalla: la ruta exige rol admin
        $this->cambiarRol($empleado, $otro, User::ROL_ADMIN)->assertRedirect(route('mostrador.index'));

        $this->assertSame(User::ROL_EMPLEADO, $otro->fresh()->rol);
    }

    public function test_un_empleado_no_puede_autopromoverse(): void
    {
        $empleado = $this->empleado();

        $this->cambiarRol($empleado, $empleado, User::ROL_ADMIN)->assertRedirect(route('mostrador.index'));

        $this->assertSame(User::ROL_EMPLEADO, $empleado->fresh()->rol);
    }

    // ── El super admin ───────────────────────────────────────────────────────

    public function test_nadie_puede_degradar_al_super_admin(): void
    {
        $super = $this->superAdmin();
        $otroAdmin = $this->admin();

        $this->cambiarRol($otroAdmin, $super, User::ROL_EMPLEADO)
            ->assertSessionHas('error', 'El rol del super administrador es inmutable.');

        $this->assertSame(User::ROL_ADMIN, $super->fresh()->rol);
    }

    public function test_el_super_admin_tampoco_se_degrada_a_si_mismo(): void
    {
        $super = $this->superAdmin();

        // Si pudiera, el sistema quedaría sin ninguna cuenta protegida
        $this->cambiarRol($super, $super, User::ROL_EMPLEADO)->assertSessionHas('error');

        $this->assertSame(User::ROL_ADMIN, $super->fresh()->rol);
    }

    public function test_la_pantalla_no_ofrece_cambiar_el_rol_del_super_admin(): void
    {
        $this->superAdmin();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/usuarios')
            ->assertOk()
            ->assertSee('Super admin')
            ->assertSee('El rol del super administrador es inmutable.');
    }

    // ── Un admin no se toca a sí mismo ───────────────────────────────────────

    public function test_un_admin_no_puede_cambiar_su_propio_rol(): void
    {
        $admin = $this->admin();

        // Degradarse solo es un tiro en el pie; el error es más claro que la
        // consecuencia de quedarse sin acceso.
        $this->cambiarRol($admin, $admin, User::ROL_EMPLEADO)
            ->assertSessionHas('error', 'No podés cambiar tu propio rol.');

        $this->assertSame(User::ROL_ADMIN, $admin->fresh()->rol);
    }

    // ── El caso que pediste: empleado promovido ──────────────────────────────

    public function test_un_empleado_promovido_a_admin_ya_puede_cambiar_roles(): void
    {
        $jefe = $this->admin();
        $empleado = $this->empleado();
        $otroAdmin = $this->admin();

        // Antes de la promoción no puede
        $this->cambiarRol($empleado, $otroAdmin, User::ROL_EMPLEADO)->assertRedirect(route('mostrador.index'));

        $this->cambiarRol($jefe, $empleado, User::ROL_ADMIN)->assertSessionHas('exito');

        // Ya como admin, tiene los mismos permisos que cualquier otro
        $this->cambiarRol($empleado->fresh(), $otroAdmin, User::ROL_EMPLEADO)->assertSessionHas('exito');

        $this->assertSame(User::ROL_EMPLEADO, $otroAdmin->fresh()->rol);
    }

    public function test_pero_al_super_admin_sigue_sin_poder_tocarlo(): void
    {
        $super = $this->superAdmin();
        $jefe = $this->admin();
        $empleado = $this->empleado();

        $this->cambiarRol($jefe, $empleado, User::ROL_ADMIN)->assertSessionHas('exito');

        $this->cambiarRol($empleado->fresh(), $super, User::ROL_EMPLEADO)
            ->assertSessionHas('error', 'El rol del super administrador es inmutable.');

        $this->assertSame(User::ROL_ADMIN, $super->fresh()->rol);
    }

    // ── Detalles ─────────────────────────────────────────────────────────────

    public function test_un_rol_inventado_se_rechaza(): void
    {
        $admin = $this->admin();
        $empleado = $this->empleado();

        $this->cambiarRol($admin, $empleado, 'dueño')->assertSessionHasErrors('rol');

        $this->assertSame(User::ROL_EMPLEADO, $empleado->fresh()->rol);
    }

    public function test_asignar_el_rol_que_ya_tiene_avisa_y_no_hace_nada(): void
    {
        $admin = $this->admin();
        $empleado = $this->empleado(['name' => 'Sin Cambios']);

        $this->cambiarRol($admin, $empleado, User::ROL_EMPLEADO)
            ->assertSessionHas('error');

        $this->assertSame(User::ROL_EMPLEADO, $empleado->fresh()->rol);
    }

    public function test_el_buscador_filtra_por_nombre_y_email(): void
    {
        $admin = $this->admin();
        $this->crearUsuario(['name' => 'Buscado Cliente', 'email' => 'buscado@beervana.test']);
        $this->crearUsuario(['name' => 'Otro Cliente', 'email' => 'otro@beervana.test']);

        $this->actingAs($admin)
            ->get('/usuarios?q=buscado')
            ->assertOk()
            ->assertSee('Buscado Cliente')
            ->assertDontSee('Otro Cliente');
    }
}
