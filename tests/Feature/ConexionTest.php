<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifica que la suite corra contra la base de tests y no contra la de
 * desarrollo. Si este test falla, no ejecutes el resto.
 */
class ConexionTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_suite_usa_la_base_de_tests(): void
    {
        $this->assertSame('beervana_test', DB::connection()->getDatabaseName());
        $this->assertSame('testing', app()->environment());
    }
}
