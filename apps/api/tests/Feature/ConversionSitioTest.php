<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| Sprint 6 · Conversión y legal del sitio público.
|   EDUN-267 / SIT-002  Solicitar demo con calendario
|   EDUN-287 / SIT-053  Políticas vigentes
|   EDUN-291 / SIT-054  Registro de consentimientos en formularios
|   EDUN-306 / SIT-055  Preferencias del banner de cookies
| Las pruebas leen y limpian con la conexión dueña (sin RLS).
*/

function propietario()
{
    return DB::connection('propietario');
}

/** Próximo día hábil a partir de pasado mañana (hora de Lima). */
function diaHabil(int $desde = 2): string
{
    $d = CarbonImmutable::now('America/Lima')->startOfDay()->addDays($desde);
    while ($d->isWeekend()) {
        $d = $d->addDay();
    }

    return $d->toDateString();
}

function proximoSabado(): string
{
    return CarbonImmutable::now('America/Lima')->startOfDay()->next(CarbonImmutable::SATURDAY)->toDateString();
}

/** El servidor del sitio reenvía la IP del visitante: cada prueba usa la suya (límite 10/min). */
function comoSitio(?string $ip = null): array
{
    config()->set('services.sitio.proxy_secreto', 'secreto-de-prueba');

    return ['X-Sitio-Secreto' => 'secreto-de-prueba', 'X-Visitante-Ip' => $ip ?? '203.0.113.'.random_int(1, 254), 'X-Visitante-Agente' => 'Navegador de prueba/1.0'];
}

function demo(array $cambios = []): array
{
    return [
        'source' => 'demo',
        'nombre' => 'Rosa Quispe',
        'cargo' => 'Director(a)',
        'telefono' => '987 654 321',
        'email' => 'Rosa.Prueba@Ejemplo.pe',
        'colegio' => 'Colegio de prueba',
        'alumnos' => 300,
        'plan_interes' => 'integral',
        'demo_fecha' => diaHabil(),
        'demo_hora' => '10:30',
        'consentimiento' => true,
        'origen' => 'plan',
        ...$cambios,
    ];
}

function limpiarLeads(string $email): void
{
    $ids = propietario()->table('cms.leads')->where('email', $email)->pluck('id');
    propietario()->table('cms.consentimientos')->whereIn('lead_id', $ids)->delete();
    propietario()->table('cms.leads')->whereIn('id', $ids)->delete();
}

afterEach(fn () => limpiarLeads('rosa.prueba@ejemplo.pe'));

// --- EDUN-267 / SIT-002 ---------------------------------------------------------------------

it('una demo completa deja un lead source=demo con fecha y hora, y su consentimiento con la versión vigente', function () {
    $fecha = diaHabil();
    $r = $this->postJson('/api/v1/sitio/leads', demo(['demo_fecha' => $fecha]), comoSitio('198.51.100.7'))
        ->assertCreated()
        ->assertJson(['demo_fecha' => $fecha, 'demo_hora' => '10:30', 'email' => 'rosa.prueba@ejemplo.pe', 'consentimiento_version' => 'privacidad-v1.0']);

    $lead = propietario()->table('cms.leads')->where('email', 'rosa.prueba@ejemplo.pe')->first();
    expect($lead->source)->toBe('demo')
        ->and($lead->demo_fecha)->toBe($fecha)
        ->and(substr($lead->demo_hora, 0, 5))->toBe('10:30')
        ->and($lead->plan_interes)->toBe('integral')
        ->and($lead->mensaje)->toBe('Demo solicitada desde: plan')
        ->and($lead->consentimiento_version)->toBe($r->json('consentimiento_version'));

    // EDUN-291 · Prueba del consentimiento: tipo, versión, lead, IP, navegador y fecha.
    $c = propietario()->table('cms.consentimientos')->where('lead_id', $lead->id)->first();
    expect($c->tipo)->toBe('formulario')
        ->and($c->version_politica)->toBe('privacidad-v1.0')
        ->and($c->ip)->toBe('198.51.100.7')
        ->and($c->user_agent)->toBe('Navegador de prueba/1.0')
        ->and($c->created_at)->not->toBeNull();
});

it('rechaza un sábado, el día de hoy y una hora fuera de la lista', function () {
    $this->postJson('/api/v1/sitio/leads', demo(['demo_fecha' => proximoSabado()]), comoSitio())
        ->assertUnprocessable()->assertJsonPath('errors.demo_fecha.0', 'La demo se agenda de lunes a viernes.');
    $this->postJson('/api/v1/sitio/leads', demo(['demo_fecha' => CarbonImmutable::now('America/Lima')->toDateString()]), comoSitio())
        ->assertUnprocessable()->assertJsonValidationErrors('demo_fecha');
    $this->postJson('/api/v1/sitio/leads', demo(['demo_hora' => '11:00']), comoSitio())
        ->assertUnprocessable()->assertJsonValidationErrors('demo_hora');

    expect(propietario()->table('cms.leads')->where('email', 'rosa.prueba@ejemplo.pe')->count())->toBe(0);
});

it('la base también impide agendar la demo hoy o sin hora', function () {
    $hoy = CarbonImmutable::now('America/Lima');
    $habilHoy = $hoy->isWeekend() ? null : $hoy->toDateString();
    if ($habilHoy) {
        expect(fn () => propietario()->table('cms.leads')->insert(['source' => 'demo', 'email' => 'rosa.prueba@ejemplo.pe', 'demo_fecha' => $habilHoy, 'demo_hora' => '09:00', 'consentimiento_version' => 'privacidad-v1.0']))
            ->toThrow(QueryException::class, 'desde mañana');
    }
    expect(fn () => propietario()->table('cms.leads')->insert(['source' => 'demo', 'email' => 'rosa.prueba@ejemplo.pe', 'demo_fecha' => diaHabil(), 'consentimiento_version' => 'privacidad-v1.0']))
        ->toThrow(QueryException::class, 'fecha y hora');
});

it('el plan de interés solo admite los planes del sitio', function () {
    $this->postJson('/api/v1/sitio/leads', demo(['plan_interes' => 'premium']), comoSitio())->assertUnprocessable()->assertJsonValidationErrors('plan_interes');
    $this->postJson('/api/v1/sitio/leads', demo(['plan_interes' => null]), comoSitio())->assertCreated();
});

it('el campo trampa bloquea a los robots', function () {
    $this->postJson('/api/v1/sitio/leads', demo(['sitio_web' => 'https://spam.example']), comoSitio())->assertUnprocessable();
    expect(propietario()->table('cms.leads')->where('email', 'rosa.prueba@ejemplo.pe')->count())->toBe(0);
});

// --- EDUN-291 / SIT-054 ---------------------------------------------------------------------

it('sin consentimiento la API responde 422 y no guarda nada', function () {
    foreach ([false, null, 'no'] as $valor) {
        $this->postJson('/api/v1/sitio/leads', demo(['consentimiento' => $valor]), comoSitio())
            ->assertUnprocessable()
            ->assertJsonPath('errors.consentimiento.0', 'Debes aceptar la Política de Privacidad para enviar la solicitud.');
    }
    expect(propietario()->table('cms.leads')->where('email', 'rosa.prueba@ejemplo.pe')->count())->toBe(0);
});

it('guarda la versión vigente al momento del envío', function () {
    $nueva = propietario()->table('cms.politicas')->insertGetId(['tipo' => 'privacidad', 'version' => '9.9', 'contenido' => '<p>Nueva</p>', 'vigente_desde' => now('America/Lima')->toDateString()]);
    try {
        $this->postJson('/api/v1/sitio/leads', demo(), comoSitio())->assertCreated()->assertJsonPath('consentimiento_version', 'privacidad-v9.9');
    } finally {
        limpiarLeads('rosa.prueba@ejemplo.pe');
        propietario()->table('cms.politicas')->where('id', $nueva)->delete();
    }
});

it('un visitante no puede leer consentimientos ni leads', function () {
    $this->postJson('/api/v1/sitio/leads', demo(), comoSitio())->assertCreated();

    // No hay ruta pública de lectura…
    $this->getJson('/api/v1/sitio/leads')->assertStatus(405);
    $this->getJson('/api/v1/sitio/consentimientos/cookies')->assertStatus(405);
    $this->getJson('/api/v1/sitio/consentimientos')->assertNotFound();

    // …y aunque la hubiera, RLS no le deja ver nada con el rol público.
    $visibles = DB::transaction(function () {
        DB::select("select set_config('app.rol_cms', 'publico', true)");

        return [DB::table('cms.consentimientos')->count(), DB::table('cms.leads')->count()];
    });
    expect($visibles)->toBe([0, 0]);
});

it('sin el secreto del sitio se ignora la IP reenviada', function () {
    config()->set('services.sitio.proxy_secreto', 'secreto-de-prueba');
    $this->postJson('/api/v1/sitio/leads', demo(), ['X-Sitio-Secreto' => 'falso', 'X-Visitante-Ip' => '198.51.100.99'])->assertCreated();

    $lead = propietario()->table('cms.leads')->where('email', 'rosa.prueba@ejemplo.pe')->value('id');
    expect(propietario()->table('cms.consentimientos')->where('lead_id', $lead)->value('ip'))->toBe('127.0.0.1');
});

// --- EDUN-287 / SIT-053 ---------------------------------------------------------------------

it('entrega la versión vigente de cada política, marcada como borrador hasta la revisión legal', function (string $tipo, string $titulo) {
    $this->getJson("/api/v1/sitio/politicas/{$tipo}")->assertOk()
        ->assertJson(['tipo' => $tipo, 'titulo' => $titulo, 'version' => '1.0', 'borrador' => true])
        ->assertJsonPath('contenido', fn ($html) => str_contains($html, '<h3>'));
})->with([
    ['privacidad', 'Política de Privacidad'],
    ['terminos', 'Términos y Condiciones del servicio'],
    ['seguridad', 'Seguridad de Datos (LPDP)'],
    ['cookies', 'Política de Cookies'],
]);

it('dadas dos versiones, se muestra la nueva solo cuando entra en vigencia', function () {
    $manana = now('America/Lima')->addDay()->toDateString();
    $id = propietario()->table('cms.politicas')->insertGetId(['tipo' => 'terminos', 'version' => '2.0', 'contenido' => '<p>Versión dos</p>', 'vigente_desde' => $manana]);
    try {
        $this->getJson('/api/v1/sitio/politicas/terminos')->assertJsonPath('version', '1.0');

        propietario()->table('cms.politicas')->where('id', $id)->update(['vigente_desde' => now('America/Lima')->toDateString()]);
        $this->getJson('/api/v1/sitio/politicas/terminos')->assertJson(['version' => '2.0', 'borrador' => false, 'contenido' => '<p>Versión dos</p>']);
    } finally {
        propietario()->table('cms.politicas')->where('id', $id)->delete();
    }
});

it('reemplaza los datos de la empresa y limpia el HTML', function () {
    $id = propietario()->table('cms.politicas')->insertGetId([
        'tipo' => 'seguridad', 'version' => '3.0', 'vigente_desde' => now('America/Lima')->toDateString(),
        'contenido' => '<p onclick="x()" style="color:red">RUC {{ruc}}</p><script>alert(1)</script><a href="javascript:alert(1)">mal</a><a href="/libro-de-reclamaciones">bien</a><div>texto</div>',
    ]);
    $ruc = propietario()->table('cms.config')->where('clave', 'ruc')->value('valor');
    try {
        $html = $this->getJson('/api/v1/sitio/politicas/seguridad')->json('contenido');
        $esperado = is_string(json_decode((string) $ruc, true)) && json_decode((string) $ruc, true) !== '' ? json_decode($ruc, true) : '[A CONFIRMAR]';
        expect($html)->toBe('<p>RUC '.$esperado.'</p><a>mal</a><a href="/libro-de-reclamaciones">bien</a>texto');
    } finally {
        propietario()->table('cms.politicas')->where('id', $id)->delete();
    }
});

it('responde 404 para un tipo de política desconocido', function () {
    $this->getJson('/api/v1/sitio/politicas/otra')->assertNotFound();
});

// --- EDUN-306 / SIT-055 ---------------------------------------------------------------------

it('registra las preferencias de cookies con «esenciales» siempre incluida', function () {
    $ip = '192.0.2.44';
    $this->postJson('/api/v1/sitio/consentimientos/cookies', ['categorias' => ['analitica']], comoSitio($ip))
        ->assertCreated()->assertJsonPath('version_politica', 'cookies-v1.0');
    $this->postJson('/api/v1/sitio/consentimientos/cookies', ['categorias' => []], comoSitio($ip))->assertCreated();

    $filas = propietario()->table('cms.consentimientos')->where('tipo', 'cookies')->where('ip', $ip)->orderBy('id')->pluck('categorias')->all();
    try {
        expect($filas)->toBe(['{esenciales,analitica}', '{esenciales}']);
    } finally {
        propietario()->table('cms.consentimientos')->where('tipo', 'cookies')->where('ip', $ip)->delete();
    }
});

it('rechaza categorías de cookies desconocidas', function () {
    $this->postJson('/api/v1/sitio/consentimientos/cookies', ['categorias' => ['espionaje']], comoSitio())->assertUnprocessable();
    $this->postJson('/api/v1/sitio/consentimientos/cookies', [], comoSitio())->assertUnprocessable();
});

it('la landing entrega el ID de GTM solo si está activo y no es el de ejemplo', function () {
    $antes = propietario()->table('cms.tracking')->where('nombre', 'gtm')->first(['valor', 'activo']);
    try {
        propietario()->table('cms.tracking')->where('nombre', 'gtm')->update(['valor' => 'GTM-XXXXXXX', 'activo' => true]);
        expect($this->getJson('/api/v1/sitio/landing')->json('tracking'))->toBe(['gtm' => null]);

        propietario()->table('cms.tracking')->where('nombre', 'gtm')->update(['valor' => 'GTM-AB12CD3', 'activo' => false]);
        expect($this->getJson('/api/v1/sitio/landing')->json('tracking.gtm'))->toBeNull();

        propietario()->table('cms.tracking')->where('nombre', 'gtm')->update(['activo' => true]);
        expect($this->getJson('/api/v1/sitio/landing')->json('tracking.gtm'))->toBe('GTM-AB12CD3');
    } finally {
        propietario()->table('cms.tracking')->where('nombre', 'gtm')->update(['valor' => $antes->valor, 'activo' => $antes->activo]);
    }
});
