<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreaDatos;
use Tests\TestCase;

class AutenticacionTest extends TestCase
{
    use RefreshDatabase;
    use CreaDatos;

    public function test_registro_crea_el_usuario(): void
    {
        $respuesta = $this->postJson('/my_api/register', [
            'name' => 'Admin',
            'email' => 'nuevo@beervana.test',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $respuesta->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'nuevo@beervana.test']);
    }

    public function test_registro_no_permite_emails_repetidos(): void
    {
        $this->crearUsuario(['email' => 'repetido@beervana.test']);

        $this->postJson('/my_api/register', [
            'name' => 'Otro',
            'email' => 'repetido@beervana.test',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertSame(1, \App\Models\User::where('email', 'repetido@beervana.test')->count());
    }

    public function test_registro_exige_confirmar_la_password(): void
    {
        $this->postJson('/my_api/register', [
            'name' => 'Admin',
            'email' => 'sinconfirmar@beervana.test',
            'password' => 'secret123',
            'password_confirmation' => 'otracosa',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_el_registro_no_devuelve_token(): void
    {
        // El frontend depende de esto: después de registrarse tiene que llamar
        // a /login por separado para obtener el token.
        $this->postJson('/my_api/register', [
            'name' => 'Admin',
            'email' => 'token@beervana.test',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertCreated()->assertJsonMissing(['token']);
    }

    public function test_login_devuelve_un_token_usable(): void
    {
        $this->crearUsuario(['email' => 'login@beervana.test']);

        $respuesta = $this->postJson('/my_api/login', [
            'email' => 'login@beervana.test',
            'password' => 'secret123',
        ])->assertOk()->assertJsonStructure(['user', 'token']);

        $token = $respuesta->json('token');

        $this->getJson('/my_api/carrito', ['Authorization' => 'Bearer '.$token])
            ->assertOk();
    }

    public function test_login_rechaza_credenciales_invalidas(): void
    {
        $this->crearUsuario(['email' => 'malpass@beervana.test']);

        $this->postJson('/my_api/login', [
            'email' => 'malpass@beervana.test',
            'password' => 'incorrecta',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_login_rechaza_un_email_inexistente(): void
    {
        $this->postJson('/my_api/login', [
            'email' => 'nadie@beervana.test',
            'password' => 'secret123',
        ])->assertStatus(422);
    }

    public function test_logout_invalida_el_token(): void
    {
        $user = $this->crearUsuario();
        $cabeceras = $this->tokenDe($user);

        $this->getJson('/my_api/carrito', $cabeceras)->assertOk();

        $this->postJson('/my_api/logout', [], $cabeceras)->assertOk();

        // El token se borró de verdad, no solo "en la sesión"
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // forgetGuards es necesario por cómo funciona el harness, no por la app:
        // dentro de un mismo test se reusa la instancia de la aplicación y el
        // guard de Sanctum conserva el usuario que ya resolvió. En producción
        // cada request arranca de cero. Sin esto el request de abajo daría 200
        // aunque el token ya no exista en la base.
        $this->app['auth']->forgetGuards();

        $this->getJson('/my_api/carrito', $cabeceras)->assertUnauthorized();
    }

    #[DataProvider('rutasProtegidas')]
    public function test_las_rutas_protegidas_exigen_token(string $metodo, string $ruta): void
    {
        $this->json($metodo, $ruta)->assertUnauthorized();
    }

    public static function rutasProtegidas(): array
    {
        return [
            'ver cervezas' => ['GET', '/my_api/cervezas'],
            'ver carrito' => ['GET', '/my_api/carrito'],
            'agregar al carrito' => ['POST', '/my_api/carrito/agregar'],
            'sincronizar carrito' => ['POST', '/my_api/carrito/sincronizar'],
            'vaciar carrito' => ['POST', '/my_api/carrito/limpiar'],
            'crear pedido' => ['POST', '/my_api/pedidos'],
            'listar pedidos' => ['GET', '/my_api/pedidos'],
            'cobrar en mostrador' => ['POST', '/my_api/mostrador/pedidos/1/cobrar'],
            'buscar por codigo' => ['GET', '/my_api/mostrador/pedido'],
            'listar facturas' => ['GET', '/my_api/facturas'],
            'logout' => ['POST', '/my_api/logout'],
        ];
    }

    #[DataProvider('rutasPublicas')]
    public function test_las_rutas_de_catalogo_son_publicas(string $ruta): void
    {
        $this->getJson($ruta)->assertOk();
    }

    public static function rutasPublicas(): array
    {
        return [
            'marcas' => ['/my_api/marcas'],
            'estilos' => ['/my_api/estilos'],
        ];
    }
}
