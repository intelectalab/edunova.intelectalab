<?php

namespace App\Modulos\AUL\Services;

use App\Models\User;
use App\Modulos\COM\Services\Notificador;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Http\Controllers\FamiliaController;
use App\Modulos\SEG\Models\Archivo;
use App\Modulos\SEG\Services\AlcanceAcademico;
use App\Modulos\SEG\Services\AlmacenArchivos;
use App\Modulos\SEG\Services\Autorizacion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-123 / 124 / 135 / 136 / 141 · Cursos, unidades, materiales, avance y avisos del aula.
 *
 * - El curso se crea solo desde la carga docente (un curso por área y sección) y el alumno entra
 *   por su matrícula: no hay matrícula aparte en el aula.
 * - Quién ve qué: el docente de la carga (o coordinación y dirección con permiso de editar) edita;
 *   el alumno de la sección cursa; la familia ve tareas y avance (si el curso lo permite);
 *   tutoría y coordinación siguen el avance.
 * - Desbloqueo en orden: con «secuencial», un material se habilita cuando el anterior está
 *   completado (aula_progreso). Video: se completa al llegar al porcentaje del curso.
 */
class Aula
{
    public const ROL_DOCENTE = 'docente';

    public const ROL_ALUMNO = 'alumno';

    public const ROL_FAMILIA = 'familia';

    public const ROL_SEGUIMIENTO = 'seguimiento';

    public function __construct(private AlcanceAcademico $alcance, private Autorizacion $autorizacion, private AlmacenArchivos $almacen, private Notificador $notificador) {}

    // ───────────── Cursos (EDUN-123)

    /** Crea los cursos que faltan para las cargas docentes del año abierto. Devuelve cuántos creó. */
    public function sincronizar(): int
    {
        $faltan = DB::table('cargas_docentes as c')->join('secciones as s', 's.id', '=', 'c.seccion_id')->join('anios_escolares as y', 'y.id', '=', 's.anio_escolar_id')
            ->join('grados as g', 'g.id', '=', 's.grado_id')->join('areas_curriculares as a', 'a.id', '=', 'c.area_curricular_id')
            ->leftJoin('aula_cursos as ac', 'ac.carga_docente_id', '=', 'c.id')->where('y.estado', 'abierto')->whereNull('ac.id')
            ->get(['c.id', DB::raw("a.nombre || ' · ' || g.nombre || ' ' || s.nombre as titulo")]);
        $inst = ContextoInstitucion::requerido();
        foreach ($faltan as $c) {
            DB::table('aula_cursos')->insertOrIgnore(['institucion_id' => $inst, 'carga_docente_id' => $c->id, 'titulo' => mb_substr($c->titulo, 0, 150)]);
        }

        return $faltan->count();
    }

    /** Mis cursos según quién entra: docente, alumno, familia o coordinación. */
    public function misCursos(User $usuario): array
    {
        $this->sincronizar();
        $q = $this->consultaCursos();
        $alumno = DB::table('alumnos')->where('user_id', $usuario->id)->value('id');
        $hijos = FamiliaController::alumnosDe((int) $usuario->id);
        if ($this->autorizacion->tiene($usuario, 'aul.cursos.ver') && $this->alcance->veTodoElColegio($usuario)) {
            // Coordinación y dirección ven todos.
        } elseif ($this->autorizacion->tiene($usuario, 'aul.cursos.ver')) {
            $personal = DB::table('personal')->where('user_id', $usuario->id)->value('id');
            $q->where(fn ($w) => $w->where('c.personal_id', $personal ?? 0)->orWhere('s.tutor_personal_id', $personal ?? 0));
        } elseif ($alumno || $hijos) {
            $secciones = DB::table('matriculas')->whereIn('alumno_id', $hijos ?: [0])->where('estado', 'matriculado')->pluck('seccion_id');
            $q->whereIn('c.seccion_id', $secciones)->where('ac.visible', true);
        } elseif ($this->autorizacion->tiene($usuario, 'aul.seguimiento.ver')) {
            $q->whereIn('c.seccion_id', $this->alcance->seccionesVisibles($usuario)->select('id'));
        } else {
            return [];
        }
        $cursos = $q->orderBy('g.nivel')->orderBy('g.numero')->orderBy('s.nombre')->orderBy('a.orden')->get();
        $pendientes = $alumno ? $this->pendientesAlumno((int) $alumno, $cursos->pluck('id')->all()) : [];

        return $cursos->map(fn ($c) => array_merge((array) $c, ['pendientes' => $pendientes[$c->id] ?? 0]))->all();
    }

    /** Rol del usuario en el curso, o 404 si no tiene relación con él. */
    public function rol(int $cursoId, User $usuario): string
    {
        $c = DB::table('aula_cursos as ac')->join('cargas_docentes as c', 'c.id', '=', 'ac.carga_docente_id')->join('secciones as s', 's.id', '=', 'c.seccion_id')
            ->where('ac.id', $cursoId)->first(['c.personal_id', 'c.seccion_id', 's.tutor_personal_id', 'ac.visible']);
        abort_if(! $c, 404, 'El curso no existe.');
        $personal = (int) DB::table('personal')->where('user_id', $usuario->id)->value('id');
        if ($personal && (int) $c->personal_id === $personal && $this->autorizacion->tiene($usuario, 'aul.cursos.ver')) {
            return self::ROL_DOCENTE;
        }
        if ($this->autorizacion->tiene($usuario, 'aul.cursos.editar') && $this->alcance->veTodoElColegio($usuario)) {
            return self::ROL_DOCENTE;
        }
        $enSeccion = fn (array $alumnos) => DB::table('matriculas')->whereIn('alumno_id', $alumnos ?: [0])->where('seccion_id', $c->seccion_id)->where('estado', 'matriculado')->exists();
        $alumno = DB::table('alumnos')->where('user_id', $usuario->id)->value('id');
        if ($alumno && $c->visible && $enSeccion([(int) $alumno])) {
            return self::ROL_ALUMNO;
        }
        if ($this->autorizacion->tiene($usuario, 'aul.seguimiento.ver')
            && ($this->alcance->veTodoElColegio($usuario) || ($personal && (int) $c->tutor_personal_id === $personal) || $this->alcance->puedeVerSeccion($usuario, (int) $c->seccion_id))) {
            return self::ROL_SEGUIMIENTO;
        }
        if ($c->visible && $enSeccion(FamiliaController::alumnosDe((int) $usuario->id))) {
            return self::ROL_FAMILIA;
        }
        abort(404, 'El curso no existe.');
    }

    public function exigirDocente(int $cursoId, User $usuario): void
    {
        abort_unless($this->rol($cursoId, $usuario) === self::ROL_DOCENTE, 403, 'Solo el docente del curso o coordinación lo editan.');
    }

    /** El alumno del usuario en el curso (él mismo). */
    public function alumnoDe(int $cursoId, User $usuario): int
    {
        abort_unless($this->rol($cursoId, $usuario) === self::ROL_ALUMNO, 403, 'Solo los alumnos del curso.');

        return (int) DB::table('alumnos')->where('user_id', $usuario->id)->value('id');
    }

    /**
     * El curso con sus unidades y contenidos. Para el alumno, cada contenido trae si está
     * bloqueado (desbloqueo en orden) y su avance; para la familia, solo lo que corresponde.
     */
    public function curso(int $cursoId, User $usuario): array
    {
        $rol = $this->rol($cursoId, $usuario);
        $curso = (array) $this->consultaCursos()->where('ac.id', $cursoId)->first();
        $docente = $rol === self::ROL_DOCENTE;
        $alumno = $rol === self::ROL_ALUMNO ? (int) DB::table('alumnos')->where('user_id', $usuario->id)->value('id') : null;
        $unidades = DB::table('aula_unidades')->where('aula_curso_id', $cursoId)->orderBy('orden')->orderBy('id')->get();
        $ids = $unidades->pluck('id');
        $recursos = DB::table('aula_recursos as r')->leftJoin('archivos as f', 'f.id', '=', 'r.archivo_id')->whereIn('r.aula_unidad_id', $ids)
            ->when(! $docente, fn ($q) => $q->where('r.visible', true))->orderBy('r.orden')->orderBy('r.id')
            ->get(['r.id', 'r.aula_unidad_id', 'r.tipo', 'r.titulo', 'r.url', 'r.contenido', 'r.duracion_seg', 'r.visible', 'r.orden', 'r.archivo_id', 'f.nombre_original', 'f.mime'])->groupBy('aula_unidad_id');
        $tareas = DB::table('aula_tareas')->whereIn('aula_unidad_id', $ids)->when(! $docente, fn ($q) => $q->whereNotNull('publicada_at'))->orderBy('vence_at')->get()->groupBy('aula_unidad_id');
        $cuestionarios = DB::table('aula_cuestionarios')->whereIn('aula_unidad_id', $ids)->when(! $docente, fn ($q) => $q->whereNotNull('publicado_at'))->orderBy('id')->get()->groupBy('aula_unidad_id');
        $progreso = $alumno ? DB::table('aula_progreso')->where('alumno_id', $alumno)->whereIn('aula_recurso_id', $recursos->flatten()->pluck('id'))->get()->keyBy('aula_recurso_id') : collect();
        $entregas = $alumno ? DB::table('aula_entregas')->where('alumno_id', $alumno)->whereIn('aula_tarea_id', $tareas->flatten()->pluck('id'))->get()->keyBy('aula_tarea_id') : collect();
        $encuestas = $alumno ? DB::table('aula_encuestas')->where('alumno_id', $alumno)->whereIn('aula_unidad_id', $ids)->pluck('valor', 'aula_unidad_id') : collect();
        $hoy = now()->toDateString();

        $bloqueado = false;
        $salida = [];
        foreach ($unidades as $u) {
            if (! $docente && $u->visible_desde && $u->visible_desde > $hoy) {
                continue;
            }
            $items = [];
            $unidadCompleta = true;
            foreach ($recursos[$u->id] ?? [] as $r) {
                $p = $progreso[$r->id] ?? null;
                $completo = (bool) ($p?->completado_at);
                $items[] = array_merge((array) $r, [
                    'porcentaje' => (int) ($p->porcentaje ?? 0), 'segundos' => (int) ($p->segundos ?? 0), 'completado' => $completo,
                    'bloqueado' => $alumno !== null && $curso['secuencial'] && $bloqueado,
                ]);
                if (! $completo) {
                    $unidadCompleta = false;
                    $bloqueado = true;
                }
            }
            $salida[] = [
                'id' => (int) $u->id, 'titulo' => $u->titulo, 'orden' => (int) $u->orden, 'visible_desde' => $u->visible_desde,
                'recursos' => $items,
                'tareas' => collect($tareas[$u->id] ?? [])->map(fn ($t) => array_merge((array) $t, [
                    'entrega' => isset($entregas[$t->id]) ? ['entregado_at' => $entregas[$t->id]->entregado_at, 'tardia' => (bool) $entregas[$t->id]->tardia, 'nota' => $entregas[$t->id]->nota, 'comentario' => $entregas[$t->id]->comentario] : null,
                ]))->all(),
                'cuestionarios' => collect($cuestionarios[$u->id] ?? [])->map(fn ($q) => array_merge((array) $q, [
                    'bloqueado' => $alumno !== null && $q->requiere_previos && ! $unidadCompleta,
                ]))->all(),
                'encuesta' => $alumno !== null && $curso['encuesta_activa'] ? ['respondida' => isset($encuestas[$u->id]), 'disponible' => $unidadCompleta] : null,
            ];
        }
        $siguiente = collect($salida)->flatMap(fn ($u) => $u['recursos'])->first(fn ($r) => ! $r['completado'] && ! $r['bloqueado']);

        return array_merge($curso, [
            'rol' => $rol,
            'unidades' => $salida,
            'avisos' => DB::table('aula_avisos as v')->leftJoin('users as u', 'u.id', '=', 'v.autor_id')->where('v.aula_curso_id', $cursoId)->orderByDesc('v.publicado_at')->limit(10)
                ->get(['v.id', 'v.texto', 'v.publicado_at', DB::raw("trim(u.nombres || ' ' || u.apellidos) as autor")])->map(fn ($a) => (array) $a)->all(),
            'continuar' => $siguiente ? ['recurso_id' => $siguiente['id'], 'titulo' => $siguiente['titulo'], 'segundos' => $siguiente['segundos']] : null,
            'avance' => $alumno ? $this->avance($cursoId, $alumno) : null,
        ]);
    }

    /** Configuración del curso (AUL-07). */
    public function configurar(int $cursoId, array $d, User $usuario): array
    {
        $this->exigirDocente($cursoId, $usuario);
        DB::table('aula_cursos')->where('id', $cursoId)->update(array_intersect_key($d, array_flip(['titulo', 'visible', 'secuencial', 'video_sin_adelantar', 'video_pct_completado', 'encuesta_activa', 'avance_visible_familia', 'pdf_descargable'])));

        return $this->curso($cursoId, $usuario);
    }

    // ───────────── Unidades y materiales (EDUN-124)

    public function guardarUnidad(int $cursoId, ?int $id, array $d, User $usuario): array
    {
        $this->exigirDocente($cursoId, $usuario);
        $fila = ['titulo' => trim($d['titulo']), 'orden' => (int) ($d['orden'] ?? 0), 'visible_desde' => $d['visible_desde'] ?? null];
        if ($id) {
            abort_unless(DB::table('aula_unidades')->where('id', $id)->where('aula_curso_id', $cursoId)->update($fila) || DB::table('aula_unidades')->where('id', $id)->where('aula_curso_id', $cursoId)->exists(), 404);
        } else {
            $fila['orden'] = $d['orden'] ?? ((int) DB::table('aula_unidades')->where('aula_curso_id', $cursoId)->max('orden') + 1);
            $id = (int) DB::table('aula_unidades')->insertGetId(['institucion_id' => ContextoInstitucion::requerido(), 'aula_curso_id' => $cursoId] + $fila);
        }

        return (array) DB::table('aula_unidades')->find($id);
    }

    public function quitarUnidad(int $cursoId, int $id, User $usuario): void
    {
        $this->exigirDocente($cursoId, $usuario);
        $u = DB::table('aula_unidades')->where('id', $id)->where('aula_curso_id', $cursoId)->first();
        abort_if(! $u, 404);
        $tiene = DB::table('aula_tareas as t')->join('aula_entregas as e', 'e.aula_tarea_id', '=', 't.id')->where('t.aula_unidad_id', $id)->exists()
            || DB::table('aula_cuestionarios as q')->join('aula_intentos as i', 'i.aula_cuestionario_id', '=', 'q.id')->where('q.aula_unidad_id', $id)->exists();
        if ($tiene) {
            throw ValidationException::withMessages(['unidad' => ['La unidad tiene entregas o intentos de los alumnos: ocúltala en lugar de borrarla.']]);
        }
        DB::transaction(function () use ($id) {
            $recursos = DB::table('aula_recursos')->where('aula_unidad_id', $id)->pluck('id');
            DB::table('aula_progreso')->whereIn('aula_recurso_id', $recursos)->delete();
            DB::table('aula_recursos')->where('aula_unidad_id', $id)->delete();
            DB::table('aula_tareas')->where('aula_unidad_id', $id)->delete();
            $q = DB::table('aula_cuestionarios')->where('aula_unidad_id', $id)->pluck('id');
            DB::table('aula_cuestionario_preguntas')->whereIn('aula_cuestionario_id', $q)->delete();
            DB::table('aula_cuestionarios')->whereIn('id', $q)->delete();
            DB::table('aula_encuestas')->where('aula_unidad_id', $id)->delete();
            DB::table('aula_unidades')->where('id', $id)->delete();
        });
    }

    /**
     * @param  array{tipo: string, titulo: string, url?: string|null, contenido?: string|null, duracion_seg?: int|null, visible?: bool, orden?: int|null}  $d
     */
    public function guardarRecurso(int $cursoId, int $unidadId, ?int $id, array $d, ?UploadedFile $archivo, User $usuario): array
    {
        $this->exigirDocente($cursoId, $usuario);
        abort_unless(DB::table('aula_unidades')->where('id', $unidadId)->where('aula_curso_id', $cursoId)->exists(), 404);
        $fila = ['tipo' => $d['tipo'], 'titulo' => trim($d['titulo']), 'url' => $d['url'] ?? null, 'contenido' => $d['contenido'] ?? null,
            'duracion_seg' => $d['duracion_seg'] ?? null, 'visible' => (bool) ($d['visible'] ?? true)];
        if ($archivo) {
            $fila['archivo_id'] = $this->almacen->guardar($archivo, $usuario->id)->id;
        }
        $necesitaArchivo = in_array($d['tipo'], ['archivo', 'pdf'], true) || ($d['tipo'] === 'video' && empty($d['url']));
        if ($necesitaArchivo && ! $archivo && ! ($id && DB::table('aula_recursos')->where('id', $id)->whereNotNull('archivo_id')->exists())) {
            throw ValidationException::withMessages(['archivo' => ['Sube el archivo del material.']]);
        }
        if (in_array($d['tipo'], ['enlace'], true) && empty($d['url'])) {
            throw ValidationException::withMessages(['url' => ['Escribe el enlace.']]);
        }
        if ($d['tipo'] === 'texto' && trim((string) ($d['contenido'] ?? '')) === '') {
            throw ValidationException::withMessages(['contenido' => ['Escribe el contenido.']]);
        }
        if ($id) {
            abort_unless(DB::table('aula_recursos')->where('id', $id)->where('aula_unidad_id', $unidadId)->exists(), 404);
            DB::table('aula_recursos')->where('id', $id)->update($fila + (isset($d['orden']) ? ['orden' => (int) $d['orden']] : []));
        } else {
            $id = (int) DB::table('aula_recursos')->insertGetId(['institucion_id' => ContextoInstitucion::requerido(), 'aula_unidad_id' => $unidadId,
                'orden' => $d['orden'] ?? ((int) DB::table('aula_recursos')->where('aula_unidad_id', $unidadId)->max('orden') + 1)] + $fila);
        }

        return (array) DB::table('aula_recursos')->find($id);
    }

    public function quitarRecurso(int $cursoId, int $id, User $usuario): void
    {
        $this->exigirDocente($cursoId, $usuario);
        $r = DB::table('aula_recursos as r')->join('aula_unidades as u', 'u.id', '=', 'r.aula_unidad_id')->where('r.id', $id)->where('u.aula_curso_id', $cursoId)->exists();
        abort_if(! $r, 404);
        DB::table('aula_progreso')->where('aula_recurso_id', $id)->delete();
        DB::table('aula_recursos')->where('id', $id)->delete();
    }

    /** URL firmada del archivo de un material (si el usuario puede verlo y no está bloqueado). */
    public function urlRecurso(int $cursoId, int $id, User $usuario): string
    {
        $c = $this->curso($cursoId, $usuario);
        $r = collect($c['unidades'])->flatMap(fn ($u) => $u['recursos'])->firstWhere('id', $id);
        abort_if(! $r || ! $r['archivo_id'], 404);
        if ($r['bloqueado']) {
            throw ValidationException::withMessages(['recurso' => ['Completa primero el contenido anterior.']]);
        }

        return $this->almacen->urlTemporal(Archivo::query()->findOrFail($r['archivo_id']));
    }

    // ───────────── Avance (EDUN-135 / 136)

    /**
     * El alumno registra su avance en un material: porcentaje visto (video) y segundos. El video
     * se completa al llegar al porcentaje del curso; los demás materiales, al abrirlos (100).
     * Con «video sin adelantar», no se acepta un salto de más de 15 puntos sobre lo ya visto.
     */
    public function avanzar(int $cursoId, int $recursoId, int $porcentaje, int $segundos, User $usuario): array
    {
        $alumno = $this->alumnoDe($cursoId, $usuario);
        $c = $this->curso($cursoId, $usuario);
        $r = collect($c['unidades'])->flatMap(fn ($u) => $u['recursos'])->firstWhere('id', $recursoId);
        abort_if(! $r, 404);
        if ($r['bloqueado']) {
            throw ValidationException::withMessages(['recurso' => ['Completa primero el contenido anterior.']]);
        }
        $porcentaje = max(0, min(100, $porcentaje));
        if ($r['tipo'] === 'video' && $c['video_sin_adelantar'] && $porcentaje > $r['porcentaje'] + 15) {
            $porcentaje = $r['porcentaje'];
        }
        $nuevo = max($porcentaje, $r['porcentaje']);
        $meta = $r['tipo'] === 'video' ? (int) $c['video_pct_completado'] : 100;
        $completado = $r['completado'] || $nuevo >= $meta;
        DB::table('aula_progreso')->upsert([[
            'institucion_id' => ContextoInstitucion::requerido(), 'aula_recurso_id' => $recursoId, 'alumno_id' => $alumno, 'porcentaje' => $nuevo,
            'segundos' => max($segundos, $r['segundos']), 'completado_at' => $completado ? (DB::table('aula_progreso')->where('aula_recurso_id', $recursoId)->where('alumno_id', $alumno)->value('completado_at') ?? now()) : null,
        ]], ['aula_recurso_id', 'alumno_id'], ['porcentaje', 'segundos', 'completado_at']);
        $this->registrarAcceso($cursoId, $usuario->id, 'recurso', $recursoId, max(0, $segundos - $r['segundos']));

        return ['porcentaje' => $nuevo, 'completado' => $completado, 'avance' => $this->avance($cursoId, $alumno)];
    }

    public function registrarAcceso(int $cursoId, int $userId, ?string $tipo, ?int $recursoId, int $segundos = 0): void
    {
        DB::table('aula_accesos')->insert(['institucion_id' => ContextoInstitucion::requerido(), 'user_id' => $userId, 'aula_curso_id' => $cursoId,
            'recurso_tipo' => $tipo, 'recurso_id' => $recursoId, 'segundos' => min($segundos, 3600)]);
    }

    /** Porcentaje de materiales completados por el alumno en el curso. */
    public function avance(int $cursoId, int $alumnoId): int
    {
        $total = DB::table('aula_recursos as r')->join('aula_unidades as u', 'u.id', '=', 'r.aula_unidad_id')->where('u.aula_curso_id', $cursoId)->where('r.visible', true)->count();
        if (! $total) {
            return 0;
        }
        $hechos = DB::table('aula_progreso as p')->join('aula_recursos as r', 'r.id', '=', 'p.aula_recurso_id')->join('aula_unidades as u', 'u.id', '=', 'r.aula_unidad_id')
            ->where('u.aula_curso_id', $cursoId)->where('r.visible', true)->where('p.alumno_id', $alumnoId)->whereNotNull('p.completado_at')->count();

        return (int) round($hechos * 100 / $total);
    }

    // ───────────── Avisos (EDUN-141)

    public function publicarAviso(int $cursoId, string $texto, User $usuario): array
    {
        $this->exigirDocente($cursoId, $usuario);
        $id = (int) DB::table('aula_avisos')->insertGetId(['institucion_id' => ContextoInstitucion::requerido(), 'aula_curso_id' => $cursoId, 'texto' => trim($texto), 'autor_id' => $usuario->id]);
        $titulo = DB::table('aula_cursos')->where('id', $cursoId)->value('titulo');
        $this->notificador->enviar($this->usuariosDelCurso($cursoId, true), ['app'], 'tareas', "Aviso en {$titulo}", mb_substr(trim($texto), 0, 140), ['pantalla' => 'aula', 'id' => $cursoId]);

        return (array) DB::table('aula_avisos')->find($id);
    }

    public function quitarAviso(int $cursoId, int $id, User $usuario): void
    {
        $this->exigirDocente($cursoId, $usuario);
        abort_unless(DB::table('aula_avisos')->where('id', $id)->where('aula_curso_id', $cursoId)->delete(), 404);
    }

    // ───────────── Apoyo

    /** Alumnos matriculados en la sección del curso. */
    public function alumnosDelCurso(int $cursoId)
    {
        return DB::table('aula_cursos as ac')->join('cargas_docentes as c', 'c.id', '=', 'ac.carga_docente_id')
            ->join('matriculas as m', fn ($j) => $j->on('m.seccion_id', '=', 'c.seccion_id')->where('m.estado', '=', 'matriculado'))
            ->join('alumnos as a', 'a.id', '=', 'm.alumno_id')->where('ac.id', $cursoId)->whereNull('a.eliminado_at')->orderBy('a.apellido_paterno')->orderBy('a.nombres')
            ->get(['a.id', 'a.user_id', DB::raw("a.apellido_paterno || ' ' || coalesce(a.apellido_materno, '') || ', ' || a.nombres as nombre")])
            ->map(function ($a) {
                $a->nombre = preg_replace('/\s+/', ' ', $a->nombre);

                return $a;
            });
    }

    /** Usuarios de los alumnos (con cuenta) y, si se pide, de sus familias. */
    public function usuariosDelCurso(int $cursoId, bool $conFamilias, ?array $soloAlumnos = null): array
    {
        $alumnos = $this->alumnosDelCurso($cursoId)->when($soloAlumnos !== null, fn ($c) => $c->whereIn('id', $soloAlumnos));
        $u = $alumnos->pluck('user_id')->filter();
        if ($conFamilias) {
            $u = $u->merge(DB::table('alumno_apoderado as aa')->join('apoderados as p', 'p.id', '=', 'aa.apoderado_id')->whereIn('aa.alumno_id', $alumnos->pluck('id'))->whereNotNull('p.user_id')->pluck('p.user_id'));
        }

        return $u->map(fn ($i) => (int) $i)->unique()->values()->all();
    }

    private function consultaCursos()
    {
        return DB::table('aula_cursos as ac')->join('cargas_docentes as c', 'c.id', '=', 'ac.carga_docente_id')->join('secciones as s', 's.id', '=', 'c.seccion_id')
            ->join('grados as g', 'g.id', '=', 's.grado_id')->join('areas_curriculares as a', 'a.id', '=', 'c.area_curricular_id')
            ->leftJoin('personal as p', 'p.id', '=', 'c.personal_id')
            ->select(['ac.id', 'ac.titulo', 'ac.visible', 'ac.secuencial', 'ac.video_sin_adelantar', 'ac.video_pct_completado', 'ac.encuesta_activa', 'ac.avance_visible_familia', 'ac.pdf_descargable',
                'c.seccion_id', 'c.area_curricular_id', 's.grado_id', DB::raw("g.nombre || ' ' || s.nombre as seccion"), 'a.nombre as area', 'g.escala_calificacion as escala',
                DB::raw("trim(coalesce(p.nombres, '') || ' ' || coalesce(p.apellidos, '')) as docente")]);
    }

    /** Tareas publicadas sin entregar y cuestionarios abiertos sin intento, por curso. */
    private function pendientesAlumno(int $alumno, array $cursos): array
    {
        $tareas = DB::table('aula_tareas as t')->join('aula_unidades as u', 'u.id', '=', 't.aula_unidad_id')->whereIn('u.aula_curso_id', $cursos)->whereNotNull('t.publicada_at')
            ->where('t.vence_at', '>=', now())->whereNotExists(fn ($q) => $q->from('aula_entregas as e')->whereColumn('e.aula_tarea_id', 't.id')->where('e.alumno_id', $alumno))
            ->groupBy('u.aula_curso_id')->selectRaw('count(*) as valor, u.aula_curso_id as clave')->pluck('valor', 'clave');
        $cuest = DB::table('aula_cuestionarios as q')->join('aula_unidades as u', 'u.id', '=', 'q.aula_unidad_id')->whereIn('u.aula_curso_id', $cursos)->whereNotNull('q.publicado_at')
            ->where(fn ($w) => $w->whereNull('q.cierra_at')->orWhere('q.cierra_at', '>=', now()))
            ->whereNotExists(fn ($w) => $w->from('aula_intentos as i')->whereColumn('i.aula_cuestionario_id', 'q.id')->where('i.alumno_id', $alumno)->whereNotNull('i.enviado_at'))
            ->groupBy('u.aula_curso_id')->selectRaw('count(*) as valor, u.aula_curso_id as clave')->pluck('valor', 'clave');
        $r = [];
        foreach ($cursos as $c) {
            $r[$c] = (int) ($tareas[$c] ?? 0) + (int) ($cuest[$c] ?? 0);
        }

        return $r;
    }
}
