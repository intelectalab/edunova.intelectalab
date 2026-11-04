<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;

abstract class TestCase extends BaseTestCase
{
    private static bool $baseLista = false;

    /**
     * Reconstruye la base de pruebas (edunova_test) una vez por corrida:
     * esquema del kit, semillas y colegio de demostración, con el rol dueño.
     * Las pruebas luego corren con la conexión por defecto (edunova_app, con RLS).
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$baseLista) {
            Artisan::call('migrate:fresh', ['--database' => 'propietario', '--seed' => true, '--force' => true]);
            self::$baseLista = true;
        }
    }
}
