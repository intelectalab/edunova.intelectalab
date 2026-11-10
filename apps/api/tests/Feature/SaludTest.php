<?php

namespace Tests\Feature;

use Tests\TestCase;

class SaludTest extends TestCase
{
    public function test_la_ruta_de_salud_responde_bajo_api_v1(): void
    {
        $this->getJson('/api/v1/salud')
            ->assertOk()
            ->assertJsonPath('estado', 'ok')
            ->assertJsonPath('servicios.base_de_datos', 'ok')
            ->assertJsonPath('servicios.redis', 'ok')
            ->assertJsonPath('servicios.cola', 'ok')
            // EDUN-206 · Sin datos sensibles: ni versiones ni detalles de los servicios.
            ->assertJsonMissingPath('version');
    }
}
