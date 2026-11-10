<?php

use App\Models\User;
use App\Modulos\Compartido\Correo\EnviarCorreo;
use App\Modulos\SIT\Correo\RespuestaReclamo;
use App\Modulos\SIT\Jobs\EnviarLeadAlCrm;
use App\Modulos\SIT\Services\EnvioCrm;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/*
| Sprint 8 · Popup de salida (EDUN-271), envío al CRM con reintentos (EDUN-275), exportar y
| sincronizar (EDUN-282) y gestión de reclamaciones en el CMS (EDUN-302).
| Usan dueno(), visitante(), superadmin() y sembrarLeads() de ReclamosYBandejaTest.php.
*/

function leadPopup(array $cambios = []): array
{
    return ['email' => 'popup@s8.prueba', 'consentimiento' => true, 'page_url' => 'https://edunova.test/', ...$cambios];
}

function expedientePrueba(array $cambios = []): int
{
    $n = (int) dueno()->selectOne("select nextval('cms.reclamaciones_correlativo') as n")->n;

    return dueno()->table('cms.reclamaciones')->insertGetId([
        'numero_expediente' => sprintf('REC-2026-%04d', $n), 'tipo' => 'reclamo', 'nombre_reclamante' => 'Rosa Prueba', 'tipo_doc' => 'DNI',
        'numero_doc' => '45678912', 'email' => 'rosa@s8.prueba', 'producto_descripcion' => 'Plan Crecimiento', 'descripcion' => str_repeat('Detalle del reclamo. ', 4),
        'pedido' => 'Que se corrija.', 'vence_en' => now('America/Lima')->addDays(20)->toDateString(), ...$cambios,
    ]);
}

beforeEach(function () {
    Queue::fake();
    config(['services.crm.url' => null, 'services.crm.token' => null]);
});

afterEach(function () {
    dueno()->table('cms.leads')->where('email', 'like', '%@s8.prueba')->orWhere('email', 'like', '%@bandeja.prueba')->delete();
    dueno()->table('cms.reclamaciones')->where('email', 'rosa@s8.prueba')->delete();
});

// --- EDUN-271 · Popup de salida ------------------------------------------------------------

it('el popup registra un lead source=popup con su consentimiento y lo deja en cola para el CRM', function () {
    $this->postJson('/api/v1/sitio/leads/popup', leadPopup(), visitante())->assertCreated()->assertJsonPath('consentimiento_version', 'privacidad-v1.0');

    $lead = dueno()->table('cms.leads')->where('email', 'popup@s8.prueba')->first();
    expect($lead->source)->toBe('popup')->and($lead->demo_fecha)->toBeNull()
        ->and(dueno()->table('cms.consentimientos')->where('lead_id', $lead->id)->where('tipo', 'formulario')->exists())->toBeTrue()
        ->and(dueno()->table('cms.leads_pendientes')->where('lead_id', $lead->id)->exists())->toBeTrue();
    Queue::assertPushed(EnviarLeadAlCrm::class, fn ($j) => $j->leadId === $lead->id);
});

it('el popup pide correo o WhatsApp y el consentimiento', function () {
    $this->postJson('/api/v1/sitio/leads/popup', leadPopup(['email' => null]), visitante())
        ->assertUnprocessable()->assertJsonPath('errors.email.0', 'Escribe tu correo o tu WhatsApp.');
    $this->postJson('/api/v1/sitio/leads/popup', leadPopup(['consentimiento' => false]), visitante())->assertUnprocessable()->assertJsonValidationErrors('consentimiento');
    $this->postJson('/api/v1/sitio/leads/popup', leadPopup(['email' => null, 'telefono' => '987 654 321']), visitante())->assertCreated();
});

it('la landing entrega la configuración del popup solo si está activo', function () {
    $antes = dueno()->table('cms.popup_config')->first();
    dueno()->table('cms.popup_config')->updateOrInsert(['id' => 1], ['activo' => true, 'headline' => 'Antes de irte', 'cta_texto' => 'Enviar', 'capture_field' => 'email', 'delay_seg' => 5, 'inactividad_seg' => 0, 'frecuencia' => 'dia']);
    try {
        expect($this->getJson('/api/v1/sitio/landing')->json('popup'))->toMatchArray(['titulo' => 'Antes de irte', 'campo' => 'email', 'espera_seg' => 5, 'frecuencia' => 'dia']);
        dueno()->table('cms.popup_config')->where('id', 1)->update(['activo' => false]);
        expect($this->getJson('/api/v1/sitio/landing')->json('popup'))->toBeNull();
    } finally {
        $antes ? dueno()->table('cms.popup_config')->where('id', 1)->update((array) $antes) : dueno()->table('cms.popup_config')->delete();
    }
});

// --- EDUN-275 · CRM con reintentos ---------------------------------------------------------

it('sin CRM configurado el lead espera en la bandeja de salida sin gastar intentos', function () {
    $this->postJson('/api/v1/sitio/leads/popup', leadPopup(), visitante())->assertCreated();
    $id = dueno()->table('cms.leads')->where('email', 'popup@s8.prueba')->value('id');

    expect(app(EnvioCrm::class)->enviar($id))->toBe('sin_crm')
        ->and(dueno()->table('cms.leads_pendientes')->where('lead_id', $id)->value('intentos'))->toBe(0);
});

it('envía al CRM con token e idempotencia; si falla reintenta y al quinto intento queda con error', function () {
    config(['services.crm.url' => 'https://crm.test/leads', 'services.crm.token' => 'tkn']);
    $this->postJson('/api/v1/sitio/leads/popup', leadPopup(), visitante())->assertCreated();
    $id = dueno()->table('cms.leads')->where('email', 'popup@s8.prueba')->value('id');

    // Un solo fake con respuesta variable: Http::fake no reemplaza a uno anterior.
    $respuesta = ['cuerpo' => ['error' => 'caído'], 'estado' => 503];
    Http::fake(['crm.test/*' => function () use (&$respuesta) {
        return Http::response($respuesta['cuerpo'], $respuesta['estado']);
    }]);
    foreach (range(1, 4) as $i) {
        expect(fn () => app(EnvioCrm::class)->enviar($id))->toThrow(RuntimeException::class);
    }
    expect(app(EnvioCrm::class)->enviar($id))->toBe('agotado')
        ->and(dueno()->table('cms.leads_pendientes')->where('lead_id', $id)->value('intentos'))->toBe(5);

    // «Sincronizar con el CRM» desde la bandeja lo vuelve a encolar desde cero.
    Sanctum::actingAs(superadmin());
    $this->postJson('/api/v1/cms/leads/sincronizar', ['ids' => [$id]])->assertOk()->assertJsonPath('encolados', 1);
    expect(dueno()->table('cms.leads_pendientes')->where('lead_id', $id)->value('intentos'))->toBe(0);

    $respuesta = ['cuerpo' => ['id' => 'crm-123'], 'estado' => 201];
    expect(app(EnvioCrm::class)->enviar($id))->toBe('enviado');
    $lead = dueno()->table('cms.leads')->where('id', $id)->first();
    expect($lead->crm_ref)->toBe('crm-123')->and($lead->crm_enviado_en)->not->toBeNull()
        ->and(dueno()->table('cms.leads_pendientes')->where('lead_id', $id)->exists())->toBeFalse();
    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer tkn') && $r->hasHeader('Idempotency-Key', "edunova-lead-{$id}") && $r['email'] === 'popup@s8.prueba' && ! isset($r['ip']));
});

it('sincronizar sin CRM configurado responde 409 y explica que los leads quedan guardados', function () {
    Sanctum::actingAs(superadmin());
    $this->postJson('/api/v1/cms/leads/sincronizar')->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, 'quedan guardados'));
});

// --- EDUN-282 · Exportar CSV ----------------------------------------------------------------

it('exporta el CSV con los filtros de la bandeja, BOM para Excel y celdas protegidas contra fórmulas', function () {
    sembrarLeads(3, ['plan_interes' => 'integral']);
    sembrarLeads(2, ['plan_interes' => 'inicial', 'colegio' => '=HYPERLINK("x")']);
    Sanctum::actingAs(superadmin());

    $r = $this->get('/api/v1/cms/leads/exportar?plan=integral')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $csv = $r->getContent();
    $filas = array_values(array_filter(explode("\n", trim($csv))));
    expect(str_starts_with($csv, "\u{FEFF}"))->toBeTrue()
        ->and(count($filas))->toBe(4)
        ->and($filas[0])->toContain('Institución educativa');

    $todo = $this->get('/api/v1/cms/leads/exportar?plan=inicial')->getContent();
    expect($todo)->toContain("'=HYPERLINK")->not->toContain(',=HYPERLINK');
});

// --- EDUN-302 · Reclamaciones en el CMS -----------------------------------------------------

it('lista expedientes con indicadores de plazo y filtra vencidos', function () {
    $vencido = expedientePrueba(['vence_en' => now('America/Lima')->subDay()->toDateString()]);
    expedientePrueba();
    Sanctum::actingAs(superadmin());

    $r = $this->getJson('/api/v1/cms/reclamaciones?plazo=vencidos')->assertOk();
    expect(collect($r->json('data'))->pluck('id')->all())->toContain($vencido)
        ->and(collect($r->json('data'))->pluck('plazo')->unique()->values()->all())->toBe(['vencido'])
        ->and($r->json('indicadores.vencidos'))->toBeGreaterThanOrEqual(1);
});

it('responder exige texto, envía el correo al cliente, deja la auditoría y la respuesta ya no cambia', function () {
    $id = expedientePrueba();
    Sanctum::actingAs(superadmin());

    $this->patchJson("/api/v1/cms/reclamaciones/{$id}", ['estado' => 'respondido', 'respuesta_cliente' => 'corta'])
        ->assertUnprocessable()->assertJsonValidationErrors('respuesta_cliente');
    $this->patchJson("/api/v1/cms/reclamaciones/{$id}", ['estado' => 'cerrado'])->assertUnprocessable()->assertJsonValidationErrors('estado');

    $this->patchJson("/api/v1/cms/reclamaciones/{$id}", ['estado' => 'respondido', 'respuesta_cliente' => 'Regularizamos la emisión y descontamos el mes.'])
        ->assertOk()->assertJsonPath('data.estado', 'respondido');

    $correo = collect(Queue::pushed(EnviarCorreo::class))->first(fn ($j) => $j->destinatario === 'rosa@s8.prueba');
    expect($correo?->correo)->toBeInstanceOf(RespuestaReclamo::class)
        ->and(dueno()->table('cms.auditoria')->where('entidad', 'reclamaciones')->where('entidad_id', (string) $id)->where('campo', 'estado')->exists())->toBeTrue();

    // La base impide cambiar la respuesta enviada y saltar estados.
    expect(fn () => dueno()->table('cms.reclamaciones')->where('id', $id)->update(['respuesta_cliente' => 'otra']))->toThrow(QueryException::class)
        ->and(fn () => dueno()->table('cms.reclamaciones')->where('id', $id)->update(['estado' => 'recibido']))->toThrow(QueryException::class);
});

it('solo el superadmin gestiona reclamaciones, exporta o sincroniza', function () {
    $director = User::whereNotIn('id', dueno()->table('roles_plataforma')->pluck('user_id'))->firstOrFail();
    Sanctum::actingAs($director);
    $this->getJson('/api/v1/cms/reclamaciones')->assertForbidden();
    $this->get('/api/v1/cms/leads/exportar', ['Accept' => 'application/json'])->assertForbidden();
    $this->postJson('/api/v1/cms/leads/sincronizar')->assertForbidden();
});
