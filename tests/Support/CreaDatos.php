<?php

namespace Tests\Support;

use App\Models\Cerveza;
use App\Models\Estilo;
use App\Models\Marca;
use App\Models\TipoFermentacion;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Datos de prueba para la suite.
 *
 * No se usan factories de Laravel porque los modelos del dominio no tienen el
 * trait HasFactory. Si algún día se agrega, esto se puede reemplazar por
 * database/factories sin tocar los tests que usan estos helpers.
 */
trait CreaDatos
{
    protected int $contador = 0;

    /** Se reusa el mismo usuario de mostrador en todo el test. */
    protected ?User $personal = null;

    protected function crearUsuario(array $attrs = []): User
    {
        $this->contador++;

        return User::create(array_merge([
            'name' => 'Usuario '.$this->contador,
            'email' => 'usuario'.$this->contador.'@beervana.test',
            'password' => Hash::make('secret123'),
            'rol' => User::ROL_CLIENTE,
        ], $attrs));
    }

    protected function crearCerveza(array $attrs = []): Cerveza
    {
        $this->contador++;

        $marca = Marca::firstOrCreate(['nombre' => $attrs['marca'] ?? 'Marca de prueba']);

        // levadura, temperatura y tiempo son NOT NULL (los agregó una migración
        // posterior a la creación de la tabla), así que hay que darles valor.
        $tipo = TipoFermentacion::firstOrCreate(
            ['nombre' => 'Alta'],
            [
                'descripcion' => 'Fermentación alta',
                'levadura' => 'Saccharomyces cerevisiae',
                'temperatura' => '15-22 °C',
                'tiempo' => '3-7 días',
            ]
        );
        $estilo = Estilo::firstOrCreate(
            ['nombre' => $attrs['estilo'] ?? 'IPA'],
            ['tipo_fermentacion_id' => $tipo->id]
        );

        unset($attrs['marca'], $attrs['estilo']);

        return Cerveza::create(array_merge([
            'nombre' => 'Cerveza '.$this->contador,
            'precio' => 2500.00,
            'marca_id' => $marca->id,
            'graduacion' => 4.60,
            'tipo_envase' => 'Lata',
            'estilo_id' => $estilo->id,
            'ibu' => 35,
            'capacidad' => '473 ml',
            'imagen' => 'https://example.test/cerveza.png',
            'stock' => 10,
            'descripcion' => 'Cerveza para tests',
        ], $attrs));
    }

    /**
     * Registra el cobro de un pedido como lo haría quien atiende.
     *
     * El cobro es presencial y solo el personal puede registrarlo, así que los
     * tests que necesitan un pedido pagado tienen que pasar por acá y no por el
     * usuario cliente.
     */
    protected function cobrarEnMostrador(int $pedidoId)
    {
        $personal = $this->personal ??= $this->crearUsuario(['rol' => User::ROL_ADMIN]);

        $respuesta = $this->postJson(
            "/my_api/mostrador/pedidos/{$pedidoId}/cobrar",
            [],
            $this->tokenDe($personal)
        );

        // Olvidar el guard también al salir: si no, el guard queda resuelto con
        // el usuario de mostrador y los requests siguientes del cliente se
        // ejecutarían como si fuera él, aunque manden su propio token.
        $this->app['auth']->forgetGuards();

        return $respuesta;
    }

    /**
     * Cabeceras con el token de un usuario ya logueado.
     *
     * El forgetGuards es por el harness, no por la app: dentro de un mismo test
     * se reusa la instancia de la aplicación y el guard de Sanctum se queda con
     * el usuario que resolvió la primera vez. Sin esto, un test que actúa como
     * dos usuarios distintos ejecutaría los dos requests como el primero, y los
     * casos de aislamiento entre usuarios pasarían sin probar nada.
     * En producción cada request arranca con la aplicación limpia.
     */
    protected function tokenDe(User $user): array
    {
        $this->app['auth']->forgetGuards();

        return [
            'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ];
    }
}
