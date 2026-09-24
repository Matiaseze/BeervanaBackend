<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreaDatos;
use Tests\TestCase;

/**
 * Borrado real de una cuenta.
 *
 * Es la operación más restringida del panel: solo el super admin, solo sobre
 * empleados y solo si no dejaron historial. Todo lo demás se bloquea, que corta
 * el acceso sin perder datos.
 */
class EliminarUsuariosTest extends TestCase
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

    private function eliminar(User $quien, User $aQuien)
    {
        return $this->actingAs($quien)->delete("/usuarios/{$aQuien->id}");
    }

    // ── El caso que sí funciona ──────────────────────────────────────────────

    public function test_el_super_admin_elimina_un_empleado_sin_historial(): void
    {
        $super = $this->superAdmin();
        $empleado = $this->empleado(['name' => 'Se Fue']);

        $this->eliminar($super, $empleado)->assertSessionHas('exito');

        $this->assertDatabaseMissing('users', ['id' => $empleado->id]);
    }

    public function test_eliminar_le_borra_los_tokens(): void
    {
        // Los tokens tienen su propia tabla y no se van solos: si quedaran,
        // apuntarían a un usuario que ya no existe.
        $super = $this->superAdmin();
        $empleado = $this->empleado();
        $this->tokenDe($empleado);

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->app['auth']->forgetGuards();
        $this->eliminar($super, $empleado)->assertSessionHas('exito');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    // ── Quién puede ──────────────────────────────────────────────────────────

    public function test_un_admin_comun_no_puede_eliminar(): void
    {
        $admin = $this->admin();
        $empleado = $this->empleado();

        $this->eliminar($admin, $empleado)
            ->assertSessionHas('error', 'Solo el super administrador puede eliminar cuentas.');

        $this->assertDatabaseHas('users', ['id' => $empleado->id]);
    }

    public function test_un_empleado_no_puede_eliminar(): void
    {
        $empleado = $this->empleado();
        $otro = $this->empleado();

        $this->eliminar($empleado, $otro)->assertRedirect(route('mostrador.index'));

        $this->assertDatabaseHas('users', ['id' => $otro->id]);
    }

    // ── A quién no ───────────────────────────────────────────────────────────

    public function test_no_se_elimina_a_un_administrador(): void
    {
        // Dos pasos deliberados en vez de uno: primero degradarlo, después
        // eliminarlo. Da tiempo a arrepentirse de algo irreversible.
        $super = $this->superAdmin();
        $admin = $this->admin();

        $this->eliminar($super, $admin)
            ->assertSessionHas('error', 'A un administrador no se lo elimina. Degradalo a empleado primero.');

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_degradar_al_admin_y_despues_eliminarlo_si_funciona(): void
    {
        $super = $this->superAdmin();
        $admin = $this->admin();

        $this->actingAs($super)->patch("/usuarios/{$admin->id}/rol", ['rol' => User::ROL_EMPLEADO])
            ->assertSessionHas('exito');

        $this->eliminar($super, $admin->fresh())->assertSessionHas('exito');

        $this->assertDatabaseMissing('users', ['id' => $admin->id]);
    }

    public function test_no_se_elimina_a_un_cliente(): void
    {
        $super = $this->superAdmin();
        $cliente = $this->crearUsuario();

        $this->eliminar($super, $cliente)->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $cliente->id]);
    }

    public function test_el_super_admin_no_se_elimina_a_si_mismo(): void
    {
        $super = $this->superAdmin();

        $this->eliminar($super, $super)
            ->assertSessionHas('error', 'No podés eliminar tu propia cuenta.');

        $this->assertDatabaseHas('users', ['id' => $super->id]);
    }

    // ── El historial manda ───────────────────────────────────────────────────

    public function test_no_se_elimina_a_un_empleado_que_ya_cobro(): void
    {
        // facturas.cobrado_por es nullOnDelete, así que el DELETE no fallaría:
        // dejaría las facturas sin saber quién atendió, que es medio arqueo de
        // caja tirado a la basura sin avisar.
        $super = $this->superAdmin();
        $cajero = $this->empleado();
        $cliente = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);

        $pedido = $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 2]],
            'metodo_entrega' => 'retiro',
        ], $this->tokenDe($cliente))->assertCreated()->json('pedido');

        $this->app['auth']->forgetGuards();
        $this->postJson(
            "/my_api/mostrador/pedidos/{$pedido['id']}/cobrar",
            [],
            $this->tokenDe($cajero)
        )->assertCreated();
        $this->app['auth']->forgetGuards();

        $this->eliminar($super, $cajero->fresh())
            ->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $cajero->id]);
        // Y la factura conserva a quién atendió
        $this->assertDatabaseHas('facturas', ['cobrado_por' => $cajero->id]);
    }

    public function test_a_ese_empleado_si_se_lo_puede_bloquear(): void
    {
        // La salida para el cajero que renunció: se le corta el acceso y el
        // historial de sus cobros queda intacto.
        $super = $this->superAdmin();
        $cajero = $this->empleado();
        $cliente = $this->crearUsuario();
        $cerveza = $this->crearCerveza(['stock' => 10]);

        $pedido = $this->postJson('/my_api/pedidos', [
            'items' => [['id' => $cerveza->id, 'cantidad' => 1]],
            'metodo_entrega' => 'retiro',
        ], $this->tokenDe($cliente))->assertCreated()->json('pedido');

        $this->app['auth']->forgetGuards();
        $this->postJson(
            "/my_api/mostrador/pedidos/{$pedido['id']}/cobrar",
            [],
            $this->tokenDe($cajero)
        )->assertCreated();
        $this->app['auth']->forgetGuards();

        $this->actingAs($super)->post("/usuarios/{$cajero->id}/bloquear")
            ->assertSessionHas('exito');

        $this->assertTrue($cajero->fresh()->estaBloqueado());
        $this->assertDatabaseHas('facturas', ['cobrado_por' => $cajero->id]);
    }

    public function test_la_pantalla_apaga_el_boton_con_el_motivo(): void
    {
        $super = $this->superAdmin();
        $this->admin(['name' => 'Otro Jefe']);

        $this->actingAs($super)
            ->get('/usuarios')
            ->assertOk()
            ->assertSee('Degradalo a empleado primero.');
    }
}
