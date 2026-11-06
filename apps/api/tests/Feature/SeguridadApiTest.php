<?php

use Database\Seeders\DemoSeeder;

/*
| EDUN-209 · Límites de uso, CORS solo para orígenes propios y cabeceras de seguridad.
*/

it('responde con las cabeceras de seguridad', function () {
    $this->getJson('/api/v1/salud')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
});

it('CORS: acepta la web y el sitio propios y rechaza otros orígenes', function () {
    config(['cors.allowed_origins' => ['https://app.edunova.pe', 'https://edunova.pe']]);
    $preflight = fn (string $origen) => $this->call('OPTIONS', '/api/v1/auth/ingresar', [], [], [], [
        'HTTP_ORIGIN' => $origen,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
    ]);

    $preflight('https://app.edunova.pe')->assertHeader('Access-Control-Allow-Origin', 'https://app.edunova.pe');
    $preflight('https://edunova.pe')->assertHeader('Access-Control-Allow-Origin', 'https://edunova.pe');
    $preflight('https://sitio-malicioso.example')->assertHeaderMissing('Access-Control-Allow-Origin');
});

it('el ingreso tiene límite por IP además del bloqueo por cuenta', function () {
    // 20 intentos por minuto por IP, aunque cada uno use una cuenta distinta.
    foreach (range(1, 20) as $i) {
        $this->postJson('/api/v1/auth/ingresar', ['usuario' => "nadie{$i}@x.pe", 'password' => 'x'])->assertUnprocessable();
    }
    $this->postJson('/api/v1/auth/ingresar', ['usuario' => 'otro@x.pe', 'password' => DemoSeeder::DEMO_PASSWORD])
        ->assertStatus(429)->assertJson(['message' => 'Demasiadas solicitudes. Espera un minuto e inténtalo de nuevo.']);
});
