<?php

namespace App\Modulos\AUL\Services;

use App\Models\User;
use App\Modulos\COM\Services\Notificador;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Http\Controllers\FamiliaController;
use App\Modulos\SEG\Models\Archivo;
use App\Modulos\SEG\Services\AlmacenArchivos;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-125 / 126 / 127 / 134 · Tareas del aula.
 *
 * - El docente la publica con instrucciones, fecha límite, si acepta entregas tardías y la
 *   competencia que evalúa (la nota es una sugerencia para el registro oficial).
 * - El alumno entrega con texto o archivo (también desde el celular, con una foto). Puede volver
 *   a entregar mientras no esté revisada; la entrega fuera de plazo queda marcada como tardía.
 * - El docente revisa con nota (AD/A/B/C o 00–20 según el grado) y comentario; avisa al alumno y
 *   a la familia. La familia ve las tareas de sus hijos con su estado.
 */
class Tareas
{
    public function __construct(private Aula $aula, private AlmacenArchivos $almacen, private Notificador $notificador) {}

    /**
     * @param  array{aula_unidad_id: int, titulo: string, instrucciones: string, vence_at: string, acepta_tardias?: bool, competencia_id?: int|null, publicar?: bool}  $d
     */
    public function guardar(int $cursoId, ?int $id, array $d, ?UploadedFile $archivo, User $usuario): array
    {
        $this->aula->exigirDocente($cursoId, $usuario);
        abort_unless(DB::table('aula_unidades')->where('id', $d['aula_unidad_id'])->where('aula_curso_id', $cursoId)->exists(), 404, 'La unidad no es de este curso.');
        $this->validarCompetencia($cursoId, $d['competencia_id'] ?? null);
        $fila = ['aula_unidad_id' => (int) $d['aula_unidad_id'], 'titulo' => trim($d['titulo']), 'instrucciones' => trim($d['instrucciones']),
            'vence_at' => Carbon::parse($d['vence_at']), 'acepta_tardias' => (bool) ($d['acepta_tardias'] ?? true), 'competencia_id' => $d['competencia_id'] ?? null];
        if ($archivo) {
            $fila['archivo_id'] = $this->almacen->guardar($archivo, $usuario->id)->id;
        }
        if ($id) {
            $t = $this->tareaDelCurso($cursoId, $id);
            DB::table('aula_tareas')->where('id', $id)->update($fila);
            $publicar = ! $t->publicada_at && ! empty($d['publicar']);
        } else {
            $id = (int) DB::table('aula_tareas')->insertGetId(['institucion_id' => ContextoInstitucion::requerido(), 'creado_por' => $usuario->id] + $fila);
            $publicar = ! empty($d['publicar']);
        }
        if ($publicar) {
            $this->publicar($cursoId, $id, $usuario);
        }

        return (array) DB::table('aula_tareas')->find($id);
    }

    public function publicar(int $cursoId, int $id, User $usuario): void
    {
        $this->aula->exigirDocente($cursoId, $usuario);
        $t = $this->tareaDelCurso($cursoId, $id);
        if ($t->publicada_at) {
            return;
        }
        DB::table('aula_tareas')->where('id', $id)->update(['publicada_at' => now()]);
        $curso = DB::table('aula_cursos')->where('id', $cursoId)->value('titulo');
        $this->notificador->enviar($this->aula->usuariosDelCurso($cursoId, true), ['app'], 'tareas', "Nueva tarea: {$t->titulo}",
            "{$curso} · entrega hasta el ".Carbon::parse($t->vence_at)->timezone(config('app.timezone'))->format('d/m H:i').'.', ['pantalla' => 'aula', 'id' => $cursoId]);
    }

    public function quitar(int $cursoId, int $id, User $usuario): void
    {
        $this->aula->exigirDocente($cursoId, $usuario);
        $this->tareaDelCurso($cursoId, $id);
        if (DB::table('aula_entregas')->where('aula_tarea_id', $id)->exists()) {
            throw ValidationException::withMessages(['tarea' => ['La tarea ya tiene entregas: no se puede borrar.']]);
        }
        DB::table('aula_tareas')->where('id', $id)->delete();
    }

    /** El alumno entrega (o vuelve a entregar mientras no esté revisada). */
    public function entregar(int $cursoId, int $id, ?string $texto, ?UploadedFile $archivo, User $usuario): array
    {
        $alumno = $this->aula->alumnoDe($cursoId, $usuario);
        $t = $this->tareaDelCurso($cursoId, $id);
        abort_if(! $t->publicada_at, 404);
        if (trim((string) $texto) === '' && ! $archivo) {
            throw ValidationException::withMessages(['texto' => ['Escribe tu respuesta o adjunta un archivo.']]);
        }
        $tardia = now()->greaterThan(Carbon::parse($t->vence_at));
        if ($tardia && ! $t->acepta_tardias) {
            throw ValidationException::withMessages(['tarea' => ['El plazo de entrega terminó y esta tarea no acepta entregas tardías.']]);
        }
        $previa = DB::table('aula_entregas')->where('aula_tarea_id', $id)->where('alumno_id', $alumno)->first();
        if ($previa?->revisado_at) {
            throw ValidationException::withMessages(['tarea' => ['Tu entrega ya fue revisada: no se puede cambiar.']]);
        }
        $a = $archivo ? $this->almacen->guardar($archivo, $usuario->id) : null;
        $fila = ['texto' => $texto !== null ? trim($texto) : null, 'entregado_at' => now(), 'tardia' => $tardia]
            + ($a ? ['archivo_id' => $a->id, 'archivo_nombre' => $a->nombre_original] : ($previa ? [] : ['archivo_id' => null]));
        if ($previa) {
            DB::table('aula_entregas')->where('id', $previa->id)->update($fila);
        } else {
            DB::table('aula_entregas')->insert(['institucion_id' => ContextoInstitucion::requerido(), 'aula_tarea_id' => $id, 'alumno_id' => $alumno] + $fila);
        }
        $this->aula->registrarAcceso($cursoId, $usuario->id, 'tarea', $id);

        return (array) DB::table('aula_entregas')->where('aula_tarea_id', $id)->where('alumno_id', $alumno)->first(['entregado_at', 'tardia', 'texto', 'archivo_nombre', 'nota', 'comentario']);
    }

    /** Entregas de una tarea: todos los alumnos del curso, con o sin entrega. */
    public function entregas(int $cursoId, int $id, User $usuario): array
    {
        $this->aula->exigirDocente($cursoId, $usuario);
        $t = $this->tareaDelCurso($cursoId, $id);
        $entregas = DB::table('aula_entregas')->where('aula_tarea_id', $id)->get()->keyBy('alumno_id');

        return [
            'tarea' => (array) $t,
            'escala' => $this->escala($cursoId),
            'alumnos' => $this->aula->alumnosDelCurso($cursoId)->map(function ($a) use ($entregas) {
                $e = $entregas[$a->id] ?? null;

                return ['alumno_id' => (int) $a->id, 'alumno' => $a->nombre, 'estado' => ! $e ? 'pendiente' : ($e->revisado_at ? 'revisada' : 'entregada'),
                    'entregado_at' => $e?->entregado_at, 'tardia' => (bool) ($e?->tardia), 'texto' => $e?->texto, 'archivo' => $e?->archivo_nombre,
                    'nota' => $e?->nota, 'comentario' => $e?->comentario];
            })->values()->all(),
        ];
    }

    public function revisar(int $cursoId, int $id, int $alumnoId, string $nota, ?string $comentario, User $usuario): array
    {
        $this->aula->exigirDocente($cursoId, $usuario);
        $t = $this->tareaDelCurso($cursoId, $id);
        $nota = strtoupper(trim($nota));
        if (! self::notaValida($nota, $this->escala($cursoId))) {
            throw ValidationException::withMessages(['nota' => [$this->escala($cursoId) === 'vigesimal' ? 'La nota va de 00 a 20.' : 'La nota es AD, A, B o C.']]);
        }
        $n = DB::table('aula_entregas')->where('aula_tarea_id', $id)->where('alumno_id', $alumnoId)->update([
            'nota' => strlen($nota) === 1 && ctype_digit($nota) ? '0'.$nota : $nota, 'comentario' => $comentario !== null ? trim($comentario) : null, 'revisado_por' => $usuario->id, 'revisado_at' => now(),
        ]);
        if (! $n) {
            throw ValidationException::withMessages(['alumno_id' => ['Ese alumno no entregó la tarea.']]);
        }
        $this->notificador->enviar($this->aula->usuariosDelCurso($cursoId, true, [$alumnoId]), ['app'], 'tareas', "Tarea revisada: {$t->titulo}",
            "Nota {$nota}.".($comentario ? ' '.mb_substr(trim($comentario), 0, 100) : ''), ['pantalla' => 'aula', 'id' => $cursoId]);

        return collect($this->entregas($cursoId, $id, $usuario)['alumnos'])->firstWhere('alumno_id', $alumnoId);
    }

    public function urlArchivoEntrega(int $cursoId, int $id, int $alumnoId, User $usuario): string
    {
        $rol = $this->aula->rol($cursoId, $usuario);
        if ($rol === Aula::ROL_ALUMNO) {
            abort_unless($this->aula->alumnoDe($cursoId, $usuario) === $alumnoId, 404);
        } else {
            abort_unless($rol === Aula::ROL_DOCENTE, 403);
        }
        $archivo = DB::table('aula_entregas')->where('aula_tarea_id', $id)->where('alumno_id', $alumnoId)->value('archivo_id');
        abort_if(! $archivo, 404);

        return $this->almacen->urlTemporal(Archivo::query()->findOrFail($archivo));
    }

    public function urlArchivoTarea(int $cursoId, int $id, User $usuario): string
    {
        $rol = $this->aula->rol($cursoId, $usuario);
        $t = $this->tareaDelCurso($cursoId, $id);
        // El archivo de una tarea en borrador solo lo ve el docente.
        abort_if(! $t->archivo_id || ($rol !== Aula::ROL_DOCENTE && ! $t->publicada_at), 404);

        return $this->almacen->urlTemporal(Archivo::query()->findOrFail($t->archivo_id));
    }

    /** EDUN-134 · Tareas de los hijos (familia): publicadas, con su estado y nota. */
    public function deHijos(User $usuario): array
    {
        $hijos = FamiliaController::alumnosDe((int) $usuario->id);
        if (! $hijos) {
            return [];
        }
        $secciones = DB::table('matriculas as m')->join('alumnos as a', 'a.id', '=', 'm.alumno_id')->whereIn('m.alumno_id', $hijos)->where('m.estado', 'matriculado')->get(['m.alumno_id', 'm.seccion_id', 'a.nombres']);

        return DB::table('aula_tareas as t')->join('aula_unidades as u', 'u.id', '=', 't.aula_unidad_id')->join('aula_cursos as ac', 'ac.id', '=', 'u.aula_curso_id')
            ->join('cargas_docentes as c', 'c.id', '=', 'ac.carga_docente_id')->whereIn('c.seccion_id', $secciones->pluck('seccion_id'))->where('ac.visible', true)
            ->whereNotNull('t.publicada_at')->where('t.vence_at', '>=', now()->subDays(14))->orderBy('t.vence_at')->limit(100)
            ->get(['t.id', 't.titulo', 't.vence_at', 't.acepta_tardias', 'ac.id as curso_id', 'ac.titulo as curso', 'c.seccion_id'])
            ->flatMap(function ($t) use ($secciones) {
                return $secciones->where('seccion_id', $t->seccion_id)->map(function ($h) use ($t) {
                    $e = DB::table('aula_entregas')->where('aula_tarea_id', $t->id)->where('alumno_id', $h->alumno_id)->first(['entregado_at', 'tardia', 'nota', 'revisado_at']);

                    return ['id' => (int) $t->id, 'titulo' => $t->titulo, 'curso' => $t->curso, 'curso_id' => (int) $t->curso_id, 'vence_at' => $t->vence_at, 'alumno_id' => (int) $h->alumno_id, 'alumno' => $h->nombres,
                        'estado' => $e ? ($e->revisado_at ? 'revisada' : 'entregada') : (Carbon::parse($t->vence_at)->isPast() ? 'vencida' : 'pendiente'),
                        'tardia' => (bool) ($e?->tardia), 'nota' => $e?->nota];
                });
            })->values()->all();
    }

    public static function notaValida(string $nota, string $escala): bool
    {
        return $escala === 'vigesimal' ? (bool) preg_match('/^(0?\d|1\d|20)$/', $nota) : in_array($nota, ['AD', 'A', 'B', 'C'], true);
    }

    /** Nota sugerida a partir de un porcentaje (cuestionarios). */
    public static function notaDesdePorcentaje(float $pct, string $escala): string
    {
        if ($escala === 'vigesimal') {
            return str_pad((string) (int) round($pct * 20 / 100), 2, '0', STR_PAD_LEFT);
        }

        return $pct >= 90 ? 'AD' : ($pct >= 70 ? 'A' : ($pct >= 50 ? 'B' : 'C'));
    }

    public function escala(int $cursoId): string
    {
        return (string) DB::table('aula_cursos as ac')->join('cargas_docentes as c', 'c.id', '=', 'ac.carga_docente_id')->join('secciones as s', 's.id', '=', 'c.seccion_id')
            ->join('grados as g', 'g.id', '=', 's.grado_id')->where('ac.id', $cursoId)->value('g.escala_calificacion') ?: 'literal';
    }

    private function validarCompetencia(int $cursoId, ?int $competencia): void
    {
        if (! $competencia) {
            return;
        }
        $area = DB::table('aula_cursos as ac')->join('cargas_docentes as c', 'c.id', '=', 'ac.carga_docente_id')->where('ac.id', $cursoId)->value('c.area_curricular_id');
        if (! DB::table('competencias')->where('id', $competencia)->where('area_curricular_id', $area)->exists()) {
            throw ValidationException::withMessages(['competencia_id' => ['La competencia no es del área del curso.']]);
        }
    }

    private function tareaDelCurso(int $cursoId, int $id): object
    {
        $t = DB::table('aula_tareas as t')->join('aula_unidades as u', 'u.id', '=', 't.aula_unidad_id')->where('t.id', $id)->where('u.aula_curso_id', $cursoId)->first(['t.*']);
        abort_if(! $t, 404, 'La tarea no existe.');

        return $t;
    }
}
