<?php

use App\Models\User;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\Autorizacion;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
| EDUN-39 / SEG-H05 · Control de acceso por permisos y propiedad.
| Escenario sobre el colegio de demostración (sección A con 8 alumnos), más una sección B
| con un alumno, un usuario por rol y un segundo colegio. Se crea una vez por corrida.
*/

function escenarioPermisos(): object
{
    static $e = null;
    if ($e) {
        return $e;
    }
    $db = DB::connection('propietario');
    $inst = (int) $db->table('instituciones')->where('slug', 'smp')->value('id');
    $secA = $db->table('secciones')->where('institucion_id', $inst)->where('nombre', 'A')->first();
    $alumnosA = $db->table('matriculas')->where('seccion_id', $secA->id)->orderBy('alumno_id')->pluck('alumno_id')->all();

    $usuario = function (string $nombre, ?string $rol, ?int $institucion) use ($db): User {
        $id = $db->table('users')->insertGetId([
            'nombres' => $nombre, 'apellidos' => 'Prueba', 'numero_documento' => (string) random_int(10000000, 99999999),
            'email' => strtolower($nombre).'.'.uniqid().'@pruebas.edunova.local',
        ]);
        if ($rol) {
            $db->table('membresias')->insert(['institucion_id' => $institucion, 'user_id' => $id, 'rol_id' => $db->table('roles')->where('codigo', $rol)->value('id')]);
        }

        return User::query()->findOrFail($id);
    };

    // Sección B del mismo año y grado, con un alumno propio.
    $secB = $db->table('secciones')->insertGetId(['institucion_id' => $inst, 'anio_escolar_id' => $secA->anio_escolar_id, 'grado_id' => $secA->grado_id, 'nombre' => 'B']);
    $alumnoB = $db->table('alumnos')->insertGetId(['institucion_id' => $inst, 'apellido_paterno' => 'Salas', 'nombres' => 'Lucía']);
    $db->table('matriculas')->insert(['institucion_id' => $inst, 'alumno_id' => $alumnoB, 'anio_escolar_id' => $secA->anio_escolar_id, 'seccion_id' => $secB]);

    // Docente: dicta solo en la sección B.
    $docente = $usuario('Docente', 'docente', $inst);
    $personalDoc = $db->table('personal')->insertGetId(['institucion_id' => $inst, 'user_id' => $docente->id, 'numero_documento' => '42000001', 'apellidos' => 'Prueba', 'nombres' => 'Docente', 'cargo' => 'Docente']);
    $db->table('cargas_docentes')->insert(['institucion_id' => $inst, 'seccion_id' => $secB, 'personal_id' => $personalDoc, 'area_curricular_id' => $db->table('areas_curriculares')->value('id')]);

    // Tutor: es el tutor de la sección A (personal ya existente).
    $tutor = $usuario('Tutor', 'tutor', $inst);
    $db->table('personal')->where('id', $secA->tutor_personal_id)->update(['user_id' => $tutor->id]);

    // Apoderado del primer alumno de A; alumno con cuenta: el segundo de A.
    $apoderado = $usuario('Apoderado', 'apoderado', $inst);
    $apo = $db->table('alumno_apoderado')->where('alumno_id', $alumnosA[0])->value('apoderado_id');
    $db->table('apoderados')->where('id', $apo)->update(['user_id' => $apoderado->id]);
    $alumno = $usuario('Alumno', 'alumno', $inst);
    $db->table('alumnos')->where('id', $alumnosA[1])->update(['user_id' => $alumno->id]);

    // Rol del colegio sin adm.alumnos.ver.
    $sinPermiso = $db->table('roles')->where('ambito', 'institucion')->whereNotIn('codigo', ['docente', 'tutor', 'apoderado', 'alumno'])
        ->whereNotExists(fn ($q) => $q->from('rol_permiso as rp')->join('permisos as p', 'p.id', '=', 'rp.permiso_id')
            ->whereColumn('rp.rol_id', 'roles.id')->where('p.codigo', 'adm.alumnos.ver'))
        ->value('codigo');

    // Segundo colegio con su director.
    $otra = $db->table('instituciones')->insertGetId(['slug' => 'otro-'.uniqid(), 'nombre' => 'Otro colegio', 'codigo_modular' => '0000001', 'ugel' => 'UGEL X', 'nivel_educativo' => 'multinivel']);

    return $e = (object) [
        'inst' => $inst, 'otra' => $otra, 'secA' => $secA->id, 'secB' => $secB, 'alumnosA' => $alumnosA, 'alumnoB' => $alumnoB,
        'secretaria' => $usuario('Secretaria', 'secretaria', $inst), 'docente' => $docente, 'tutor' => $tutor,
        'apoderado' => $apoderado, 'alumno' => $alumno, 'sinPermiso' => $usuario('SinPermiso', $sinPermiso, $inst),
        'rolSinPermiso' => $sinPermiso, 'ajeno' => $usuario('Ajeno', 'director', $otra), 'sinRol' => $usuario('SinRol', null, null),
    ];
}

function como(User $usuario, int $institucion, string $url)
{
    Sanctum::actingAs($usuario);

    return test()->getJson('/api/v1'.$url, ['X-Institucion' => (string) $institucion]);
}

function idsDe($respuesta): array
{
    return collect($respuesta->json('data'))->pluck('id')->sort()->values()->all();
}

it('sin sesión responde 401', function () {
    $this->getJson('/api/v1/alumnos', ['X-Institucion' => '1'])->assertUnauthorized();
});

it('sin colegio elegido responde 400 con mensaje claro', function () {
    Sanctum::actingAs(escenarioPermisos()->secretaria);
    $this->getJson('/api/v1/alumnos')->assertStatus(400)->assertJson(['message' => 'Elige un colegio para continuar.']);
});

it('quien no pertenece al colegio no entra', function () {
    $e = escenarioPermisos();
    como($e->ajeno, $e->inst, '/alumnos')->assertForbidden()->assertJson(['message' => 'No perteneces a este colegio.']);
    como($e->sinRol, $e->inst, '/alumnos')->assertForbidden();
});

it('el middleware de permisos bloquea al rol sin adm.alumnos.ver', function () {
    $e = escenarioPermisos();
    expect($e->rolSinPermiso)->not->toBeNull();
    como($e->sinPermiso, $e->inst, '/alumnos')->assertForbidden()->assertJson(['message' => 'No tienes permiso para realizar esta acción.']);
});

it('secretaría ve a todos los alumnos del colegio', function () {
    $e = escenarioPermisos();
    $ids = idsDe(como($e->secretaria, $e->inst, '/alumnos')->assertOk());
    expect($ids)->toContain($e->alumnoB, ...$e->alumnosA);
    como($e->secretaria, $e->inst, "/alumnos/{$e->alumnoB}")->assertOk();
});

it('el docente solo ve a los alumnos de sus secciones', function () {
    $e = escenarioPermisos();
    expect(idsDe(como($e->docente, $e->inst, '/alumnos')->assertOk()))->toBe([$e->alumnoB]);
    como($e->docente, $e->inst, "/alumnos/{$e->alumnoB}")->assertOk();
    como($e->docente, $e->inst, "/alumnos/{$e->alumnosA[0]}")->assertForbidden();
});

it('el tutor ve a los alumnos de la sección que tutorea y no los de otra', function () {
    $e = escenarioPermisos();
    $ids = idsDe(como($e->tutor, $e->inst, '/alumnos')->assertOk());
    expect($ids)->toBe(collect($e->alumnosA)->sort()->values()->all());
    como($e->tutor, $e->inst, "/alumnos/{$e->alumnoB}")->assertForbidden();
});

it('el apoderado solo ve a sus hijos', function () {
    $e = escenarioPermisos();
    como($e->apoderado, $e->inst, "/alumnos/{$e->alumnosA[0]}")->assertOk()->assertJsonPath('data.id', $e->alumnosA[0]);
    como($e->apoderado, $e->inst, "/alumnos/{$e->alumnosA[1]}")->assertForbidden();
    // Apoderado no tiene permisos de staff: no lista alumnos.
    como($e->apoderado, $e->inst, '/alumnos')->assertForbidden();
});

it('el alumno solo se ve a sí mismo', function () {
    $e = escenarioPermisos();
    como($e->alumno, $e->inst, "/alumnos/{$e->alumnosA[1]}")->assertOk();
    como($e->alumno, $e->inst, "/alumnos/{$e->alumnosA[0]}")->assertForbidden();
});

it('desde otro colegio, un alumno ajeno no existe', function () {
    $e = escenarioPermisos();
    como($e->ajeno, $e->otra, "/alumnos/{$e->alumnosA[0]}")->assertNotFound()->assertJson(['message' => 'No encontramos lo que buscas.']);
    expect(idsDe(como($e->ajeno, $e->otra, '/alumnos')->assertOk()))->toBe([]);
});

it('los permisos del catálogo funcionan con can()', function () {
    $e = escenarioPermisos();
    DB::transaction(function () use ($e) {
        ContextoInstitucion::fijar($e->inst);
        app(Autorizacion::class)->olvidar();
        expect($e->secretaria->can('adm.alumnos.ver'))->toBeTrue()
            ->and($e->docente->can('pag.pagos.anular'))->toBeFalse();
    });
});
