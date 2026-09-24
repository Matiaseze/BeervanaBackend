<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Red de seguridad: RefreshDatabase corre `migrate:fresh`, que dropea todas
     * las tablas. Si la conexión apuntara a la base de desarrollo, se perderían
     * todos los datos.
     *
     * La verificación va en setUpTraits() y NO en setUp(): setUp() del padre es
     * justamente quien invoca a los traits, así que un chequeo puesto después de
     * parent::setUp() llega tarde — RefreshDatabase ya vació la base. Pasó de
     * verdad mientras se armaba esta suite: la primera corrida, con phpunit.xml
     * todavía mal configurado, borró la base de desarrollo antes de que el
     * chequeo llegara a abortar.
     *
     * setUpTraits() se ejecuta después de refreshApplication() (o sea que la
     * config ya está disponible) y antes de que cualquier trait haga nada.
     */
    protected function setUpTraits()
    {
        $conexion = config('database.default');
        $base = config("database.connections.{$conexion}.database");

        if (! str_contains((string) $base, 'test')) {
            throw new RuntimeException(
                "ABORTADO: los tests apuntan a la base '{$base}', que no parece de test. ".
                "RefreshDatabase la habría vaciado. Revisá los <server> y <env> de DB_DATABASE ".
                "en phpunit.xml (hacen falta los dos, con force=\"true\")."
            );
        }

        return parent::setUpTraits();
    }
}
