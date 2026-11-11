<?php

use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
| Sprint 24 · Constructor visual de flujos del chatbot (EDUN-379/380/382 · SIT-028): nodos
| mensaje, opciones, captura, URL, WhatsApp y fin con su posición en el lienzo; publicar llega al
| sitio y a la auditoría; historial de sesiones con filtros. Usan dueno(), superadmin() y visitante().
*/

function publicarChatbotS24(array $contenido): void
{
    test()->putJson('/api/v1/cms/secciones/_chatbot', ['contenido' => $contenido])->assertOk();
    test()->postJson('/api/v1/cms/publicar', ['claves' => ['_chatbot']])->assertOk();
}

function flujoS24(): array
{
    return [
        ['tipo' => 'mensaje', 'texto' => 'Hola, soy el asistente.', 'url' => null, 'siguiente' => 1, 'opciones' => [], 'posicion' => ['x' => 40, 'y' => 40]],
        ['tipo' => 'opciones', 'texto' => '¿Qué buscas?', 'url' => null, 'siguiente' => null, 'opciones' => [['texto' => 'Precios', 'destino' => 2], ['texto' => 'Asesor', 'destino' => 3], ['texto' => 'Nada', 'destino' => 4]], 'posicion' => ['x' => 40, 'y' => 200]],
        ['tipo' => 'url', 'texto' => 'Mira los planes', 'url' => '/planes', 'siguiente' => null, 'opciones' => [], 'posicion' => ['x' => 0, 'y' => 380]],
        ['tipo' => 'whatsapp', 'texto' => 'Te pasamos con un asesor', 'url' => null, 'siguiente' => null, 'opciones' => [], 'posicion' => ['x' => 280, 'y' => 380]],
        ['tipo' => 'fin', 'texto' => 'Gracias por escribirnos', 'url' => null, 'siguiente' => null, 'opciones' => [], 'posicion' => null],
        ['tipo' => 'captura', 'texto' => 'Déjanos tu contacto', 'url' => null, 'siguiente' => null, 'opciones' => [], 'posicion' => ['x' => 560, 'y' => 380]],
    ];
}

it('constructor: publica los seis tipos de paso con su posición en el lienzo; llega al sitio y a la auditoría', function () {
    Sanctum::actingAs(superadmin());
    dueno()->table('cms.config')->updateOrInsert(['clave' => 'whatsapp'], ['valor' => json_encode('+51 999 888 777')]);
    $base = $this->getJson('/api/v1/cms/secciones/_chatbot')->json('data.publicado');
    $auditoria = dueno()->table('cms.auditoria')->where('seccion', '_chatbot')->count();

    publicarChatbotS24([...$base, 'activo' => true, 'modo' => 'flujo', 'nodos' => flujoS24()]);

    $c = $this->getJson('/api/v1/sitio/landing')->json('chatbot');
    expect(collect($c['nodos'])->pluck('tipo')->all())->toBe(['mensaje', 'opciones', 'url', 'whatsapp', 'fin', 'captura'])
        ->and($c['nodos'][0]['siguiente'])->toBe(1)
        ->and($c['nodos'][1]['opciones'][1])->toBe(['texto' => 'Asesor', 'destino' => 3]);
    $vista = $this->getJson('/api/v1/cms/secciones/_chatbot')->json('data.publicado.nodos');
    expect($vista[1]['posicion'])->toBe(['x' => 40, 'y' => 200])->and($vista[4]['posicion'])->toBeNull();
    expect(dueno()->table('cms.auditoria')->where('seccion', '_chatbot')->count())->toBeGreaterThan($auditoria);
});

it('constructor: rechaza posiciones fuera del lienzo y destinos que no existen', function () {
    Sanctum::actingAs(superadmin());
    $base = $this->getJson('/api/v1/cms/secciones/_chatbot')->json('data.publicado');
    $nodos = flujoS24();
    $nodos[0]['posicion'] = ['x' => 5000, 'y' => 10];
    $this->putJson('/api/v1/cms/secciones/_chatbot', ['contenido' => [...$base, 'modo' => 'flujo', 'nodos' => $nodos]])
        ->assertUnprocessable()->assertJsonValidationErrors('nodos.0.posicion.x');
    $nodos = flujoS24();
    $nodos[0]['siguiente'] = 9;
    $this->putJson('/api/v1/cms/secciones/_chatbot', ['contenido' => [...$base, 'modo' => 'flujo', 'nodos' => $nodos]])
        ->assertUnprocessable()->assertJsonValidationErrors('nodos.0.siguiente');
});

it('sesiones: el sitio registra la conversación una sola vez y sin datos de la URL', function () {
    $uuid = (string) Str::uuid();
    $cuerpo = ['sesion_uuid' => $uuid, 'resultado' => 'whatsapp', 'recorrido' => ['Edunova: ¿Qué buscas?', 'Tú: Asesor'], 'page_url' => 'https://sitio.prueba/planes?utm_source=x&email=a@b.pe#demo'];
    $this->postJson('/api/v1/sitio/chatbot/sesion', $cuerpo, visitante())->assertCreated();
    $this->postJson('/api/v1/sitio/chatbot/sesion', $cuerpo, visitante())->assertCreated();
    $s = dueno()->table('cms.chatbot_sesiones')->where('sesion_uuid', $uuid)->get();
    expect($s)->toHaveCount(1)->and($s[0]->pagina)->toBe('/planes')->and($s[0]->resultado)->toBe('whatsapp')
        ->and(dueno()->table('cms.chatbot_mensajes')->where('sesion_id', $s[0]->id)->pluck('rol')->all())->toBe(['bot', 'visitante']);
    $this->postJson('/api/v1/sitio/chatbot/sesion', [...$cuerpo, 'resultado' => 'captura'], visitante())->assertUnprocessable();
    $this->postJson('/api/v1/sitio/chatbot/sesion', [...$cuerpo, 'sesion_uuid' => 'no-es-uuid'], visitante())->assertUnprocessable();
});

it('sesiones: historial con filtros por resultado, contacto, fecha y texto, y el detalle de la conversación', function () {
    $marca = 'marca-'.Str::random(8);
    $this->postJson('/api/v1/sitio/chatbot/sesion', ['sesion_uuid' => (string) Str::uuid(), 'resultado' => 'abandono', 'recorrido' => ["Tú: $marca"]], visitante())->assertCreated();
    $email = 'chat.'.Str::random(6).'@s24.prueba';
    $this->postJson('/api/v1/sitio/chatbot/lead', ['email' => $email, 'consentimiento' => true, 'recorrido' => ["Tú: $marca precios"], 'page_url' => 'https://sitio.prueba/'], visitante())->assertCreated();

    Sanctum::actingAs(superadmin());
    $todas = $this->getJson('/api/v1/cms/chatbot/sesiones?q='.$marca)->assertOk();
    expect($todas->json('total'))->toBe(2)->and($todas->json('por_resultado.captura'))->toBe(1)->and($todas->json('por_resultado.abandono'))->toBe(1);
    expect($this->getJson("/api/v1/cms/chatbot/sesiones?q=$marca&lead=si")->json('data.0.resultado'))->toBe('captura')
        ->and($this->getJson("/api/v1/cms/chatbot/sesiones?q=$marca&resultado=abandono")->json('total'))->toBe(1)
        ->and($this->getJson("/api/v1/cms/chatbot/sesiones?q=$marca&desde=".now('America/Lima')->addDay()->toDateString())->json('total'))->toBe(0);

    $id = $this->getJson("/api/v1/cms/chatbot/sesiones?q=$marca&lead=si")->json('data.0.id');
    $d = $this->getJson("/api/v1/cms/chatbot/sesiones/$id")->assertOk();
    expect($d->json('data.lead_email'))->toBe(strtolower($email))->and($d->json('data.mensajes.0.contenido'))->toContain($marca);
    $this->getJson('/api/v1/cms/chatbot/sesiones/999999999')->assertNotFound();
    $this->getJson('/api/v1/cms/chatbot/sesiones?resultado=otro')->assertUnprocessable();
});

it('sesiones y constructor: sin permiso de CMS la API responde 403', function () {
    Sanctum::actingAs(personaDemo('director'));
    $this->getJson('/api/v1/cms/chatbot/sesiones')->assertForbidden();
    $this->getJson('/api/v1/cms/chatbot/sesiones/1')->assertForbidden();
    $this->putJson('/api/v1/cms/secciones/_chatbot', ['contenido' => []])->assertForbidden();
});
