<?php

use App\Models\User;
use App\Modulos\SEG\Services\AlmacenArchivos;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
| Revisión de autorización de las fases 3 y 4: lo que un alumno, un docente o secretaría no deben poder hacer.
*/

beforeEach(function () {
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok']]])]);
    Storage::fake(AlmacenArchivos::DISCO);
    $db = dueno();
    $sus = $db->table('suscripciones')->where('institucion_id', demoId())->value('id');
    foreach (['POR', 'SAL', 'TIE', 'DOC', 'AUL', 'BIB', 'MOV'] as $codigo) {
        $m = $db->table('modulos_catalogo')->where('codigo', $codigo)->value('id');
        if (! $db->table('suscripcion_modulos')->where('suscripcion_id', $sus)->where('modulo_id', $m)->exists()) {
            $db->table('suscripcion_modulos')->insert(['institucion_id' => demoId(), 'suscripcion_id' => $sus, 'modulo_id' => $m]);
        }
        $db->table('suscripcion_modulos')->where('suscripcion_id', $sus)->where('modulo_id', $m)->update(['activo' => true]);
    }
});

/** Alumno con cuenta propia (rol alumno), matriculado en 2027. */
function alumnoConCuentaRevision(): array
{
    $db = dueno();
    $inst = demoId();
    $anio = (int) $db->table('anios_escolares')->where('institucion_id', $inst)->where('anio', 2027)->value('id');
    $seccion = (int) $db->table('secciones')->where('anio_escolar_id', $anio)->orderBy('id')->value('id');
    $u = $db->table('users')->insertGetId(['nombres' => 'Alumno', 'apellidos' => 'Rev '.uniqid(), 'numero_documento' => (string) random_int(70000000, 79999999), 'email' => 'ar.'.uniqid().'@alumno.prueba', 'password' => Hash::make('x')]);
    $db->table('membresias')->insert(['institucion_id' => $inst, 'user_id' => $u, 'rol_id' => $db->table('roles')->where('codigo', 'alumno')->value('id')]);
    $alumno = $db->table('alumnos')->insertGetId(['institucion_id' => $inst, 'user_id' => $u, 'apellido_paterno' => 'Rev'.uniqid(), 'nombres' => 'Sam', 'numero_documento' => (string) random_int(60000000, 69999999)]);
    $db->table('matriculas')->insert(['institucion_id' => $inst, 'alumno_id' => $alumno, 'anio_escolar_id' => $anio, 'seccion_id' => $seccion, 'origen' => 'manual']);

    return ['alumno' => $alumno, 'usuario' => User::query()->findOrFail($u), 'seccion' => $seccion];
}

it('un alumno no registra quién lo recoge, no pide su salida, no cambia su ficha de salud ni compra con la pensión', function () {
    $a = alumnoConCuentaRevision();
    enDemo($a['usuario'], 'POST', '/familia/autorizados', ['alumno_id' => $a['alumno'], 'nombres' => 'Amigo', 'numero_documento' => '12345678', 'parentesco' => 'Tío'])->assertForbidden();
    enDemo($a['usuario'], 'POST', '/familia/salidas', ['alumno_id' => $a['alumno'], 'fecha' => now()->toDateString(), 'hora' => '10:00', 'motivo' => 'Me voy'])->assertForbidden();
    enDemo($a['usuario'], 'PUT', "/familia/salud/{$a['alumno']}", ['alergias' => ''])->assertForbidden();
    enDemo($a['usuario'], 'POST', '/tienda/familia/pedidos', ['alumno_id' => $a['alumno'], 'forma_pago' => 'con_pension', 'items' => [['producto_id' => 1, 'cantidad' => 1]]])->assertForbidden();
});

it('secretaría no abre, cambia ni quita por su id un documento del expediente del personal', function () {
    $personal = dueno()->table('personal')->insertGetId(['institucion_id' => demoId(), 'nombres' => 'Doc', 'apellidos' => 'Rev '.uniqid(), 'numero_documento' => (string) random_int(30000000, 39999999), 'cargo' => 'Docente']);
    actuarComo(personaDemo('director'), true, demoId());
    $doc = test()->post("/api/v1/expedientes/personal/{$personal}", ['categoria' => 'contrato', 'archivo' => UploadedFile::fake()->create('contrato.pdf', 20, 'application/pdf')], ['X-Institucion' => (string) demoId(), 'Accept' => 'application/json'])->assertCreated()->json('id');
    $sec = personaDemo('secretaria');
    enDemo($sec, 'GET', "/expedientes/documentos/{$doc}/archivo")->assertForbidden();
    enDemo($sec, 'PATCH', "/expedientes/documentos/{$doc}", ['verificar' => true])->assertForbidden();
    enDemo($sec, 'DELETE', "/expedientes/documentos/{$doc}")->assertForbidden();
});

it('un docente no sigue ni anula firmas de una autorización que no pidió', function () {
    $a = alumnoConCuentaRevision();
    $aut = enDemo(personaDemo('director'), 'POST', '/autorizaciones', ['titulo' => 'Paseo al parque', 'contenido' => 'Autorizo la salida al parque.', 'secciones' => [$a['seccion']], 'vence' => now()->addDays(3)->toDateString()])->assertCreated()->json('id');
    $docente = personaDemo('docente');
    enDemo($docente, 'GET', "/autorizaciones/{$aut}")->assertForbidden();
    enDemo($docente, 'POST', "/autorizaciones/{$aut}/recordatorio")->assertForbidden();
    enDemo($docente, 'DELETE', "/autorizaciones/{$aut}/firmas/{$a['alumno']}")->assertForbidden();
});

it('la ficha de un libro no dice a la familia quién tiene cada ejemplar y la ruta no lleva el DNI', function () {
    $bib = personaDemo('bibliotecario');
    actuarComo($bib, true, demoId());
    $libro = test()->post('/api/v1/biblioteca/libros', ['titulo' => 'Revisión', 'tipo' => 'libro', 'ejemplares' => 1], ['X-Institucion' => (string) demoId(), 'Accept' => 'application/json'])->json('id');
    $a = alumnoConCuentaRevision();
    $carne = 'EN'.strtoupper(bin2hex(random_bytes(5)));
    dueno()->table('alumnos')->where('id', $a['alumno'])->update(['codigo_carne' => $carne]);
    enDemo($bib, 'POST', '/biblioteca/prestamos', ['ejemplar' => "B{$libro}-001", 'lector' => $carne])->assertCreated();
    $e = enDemo($a['usuario'], 'GET', "/biblioteca/libros/{$libro}")->assertOk()->json('ejemplares_detalle.0');
    expect($e)->not->toHaveKey('lector_id')->and($e['lector'])->toBeNull();
});
