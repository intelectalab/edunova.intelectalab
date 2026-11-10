<?php

use App\Models\User;
use App\Modulos\ADM\Correo\AvisoEntrevista;
use App\Modulos\Compartido\Correo\CorreoTransaccional;
use App\Modulos\PLA\Correo\AlertaSla;
use App\Modulos\PLA\Correo\AvisoSuspension;
use App\Modulos\PLA\Services\HorarioHabil;
use App\Modulos\PLA\Services\MesaDeAyuda;
use App\Modulos\PLA\Services\Suspensiones;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/*
| Sprint 15 · Soporte y operación del SaaS (fin de la fase 1):
| EDUN-29 tickets · EDUN-31 SLA · EDUN-32 cumplimiento · EDUN-33 soporte desde el colegio · EDUN-30 parámetros
| EDUN-28 suspensión · EDUN-34 exportación · EDUN-212 lista de implementación · EDUN-53 admisión
*/

afterEach(fn () => Carbon::setTestNow());

/** Colegio nuevo dado de alta por la consola (para no tocar el estado del demo). */
function colegioNuevo(): int
{
    Mail::fake();
    actuarComo(superadmin());
    $n = random_int(1000000, 8999999);

    return test()->postJson('/api/v1/plataforma/instituciones', [
        'nombre' => "IEP Operación {$n}", 'ugel' => 'UGEL Cusco', 'slug' => "op-{$n}", 'plan' => 'P150', 'aula_opcion' => 'medida',
        'servicios' => [['nivel' => 'primaria', 'codigo_modular' => (string) $n]],
        'promotor' => ['nombres' => 'Pedro', 'apellidos' => 'Promotor', 'email' => "pedro{$n}@op.prueba"],
    ])->assertCreated()->json('id');
}

// ─────────────────────────────── EDUN-31 · Horario hábil

it('suma y cuenta horas hábiles saltando noches, fines de semana y feriados', function () {
    $h = new HorarioHabil;
    // Viernes 12/03/2027 17:00 (Lima) + 2 h hábiles = lunes 15/03 09:00.
    $vie = Carbon::parse('2027-03-12 17:00', 'America/Lima');
    expect($h->sumar($vie, 120)->timezone('America/Lima')->format('Y-m-d H:i'))->toBe('2027-03-15 09:00')
        ->and($h->minutosEntre($vie, Carbon::parse('2027-03-15 09:00', 'America/Lima')))->toBe(120)
        // Antes de la jornada empieza a contar a las 08:00.
        ->and($h->sumar(Carbon::parse('2027-03-15 06:00', 'America/Lima'), 60)->timezone('America/Lima')->format('H:i'))->toBe('09:00')
        // Feriados nacionales (Jueves y Viernes Santo 25 y 26/03/2027): no cuentan.
        ->and($h->sumar(Carbon::parse('2027-03-24 17:00', 'America/Lima'), 120)->timezone('America/Lima')->format('Y-m-d H:i'))->toBe('2027-03-29 09:00');
});

// ─────────────────────────────── EDUN-33 / EDUN-29 · Tickets

it('el director pide soporte con adjuntos y ve el plazo; las notas internas no le llegan', function () {
    Storage::fake('objetos');
    Carbon::setTestNow(Carbon::parse('2027-03-15 09:00', 'America/Lima'));
    $r = enDemo(directorDemo(), 'POST', '/soporte/tickets', [
        'asunto' => 'No puedo emitir boletas', 'descripcion' => 'Sale un error al emitir.', 'categoria' => 'error', 'prioridad' => 'media',
        'adjuntos' => [UploadedFile::fake()->create('error.png', 30, 'image/png')],
    ])->assertCreated();
    $id = $r->json('id');
    expect($r->json('numero'))->toStartWith('T-')
        ->and(Carbon::parse($r->json('vence_primera_respuesta_at'))->timezone('America/Lima')->format('Y-m-d H:i'))->toBe('2027-03-15 17:00') // 8 h hábiles
        ->and($r->json('adjuntos'))->toHaveCount(1)
        ->and($r->json('semaforo.color'))->toBe('verde');

    actuarComo(superadmin());
    test()->postJson("/api/v1/plataforma/tickets/{$id}/mensajes", ['mensaje' => 'Revisar el certificado.', 'interno' => true])->assertOk();
    test()->postJson("/api/v1/plataforma/tickets/{$id}/mensajes", ['mensaje' => 'Estamos revisándolo.'])->assertOk()->assertJsonPath('estado', 'en_curso');

    $visto = enDemo(directorDemo(), 'GET', "/soporte/tickets/{$id}")->assertOk();
    expect(collect($visto->json('mensajes'))->pluck('mensaje')->all())->toBe(['Estamos revisándolo.'])
        ->and($visto->json('primera_respuesta_at'))->not->toBeNull();
    actuarComo(superadmin());
    expect(test()->getJson("/api/v1/plataforma/tickets/{$id}")->json('mensajes'))->toHaveCount(2);

    // Otros roles del colegio no ven el soporte; otro colegio no ve el ticket.
    enDemo(personaDemo('secretaria'), 'GET', '/soporte/tickets')->assertForbidden();
    $otro = colegioNuevo();
    $promotor = User::query()->where('email', 'like', 'pedro%@op.prueba')->latest('id')->firstOrFail();
    enDemo($promotor, 'GET', "/soporte/tickets/{$id}", [], $otro)->assertNotFound();
});

it('mientras se espera al colegio el plazo se detiene, y el colegio califica al cerrar', function () {
    Carbon::setTestNow(Carbon::parse('2027-03-15 09:00', 'America/Lima'));
    $id = enDemo(directorDemo(), 'POST', '/soporte/tickets', ['asunto' => 'Duda', 'descripcion' => 'Consulta', 'categoria' => 'consulta', 'prioridad' => 'alta'])->json('id');
    actuarComo(superadmin());
    $antes = test()->patchJson("/api/v1/plataforma/tickets/{$id}", ['estado' => 'esperando'])->assertOk()->json('vence_resolucion_at');

    // El colegio contesta 2 horas hábiles después: el vencimiento se corre 2 horas.
    Carbon::setTestNow(Carbon::parse('2027-03-15 11:00', 'America/Lima'));
    enDemo(directorDemo(), 'POST', "/soporte/tickets/{$id}/mensajes", ['mensaje' => 'Les mando el dato.'])->assertOk()->assertJsonPath('estado', 'en_curso');
    $t = dueno()->table('tickets_soporte')->find($id);
    expect($t->pausado_minutos)->toBe(120)->and($t->pausado_desde)->toBeNull()
        ->and((new HorarioHabil)->minutosEntre(Carbon::parse($antes), Carbon::parse($t->vence_resolucion_at)))->toBe(120);

    enDemo(directorDemo(), 'POST', "/soporte/tickets/{$id}/calificacion", ['calificacion' => 5])->assertUnprocessable();
    actuarComo(superadmin());
    test()->patchJson("/api/v1/plataforma/tickets/{$id}", ['estado' => 'resuelto'])->assertOk();
    enDemo(directorDemo(), 'POST', "/soporte/tickets/{$id}/calificacion", ['calificacion' => 4, 'comentario' => 'Rápido'])->assertOk()
        ->assertJsonPath('estado', 'cerrado')->assertJsonPath('calificacion', 4);
});

it('avisa al 75 % del plazo y escala al vencer', function () {
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2027-03-16 09:00', 'America/Lima'));
    $id = enDemo(directorDemo(), 'POST', '/soporte/tickets', ['asunto' => 'Caído', 'descripcion' => 'No carga', 'categoria' => 'incidente', 'prioridad' => 'critica'])->json('id');
    $mesa = MesaDeAyuda::plataforma();
    $correo = app(CorreoTransaccional::class);

    Carbon::setTestNow(Carbon::parse('2027-03-16 09:30', 'America/Lima'));
    $mesa->vigilar($correo);
    expect(dueno()->table('tickets_soporte')->find($id)->alerta_75_at)->toBeNull();

    Carbon::setTestNow(Carbon::parse('2027-03-16 09:50', 'America/Lima')); // 50 de 60 minutos
    $mesa->vigilar($correo);
    expect(dueno()->table('tickets_soporte')->find($id)->alerta_75_at)->not->toBeNull();
    Mail::assertSent(AlertaSla::class, fn ($m) => $m->tipo === 'alerta' && $m->hasTo(superadmin()->email));

    Carbon::setTestNow(Carbon::parse('2027-03-16 10:05', 'America/Lima'));
    $mesa->vigilar($correo);
    expect(dueno()->table('tickets_soporte')->find($id)->escalado_at)->not->toBeNull();
    Mail::assertSent(AlertaSla::class, fn ($m) => $m->tipo === 'escalado');
    actuarComo(superadmin());
    expect(collect(test()->getJson('/api/v1/plataforma/tickets?prioridad=critica')->json('data'))->firstWhere('id', $id)['semaforo']['color'])->toBe('rojo');
});

// ─────────────────────────────── EDUN-32 · Cumplimiento

it('muestra el cumplimiento del SLA por prioridad, colegio y mes con la calificación promedio', function () {
    actuarComo(superadmin());
    $r = test()->getJson('/api/v1/plataforma/sla/cumplimiento?desde=2027-01-01&hasta=2027-12-31')->assertOk();
    expect($r->json('total.tickets'))->toBeGreaterThan(0)
        ->and(collect($r->json('por_prioridad'))->pluck('prioridad')->all())->toBe(['critica', 'alta', 'media', 'baja'])
        ->and($r->json('por_colegio.0'))->toHaveKeys(['colegio', 'primera_respuesta_a_tiempo', 'resolucion_a_tiempo', 'horas_primera_respuesta', 'horas_resolucion', 'calificacion'])
        ->and($r->json('por_mes.0.mes'))->toBe('2027-03');
    actuarComo(superadmin(), dosPasos: false);
    test()->getJson('/api/v1/plataforma/sla/cumplimiento')->assertForbidden();
});

// ─────────────────────────────── EDUN-30 · Parámetros legales

it('mantiene los parámetros legales con fuente y vigencia; los no confirmados se cuentan como alerta', function () {
    actuarComo(superadmin());
    $antes = test()->getJson('/api/v1/plataforma/parametros-legales')->assertOk();
    expect($antes->json('sin_confirmar'))->toBeGreaterThan(0);
    test()->postJson('/api/v1/plataforma/parametros-legales', ['codigo' => 'uit.valor', 'valor' => '5500', 'vigente_desde' => '2027-01-01'])->assertJsonValidationErrors('fuente');
    $r = test()->postJson('/api/v1/plataforma/parametros-legales', ['codigo' => 'uit.valor', 'valor' => '5500', 'vigente_desde' => '2027-01-01', 'fuente' => 'D.S. de la UIT 2027', 'confirmado' => true])->assertOk();
    $uit = collect($r->json('data'))->firstWhere('codigo', 'uit.valor');
    expect($uit)->toMatchArray(['valor' => '5500', 'confirmado' => true, 'fuente' => 'D.S. de la UIT 2027']);
    $pendiente = collect($r->json('data'))->firstWhere('confirmado', false);
    $despues = test()->patchJson("/api/v1/plataforma/parametros-legales/{$pendiente['id']}", ['confirmado' => true])->assertOk();
    expect($despues->json('sin_confirmar'))->toBe($antes->json('sin_confirmar') - ($pendiente['vigente'] ? 1 : 0));
});

// ─────────────────────────────── EDUN-28 · Suspensión por falta de pago

it('avisa al promotor 7 y 3 días antes, suspende el día fijado conservando los datos y se reactiva', function () {
    $id = colegioNuevo();
    $promotor = User::query()->where('email', 'like', 'pedro%@op.prueba')->latest('id')->firstOrFail();
    Mail::fake();
    actuarComo(superadmin());
    $fecha = now()->timezone('America/Lima')->addDays(10)->toDateString();
    test()->postJson("/api/v1/plataforma/instituciones/{$id}/suspension", ['fecha' => now()->toDateString(), 'motivo' => 'Impago'])->assertUnprocessable();
    test()->postJson("/api/v1/plataforma/instituciones/{$id}/suspension", ['fecha' => $fecha, 'motivo' => 'Facturas de agosto impagas'])->assertOk();

    $s = app(Suspensiones::class);
    expect($s->procesar(now()->addDays(2))['avisos'])->toBe(0);     // faltan 8 días
    expect($s->procesar(now()->addDays(3))['avisos'])->toBe(1);     // 7 días
    expect($s->procesar(now()->addDays(4))['avisos'])->toBe(0);     // ya avisado
    expect($s->procesar(now()->addDays(7))['avisos'])->toBe(1);     // 3 días
    Mail::assertSent(AvisoSuspension::class, 2);
    Mail::assertSent(AvisoSuspension::class, fn ($m) => $m->hasTo($promotor->email) && $m->dias === 7);

    $datosAntes = dueno()->table('servicios_educativos')->where('institucion_id', $id)->count();
    expect($s->procesar(now()->addDays(10))['suspendidos'])->toBe(1);
    expect(dueno()->table('instituciones')->where('id', $id)->value('estado'))->toBe('suspendido')
        ->and(dueno()->table('servicios_educativos')->where('institucion_id', $id)->count())->toBe($datosAntes);
    enDemo($promotor, 'GET', '/soporte/tickets', [], $id)->assertForbidden()->assertJsonPath('codigo', 'colegio_no_disponible');

    actuarComo(superadmin());
    test()->putJson("/api/v1/plataforma/instituciones/{$id}/estado", ['estado' => 'activo'])->assertOk()->assertJsonPath('estado', 'implementacion');
    enDemo($promotor, 'GET', '/soporte/tickets', [], $id)->assertOk();
});

// ─────────────────────────────── EDUN-34 · Exportación

it('exporta todos los datos de un colegio en un ZIP con JSON, Excel y archivos', function () {
    Storage::fake('objetos');
    config()->set('filesystems.disks.objetos.temporary_url', 'http://localhost:9000');
    $id = colegioNuevo();
    $promotor = User::query()->where('email', 'like', 'pedro%@op.prueba')->latest('id')->firstOrFail();
    enDemo($promotor, 'POST', '/archivos', ['archivo' => UploadedFile::fake()->createWithContent('acta.pdf', '%PDF-1.4 acta')], $id)->assertCreated();

    actuarComo(superadmin());
    $r = test()->postJson("/api/v1/plataforma/instituciones/{$id}/exportaciones", ['motivo' => 'portabilidad'])->assertStatus(202);
    $lista = test()->getJson("/api/v1/plataforma/instituciones/{$id}/exportaciones")->assertOk()->json('data.0');
    expect($lista['estado'])->toBe('lista')->and($lista['resumen']['archivos'])->toBe(1)->and($lista['resumen']['modulos']['SEG']['membresias'])->toBe(1);
    expect(test()->getJson("/api/v1/plataforma/instituciones/{$id}/exportaciones/{$r->json('id')}/enlace")->json('url'))->toContain('X-Amz-Expires=300');

    $ruta = dueno()->table('exportaciones')->where('id', $r->json('id'))->value('ruta');
    $tmp = tempnam(sys_get_temp_dir(), 'zip');
    file_put_contents($tmp, Storage::disk('objetos')->get($ruta));
    $zip = new ZipArchive;
    $zip->open($tmp);
    $nombres = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i));
    expect($nombres)->toContain('colegio.json', 'SEG/SEG.json', 'SEG/SEG.xlsx', 'ADM/ADM.json', 'PLA/PLA.json', 'LEEME.txt')
        ->and($nombres->first(fn ($n) => str_starts_with($n, 'archivos/')))->toEndWith('acta.pdf');
    $seg = json_decode($zip->getFromName('SEG/SEG.json'), true);
    expect($seg['usuarios'][0])->not->toHaveKey('password')->and($seg)->not->toHaveKey('personal_access_tokens');
    $zip->close();
    @unlink($tmp);
});

// ─────────────────────────────── EDUN-212 · Lista de implementación

it('la lista de implementación marca sola lo verificable y no deja activar con pasos clave pendientes', function () {
    $id = colegioNuevo();
    actuarComo(superadmin());
    $lista = collect(test()->getJson("/api/v1/plataforma/instituciones/{$id}/checklist")->assertOk()->json('data'))->keyBy('clave');
    expect($lista['alta']['hecho'])->toBeTrue()->and($lista['modulos']['hecho'])->toBeTrue()->and($lista['promotor']['hecho'])->toBeFalse()
        ->and($lista['migracion']['nota'])->toContain('D-07')->and($lista['pasarela']['quien'])->toContain('Tesorería')
        ->and($lista->every(fn ($p) => $p['quien'] && $p['verificacion']))->toBeTrue();
    test()->putJson("/api/v1/plataforma/instituciones/{$id}/checklist/pasarela", ['hecho' => true])->assertOk();
    expect(collect(test()->getJson("/api/v1/plataforma/instituciones/{$id}/checklist")->json('data'))->firstWhere('clave', 'pasarela')['hecho'])->toBeTrue();
    test()->putJson("/api/v1/plataforma/instituciones/{$id}/checklist/alta", ['hecho' => true])->assertUnprocessable();
    test()->putJson("/api/v1/plataforma/instituciones/{$id}/estado", ['estado' => 'activo'])->assertUnprocessable()->assertJsonValidationErrors('estado');
    actuarComo(personaDemo('director'));
    test()->getJson("/api/v1/plataforma/instituciones/{$id}/checklist")->assertForbidden();
});

// ─────────────────────────────── EDUN-53 · Admisión

it('gestiona la admisión por etapas con vacantes, entrevistas con aviso y conversión en alumno', function () {
    Mail::fake();
    $secretaria = personaDemo('secretaria');
    $anio = dueno()->table('anios_escolares')->where('institucion_id', demoId())->where('anio', 2027)->value('id');
    $grado = dueno()->table('grados')->where('institucion_id', demoId())->where('nivel', 'secundaria')->where('numero', 3)->value('id');
    $proceso = enDemo($secretaria, 'POST', '/admision/procesos', ['anio_escolar_id' => $anio, 'nombre' => 'Admisión 2027 '.uniqid(), 'fecha_inicio' => '2026-09-01', 'fecha_fin' => '2027-02-28'])->assertCreated()->json('id');
    $ocupadas = (int) dueno()->table('postulantes as p')->join('admision_procesos as ap', 'ap.id', '=', 'p.admision_proceso_id')
        ->where('ap.anio_escolar_id', $anio)->where('p.grado_id', $grado)->whereIn('p.etapa', ['vacante', 'matriculado'])->count();
    enDemo($secretaria, 'PUT', '/admision/vacantes', ['anio_escolar_id' => $anio, 'grado_id' => $grado, 'cantidad' => $ocupadas + 1])->assertOk();

    $nuevo = fn ($nombre) => enDemo($secretaria, 'POST', '/admision/postulantes', [
        'admision_proceso_id' => $proceso, 'grado_id' => $grado, 'apellidos' => 'Quispe Ramos', 'nombres' => $nombre, 'numero_documento' => (string) random_int(70000000, 79999999),
        'contacto_nombre' => 'Rosa Ramos', 'contacto_celular' => '987123456', 'contacto_email' => 'rosa.'.uniqid().'@familia.prueba',
    ])->assertCreated()->json('id');
    $ana = $nuevo('Ana');
    $beto = $nuevo('Beto');

    $cita = now()->timezone('America/Lima')->addDays(3)->setTime(10, 30)->format('Y-m-d\TH:i');
    $e = enDemo($secretaria, 'POST', "/admision/postulantes/{$ana}/entrevistas", ['fecha' => $cita, 'lugar' => 'Dirección'])->assertOk();
    expect($e->json('correo'))->toBeTrue()->and($e->json('whatsapp_url'))->toStartWith('https://wa.me/51987123456');
    Mail::assertSent(AvisoEntrevista::class, fn ($m) => str_contains($m->cuando, '10:30') && $m->lugar === 'Dirección');

    enDemo($secretaria, 'PATCH', "/admision/postulantes/{$ana}/etapa", ['etapa' => 'vacante'])->assertOk();
    enDemo($secretaria, 'PATCH', "/admision/postulantes/{$beto}/etapa", ['etapa' => 'vacante'])->assertUnprocessable()->assertJsonPath('errors.etapa.0', 'Ya no quedan vacantes en ese grado.');
    enDemo($secretaria, 'PATCH', "/admision/postulantes/{$beto}/etapa", ['etapa' => 'matriculado'])->assertUnprocessable();

    $tablero = enDemo($secretaria, 'GET', "/admision?anio_escolar_id={$anio}")->assertOk();
    $filaAna = collect($tablero->json('postulantes'))->firstWhere('id', $ana);
    expect($filaAna['etapa'])->toBe('vacante')->and($filaAna['proxima_entrevista'])->not->toBeNull()
        ->and(collect($tablero->json('agenda'))->pluck('postulante_id'))->toContain($ana);
    expect(collect(enDemo($secretaria, 'GET', "/admision/postulantes/{$ana}/historia")->json('data'))->pluck('tipo')->all())->toContain('registro', 'entrevista', 'cambio_etapa');

    $alumno = enDemo($secretaria, 'POST', "/admision/postulantes/{$ana}/convertir", ['numero_documento' => (string) random_int(40000000, 49999999), 'apellidos' => 'Ramos Díaz', 'nombres' => 'Rosa', 'parentesco' => 'madre'])
        ->assertOk()->json('alumno_id');
    expect(dueno()->table('alumnos')->where('id', $alumno)->value('apellido_paterno'))->toBe('Quispe');

    // Al matricularlo en ventanilla, el postulante queda «matriculado».
    if (! dueno()->table('condiciones_economicas')->where('anio_escolar_id', $anio)->whereNotNull('publicado_at')->exists()) {
        enDemo(directorDemo(), 'POST', '/condiciones-economicas', ['anio_escolar_id' => $anio, 'contenido' => str_repeat('Pensión mensual según cronograma del año escolar. ', 3)])->assertCreated();
    }
    $apoderado = dueno()->table('alumno_apoderado')->where('alumno_id', $alumno)->value('apoderado_id');
    $seccion = dueno()->table('secciones')->where('anio_escolar_id', $anio)->where('grado_id', $grado)->whereNull('vacantes')->value('id');
    enDemo($secretaria, 'POST', '/matriculas', ['alumno_id' => $alumno, 'seccion_id' => $seccion, 'apoderado_id' => $apoderado, 'acepta_condiciones' => true])->assertCreated();
    expect(dueno()->table('postulantes')->where('id', $ana)->value('etapa'))->toBe('matriculado');
    enDemo(personaDemo('docente'), 'GET', "/admision?anio_escolar_id={$anio}")->assertForbidden();
});
