<?php

use App\Models\User;
use App\Modulos\Compartido\Correo\EnviarCorreo;
use App\Modulos\SEG\Services\AlmacenArchivos;
use App\Modulos\SIT\Correo\AvisoReclamo;
use App\Modulos\SIT\Correo\ConfirmacionReclamo;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| Sprint 7 · Libro de Reclamaciones (EDUN-294), seguimiento (EDUN-298) y bandeja de
| solicitudes del Panel CMS (EDUN-278). Leen y limpian con la conexión dueña (sin RLS).
*/

function reclamo(array $cambios = []): array
{
    return [
        'tipo' => 'reclamo',
        'nombre' => 'Lucía Ramos Prueba',
        'tipo_doc' => 'DNI',
        'numero_doc' => '45678912',
        'email' => 'lucia.reclamo@ejemplo.pe',
        'telefono' => '987654321',
        'direccion' => 'Av. Ejemplo 123, Lima',
        'producto' => 'Plan Crecimiento · módulo de pagos',
        'monto' => '150.00',
        'descripcion' => 'La plataforma no generó las boletas de pensiones del mes y no hubo respuesta de soporte.',
        'pedido' => 'Que se regularice la emisión y se descuente el mes.',
        'declaracion' => true,
        'consentimiento' => true,
        ...$cambios,
    ];
}

beforeEach(function () {
    Queue::fake();
    Storage::fake(AlmacenArchivos::DISCO);
});

afterEach(function () {
    dueno()->table('cms.reclamaciones')->where('email', 'lucia.reclamo@ejemplo.pe')->delete();
    dueno()->table('cms.leads')->where('email', 'like', '%@bandeja.prueba')->delete();
});

// --- EDUN-294 / SIT-010 ---------------------------------------------------------------------

it('registra el reclamo, muestra el número REC-AAAA-NNNN y envía la confirmación con el plazo', function () {
    dueno()->table('cms.config')->updateOrInsert(['clave' => 'email_reclamaciones'], ['valor' => json_encode('soporte@ejemplo.pe')]);
    try {
        $r = $this->post('/api/v1/sitio/reclamaciones', reclamo([
            'adjuntos' => [UploadedFile::fake()->create('boleta.pdf', 200, 'application/pdf')],
        ]), ['Accept' => 'application/json', ...visitante('203.0.113.9')])->assertCreated();
    } finally {
        dueno()->table('cms.config')->where('clave', 'email_reclamaciones')->delete();
    }

    $anio = CarbonImmutable::now('America/Lima')->year;
    expect($r->json('expediente'))->toMatch("/^REC-{$anio}-\\d{4,}$/")
        ->and($r->json('plazo_dias_habiles'))->toBe(15);

    $fila = dueno()->table('cms.reclamaciones')->where('numero_expediente', $r->json('expediente'))->first();
    expect($fila->estado)->toBe('recibido')->and($fila->ip)->toBe('203.0.113.9')->and($fila->vence_en)->toBe($r->json('vence_en'));

    // El adjunto va al almacenamiento privado, nunca a la Biblioteca de medios.
    $adjuntos = json_decode($fila->adjuntos, true);
    expect($adjuntos)->toHaveCount(1)->and($adjuntos[0]['nombre'])->toBe('boleta.pdf');
    Storage::disk(AlmacenArchivos::DISCO)->assertExists($adjuntos[0]['ruta']);

    // Confirmación al reclamante (con el plazo) y aviso a soporte, por la cola de correo.
    $correos = collect(Queue::pushed(EnviarCorreo::class))->mapWithKeys(fn ($j) => [$j->destinatario => $j->correo]);
    expect($correos['lucia.reclamo@ejemplo.pe'])->toBeInstanceOf(ConfirmacionReclamo::class)
        ->and($correos['soporte@ejemplo.pe'])->toBeInstanceOf(AvisoReclamo::class);
    $html = $correos['lucia.reclamo@ejemplo.pe']->content()->htmlString;
    expect($html)->toContain($r->json('expediente'))->toContain('15 días hábiles');
});

it('pide al menos 50 caracteres en la descripción (API y base)', function () {
    $this->postJson('/api/v1/sitio/reclamaciones', reclamo(['descripcion' => str_repeat('a', 30)]), visitante())
        ->assertUnprocessable()->assertJsonPath('errors.descripcion.0', 'Describe los hechos con al menos 50 caracteres.');

    expect(fn () => dueno()->table('cms.reclamaciones')->insert([
        'numero_expediente' => 'REC-2026-9999', 'tipo' => 'queja', 'nombre_reclamante' => 'X', 'tipo_doc' => 'DNI', 'numero_doc' => '12345678',
        'email' => 'lucia.reclamo@ejemplo.pe', 'producto_descripcion' => 'x', 'descripcion' => str_repeat('a', 30), 'pedido' => 'x', 'vence_en' => '2026-12-31',
    ]))->toThrow(QueryException::class);
});

it('el vencimiento es 15 días hábiles después, sin fines de semana ni feriados', function () {
    $f = fn (string $desde) => DB::selectOne('select cms.sumar_dias_habiles(?::date, 15) as d', [$desde])->d;
    expect($f('2026-11-09'))->toBe('2026-11-30')   // lunes: 3 fines de semana
        ->and($f('2026-12-01'))->toBe('2026-12-24') // salta el 8 y 9 de diciembre
        ->and($f('2026-07-20'))->toBe('2026-08-14'); // salta 23, 28, 29 de julio y 6 de agosto

    $hoy = CarbonImmutable::now('America/Lima')->toDateString();
    $r = $this->postJson('/api/v1/sitio/reclamaciones', reclamo(), visitante())->assertCreated();
    expect($r->json('vence_en'))->toBe($f($hoy));
});

it('valida el documento, el apoderado de un menor, los adjuntos y la declaración', function () {
    $this->postJson('/api/v1/sitio/reclamaciones', reclamo(['numero_doc' => '1234']), visitante())->assertJsonPath('errors.numero_doc.0', 'El DNI tiene 8 dígitos.');
    $this->postJson('/api/v1/sitio/reclamaciones', reclamo(['menor_edad' => true]), visitante())->assertJsonValidationErrors(['apoderado_nombre', 'apoderado_doc']);
    $this->postJson('/api/v1/sitio/reclamaciones', reclamo(['declaracion' => false, 'consentimiento' => false]), visitante())->assertJsonValidationErrors(['declaracion', 'consentimiento']);
    $this->post('/api/v1/sitio/reclamaciones', reclamo(['adjuntos' => [UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')]]), ['Accept' => 'application/json', ...visitante()])
        ->assertJsonPath('errors', fn ($e) => isset($e['adjuntos.0']));
    $this->post('/api/v1/sitio/reclamaciones', reclamo(['adjuntos' => [UploadedFile::fake()->create('grande.pdf', 6000, 'application/pdf')]]), ['Accept' => 'application/json', ...visitante()])
        ->assertJsonValidationErrors(['adjuntos.0' => 'Cada archivo puede pesar hasta 5 MB.']);
    $this->postJson('/api/v1/sitio/reclamaciones', reclamo(['sitio_web' => 'spam']), visitante())->assertUnprocessable();

    expect(dueno()->table('cms.reclamaciones')->where('email', 'lucia.reclamo@ejemplo.pe')->count())->toBe(0);
    $this->postJson('/api/v1/sitio/reclamaciones', reclamo(['menor_edad' => true, 'apoderado_nombre' => 'Mario Ramos', 'apoderado_doc' => '41234567']), visitante())->assertCreated();
});

it('con reCAPTCHA activo rechaza un envío sin token válido', function () {
    config()->set('services.recaptcha.secreto', 'secreto-recaptcha');
    $puntaje = 0.1;
    Http::fake(['www.google.com/*' => function () use (&$puntaje) {
        return Http::response(['success' => true, 'score' => $puntaje, 'action' => 'reclamo']);
    }]);
    $this->postJson('/api/v1/sitio/reclamaciones', reclamo(), visitante())->assertJsonValidationErrors('recaptcha');
    $this->postJson('/api/v1/sitio/reclamaciones', reclamo(['recaptcha' => 'token']), visitante())->assertJsonValidationErrors('recaptcha');

    $puntaje = 0.9;
    $this->postJson('/api/v1/sitio/reclamaciones', reclamo(['recaptcha' => 'token']), visitante())->assertCreated();
});

// --- EDUN-298 / SIT-011 ---------------------------------------------------------------------

it('con el expediente y su documento muestra el estado; con otro documento, un mensaje genérico', function () {
    $exp = $this->postJson('/api/v1/sitio/reclamaciones', reclamo(), visitante())->json('expediente');
    dueno()->table('cms.reclamaciones')->where('numero_expediente', $exp)->update(['estado' => 'en_revision']);

    $ok = $this->getJson('/api/v1/sitio/reclamaciones/seguimiento?'.http_build_query(['expediente' => strtolower($exp), 'documento' => '45678912']), visitante())->assertOk();
    expect(array_keys($ok->json()))->toBe(['expediente', 'tipo', 'estado', 'actualizado_en', 'vence_en'])
        ->and($ok->json('estado'))->toBe('en_revision');
    // Nunca datos personales.
    expect(json_encode($ok->json()))->not->toContain('Lucía')->not->toContain('lucia.reclamo')->not->toContain('45678912');

    $this->getJson('/api/v1/sitio/reclamaciones/seguimiento?'.http_build_query(['expediente' => $exp, 'documento' => '11111111']), visitante())
        ->assertNotFound()->assertExactJson(['message' => 'No encontramos un reclamo con esos datos.']);
    $this->getJson('/api/v1/sitio/reclamaciones/seguimiento?'.http_build_query(['expediente' => 'REC-2026-0000', 'documento' => '45678912']), visitante())
        ->assertNotFound()->assertExactJson(['message' => 'No encontramos un reclamo con esos datos.']);
});

it('tras 10 consultas fallidas seguidas desde una IP, bloquea la siguiente', function () {
    $ip = '192.0.2.77';
    RateLimiter::clear('seguimiento-reclamo:'.$ip);
    $exp = $this->postJson('/api/v1/sitio/reclamaciones', reclamo(), visitante())->json('expediente');
    $consulta = fn (string $doc) => $this->getJson('/api/v1/sitio/reclamaciones/seguimiento?'.http_build_query(['expediente' => $exp, 'documento' => $doc]), visitante($ip));

    for ($i = 0; $i < 10; $i++) {
        $consulta('99999999')->assertNotFound();
    }
    // Ni siquiera con el documento correcto: la IP queda bloqueada temporalmente.
    $consulta('45678912')->assertStatus(429);
    // Otra IP no está bloqueada.
    $this->getJson('/api/v1/sitio/reclamaciones/seguimiento?'.http_build_query(['expediente' => $exp, 'documento' => '45678912']), visitante('192.0.2.78'))->assertOk();
    RateLimiter::clear('seguimiento-reclamo:'.$ip);
});

it('el público no puede leer reclamaciones', function () {
    $this->postJson('/api/v1/sitio/reclamaciones', reclamo(), visitante())->assertCreated();
    $n = DB::transaction(function () {
        DB::select("select set_config('app.rol_cms', 'publico', true)");

        return DB::table('cms.reclamaciones')->count();
    });
    expect($n)->toBe(0);
});

// --- EDUN-278 / SIT-026 ---------------------------------------------------------------------

it('con 42 leads del mes el indicador muestra 42 y la tabla 10 por página', function () {
    dueno()->table('cms.leads')->delete();
    sembrarLeads(42);
    Sanctum::actingAs(superadmin());

    $r = $this->getJson('/api/v1/cms/leads')->assertOk();
    expect($r->json('indicadores.total_mes'))->toBe(42)
        ->and($r->json('data'))->toHaveCount(10)
        ->and($r->json('total'))->toBe(42)
        ->and($r->json('paginas'))->toBe(5)
        ->and($r->json('contadores.todos'))->toBe(42)
        ->and($r->json('contadores.nuevo'))->toBe(42);
    expect($this->getJson('/api/v1/cms/leads?pagina=5')->json('data'))->toHaveCount(2);
});

it('el filtro «Plan Integral» deja solo leads de ese plan', function () {
    dueno()->table('cms.leads')->delete();
    sembrarLeads(12);
    Sanctum::actingAs(superadmin());

    $r = $this->getJson('/api/v1/cms/leads?plan=integral')->assertOk();
    expect($r->json('total'))->toBe(4)
        ->and(collect($r->json('data'))->pluck('plan_interes')->unique()->all())->toBe(['integral']);
    expect($this->getJson('/api/v1/cms/leads?ciudad=Lima&plan=integral')->json('total'))->toBe(2);
    expect($this->getJson('/api/v1/cms/leads?q=colegio%2012')->json('total'))->toBe(1);
});

it('al pasar un lead Nuevo a Contactado cambia de pestaña, marca el primer contacto y queda en la auditoría', function () {
    dueno()->table('cms.leads')->delete();
    sembrarLeads(3);
    $usuario = superadmin();
    Sanctum::actingAs($usuario);
    $id = dueno()->table('cms.leads')->orderBy('id')->value('id');

    $this->patchJson("/api/v1/cms/leads/{$id}", ['estado' => 'contactado'])->assertOk()->assertJsonPath('data.estado', 'contactado');

    $r = $this->getJson('/api/v1/cms/leads');
    expect($r->json('contadores.nuevo'))->toBe(2)->and($r->json('contadores.contactado'))->toBe(1)
        ->and($r->json('indicadores.respuesta_horas'))->not->toBeNull();
    expect($this->getJson('/api/v1/cms/leads?estado=contactado')->json('data.0.id'))->toBe($id);
    expect(dueno()->table('cms.leads')->where('id', $id)->value('primer_contacto_en'))->not->toBeNull();

    $aud = dueno()->table('cms.auditoria')->where('entidad', 'leads')->where('entidad_id', (string) $id)->latest('id')->first();
    expect($aud->usuario_id)->toBe($usuario->id)->and($aud->campo)->toBe('estado')
        ->and(json_decode($aud->valor_antes))->toBe('nuevo')->and(json_decode($aud->valor_despues))->toBe('contactado');

    $this->patchJson("/api/v1/cms/leads/{$id}", ['estado' => 'archivado'])->assertUnprocessable();
});

it('solo el superadmin ve la bandeja', function () {
    $this->getJson('/api/v1/cms/leads')->assertUnauthorized();
    $director = User::where('email', 'like', '%@%')->whereNotIn('id', dueno()->table('roles_plataforma')->pluck('user_id'))->firstOrFail();
    Sanctum::actingAs($director);
    $this->getJson('/api/v1/cms/leads')->assertForbidden();
    $this->patchJson('/api/v1/cms/leads/1', ['estado' => 'ganado'])->assertForbidden();
});
