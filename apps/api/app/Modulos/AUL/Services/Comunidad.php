<?php

namespace App\Modulos\AUL\Services;

use App\Models\User;
use App\Modulos\COM\Services\Notificador;
use App\Modulos\Compartido\ContextoInstitucion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-131 foros · 132 clases en vivo · 133 seguimiento de la actividad · 140 observaciones.
 *
 * - Foros: el docente los abre; alumnos y docente escriben y responden; el docente oculta mensajes.
 * - Clases en vivo: solo enlace a Meet, Zoom, Teams u otro (no se aloja video), recordatorio 15
 *   minutos antes (aul:recordatorios) y enlace de la grabación.
 * - Seguimiento por alumno: avance de materiales, tiempo de estudio, última actividad, tareas,
 *   nota de cuestionarios y observaciones; aviso a la familia desde el seguimiento.
 * - Observaciones (fortaleza, aspecto a mejorar, comentario): las ven el docente, tutoría y
 *   coordinación; no los alumnos.
 */
class Comunidad
{
    public const PLATAFORMAS = ['meet', 'zoom', 'teams', 'otro'];

    public function __construct(private Aula $aula, private Evaluaciones $evaluaciones, private Notificador $notificador) {}

    // ───────────── Foros (EDUN-131)

    public function foros(int $cursoId, User $usuario): array
    {
        $this->rolEnCurso($cursoId, $usuario, [Aula::ROL_DOCENTE, Aula::ROL_ALUMNO, Aula::ROL_SEGUIMIENTO]);

        return DB::table('aula_foros as f')->where('f.aula_curso_id', $cursoId)->orderByDesc('f.id')
            ->get(['f.id', 'f.titulo', 'f.created_at', DB::raw('(select count(*) from aula_foro_mensajes m where m.aula_foro_id = f.id and not m.oculto) as mensajes')])
            ->map(fn ($f) => array_merge((array) $f, ['mensajes' => (int) $f->mensajes]))->all();
    }

    public function crearForo(int $cursoId, string $titulo, User $usuario): array
    {
        $this->aula->exigirDocente($cursoId, $usuario);
        $id = (int) DB::table('aula_foros')->insertGetId(['institucion_id' => ContextoInstitucion::requerido(), 'aula_curso_id' => $cursoId, 'titulo' => trim($titulo), 'creado_por' => $usuario->id]);

        return collect($this->foros($cursoId, $usuario))->firstWhere('id', $id);
    }

    public function mensajes(int $cursoId, int $foroId, User $usuario): array
    {
        $rol = $this->rolEnCurso($cursoId, $usuario, [Aula::ROL_DOCENTE, Aula::ROL_ALUMNO, Aula::ROL_SEGUIMIENTO]);
        $this->foroDelCurso($cursoId, $foroId);

        return DB::table('aula_foro_mensajes as m')->join('users as u', 'u.id', '=', 'm.autor_id')->where('m.aula_foro_id', $foroId)
            ->when($rol === Aula::ROL_ALUMNO, fn ($q) => $q->where('m.oculto', false))->orderBy('m.id')
            ->get(['m.id', 'm.contenido', 'm.respuesta_a', 'm.oculto', 'm.created_at', 'm.autor_id', DB::raw("trim(u.nombres || ' ' || u.apellidos) as autor")])
            ->map(fn ($m) => array_merge((array) $m, ['mio' => (int) $m->autor_id === (int) $usuario->id]))->all();
    }

    public function escribir(int $cursoId, int $foroId, string $contenido, ?int $respuestaA, User $usuario): array
    {
        $this->rolEnCurso($cursoId, $usuario, [Aula::ROL_DOCENTE, Aula::ROL_ALUMNO]);
        $this->foroDelCurso($cursoId, $foroId);
        if ($respuestaA && ! DB::table('aula_foro_mensajes')->where('id', $respuestaA)->where('aula_foro_id', $foroId)->exists()) {
            throw ValidationException::withMessages(['respuesta_a' => ['Ese mensaje no es de este foro.']]);
        }
        $id = (int) DB::table('aula_foro_mensajes')->insertGetId(['institucion_id' => ContextoInstitucion::requerido(), 'aula_foro_id' => $foroId, 'autor_id' => $usuario->id,
            'contenido' => trim($contenido), 'respuesta_a' => $respuestaA]);
        $this->aula->registrarAcceso($cursoId, $usuario->id, 'foro', $foroId);

        return collect($this->mensajes($cursoId, $foroId, $usuario))->firstWhere('id', $id);
    }

    public function ocultar(int $cursoId, int $foroId, int $mensajeId, bool $oculto, User $usuario): void
    {
        $this->aula->exigirDocente($cursoId, $usuario);
        $this->foroDelCurso($cursoId, $foroId);
        abort_unless(DB::table('aula_foro_mensajes')->where('id', $mensajeId)->where('aula_foro_id', $foroId)->update(['oculto' => $oculto]) >= 0, 404);
    }

    // ───────────── Clases en vivo (EDUN-132)

    public function clases(int $cursoId, User $usuario): array
    {
        $this->aula->rol($cursoId, $usuario);

        return DB::table('aula_clases_vivo')->where('aula_curso_id', $cursoId)->where('inicio', '>=', now()->subDays(30))->orderBy('inicio')
            ->get(['id', 'titulo', 'inicio', 'plataforma', 'url', 'grabacion_url'])->map(fn ($c) => (array) $c)->all();
    }

    public function guardarClase(int $cursoId, ?int $id, array $d, User $usuario): array
    {
        $this->aula->exigirDocente($cursoId, $usuario);
        foreach (['url', 'grabacion_url'] as $campo) {
            if (! empty($d[$campo]) && ! preg_match('#^https://#i', (string) $d[$campo])) {
                throw ValidationException::withMessages([$campo => ['El enlace debe empezar con https://']]);
            }
        }
        $fila = ['titulo' => trim($d['titulo']), 'inicio' => Carbon::parse($d['inicio']), 'plataforma' => $d['plataforma'], 'url' => $d['url'], 'grabacion_url' => $d['grabacion_url'] ?? null];
        if ($id) {
            $c = DB::table('aula_clases_vivo')->where('id', $id)->where('aula_curso_id', $cursoId)->first();
            abort_if(! $c, 404);
            DB::table('aula_clases_vivo')->where('id', $id)->update($fila + (Carbon::parse($c->inicio)->equalTo($fila['inicio']) ? [] : ['recordado_at' => null]));
        } else {
            $id = (int) DB::table('aula_clases_vivo')->insertGetId(['institucion_id' => ContextoInstitucion::requerido(), 'aula_curso_id' => $cursoId, 'creado_por' => $usuario->id] + $fila);
            $curso = DB::table('aula_cursos')->where('id', $cursoId)->value('titulo');
            $this->notificador->enviar($this->aula->usuariosDelCurso($cursoId, true), ['app'], 'tareas', "Clase en vivo: {$fila['titulo']}",
                "{$curso} · ".$fila['inicio']->copy()->timezone(config('app.timezone'))->format('d/m H:i').'.', ['pantalla' => 'aula', 'id' => $cursoId]);
        }

        return (array) DB::table('aula_clases_vivo')->find($id, ['id', 'titulo', 'inicio', 'plataforma', 'url', 'grabacion_url']);
    }

    /** Recordatorio a alumnos y familias de las clases que empiezan en los próximos 15 minutos. */
    public function recordarClases(): int
    {
        $n = 0;
        foreach (DB::table('aula_clases_vivo')->whereNull('recordado_at')->whereBetween('inicio', [now(), now()->addMinutes(15)])->get() as $c) {
            $this->notificador->enviar($this->aula->usuariosDelCurso((int) $c->aula_curso_id, true), ['app'], 'tareas', "La clase «{$c->titulo}» empieza pronto",
                'A las '.Carbon::parse($c->inicio)->timezone(config('app.timezone'))->format('H:i').'. Entra desde el aula.', ['pantalla' => 'aula', 'id' => (int) $c->aula_curso_id]);
            DB::table('aula_clases_vivo')->where('id', $c->id)->update(['recordado_at' => now()]);
            $n++;
        }

        return $n;
    }

    // ───────────── Observaciones (EDUN-140)

    public function observaciones(int $cursoId, ?int $alumnoId, User $usuario): array
    {
        $this->rolEnCurso($cursoId, $usuario, [Aula::ROL_DOCENTE, Aula::ROL_SEGUIMIENTO]);

        return DB::table('aula_observaciones as o')->join('users as u', 'u.id', '=', 'o.autor_id')->join('alumnos as a', 'a.id', '=', 'o.alumno_id')
            ->where('o.aula_curso_id', $cursoId)->when($alumnoId, fn ($q, $a) => $q->where('o.alumno_id', $a))->orderByDesc('o.id')
            ->get(['o.id', 'o.alumno_id', 'o.tipo', 'o.texto', 'o.created_at', DB::raw("a.apellido_paterno || ', ' || a.nombres as alumno"), DB::raw("trim(u.nombres || ' ' || u.apellidos) as autor")])
            ->map(fn ($o) => (array) $o)->all();
    }

    public function observar(int $cursoId, int $alumnoId, string $tipo, string $texto, User $usuario): array
    {
        $this->aula->exigirDocente($cursoId, $usuario);
        abort_unless($this->aula->alumnosDelCurso($cursoId)->contains('id', $alumnoId), 404, 'El alumno no es de este curso.');
        $id = (int) DB::table('aula_observaciones')->insertGetId(['institucion_id' => ContextoInstitucion::requerido(), 'aula_curso_id' => $cursoId, 'alumno_id' => $alumnoId,
            'tipo' => $tipo, 'texto' => trim($texto), 'autor_id' => $usuario->id]);

        return collect($this->observaciones($cursoId, $alumnoId, $usuario))->firstWhere('id', $id);
    }

    // ───────────── Seguimiento (EDUN-133)

    public function seguimiento(int $cursoId, User $usuario): array
    {
        $this->rolEnCurso($cursoId, $usuario, [Aula::ROL_DOCENTE, Aula::ROL_SEGUIMIENTO]);
        $alumnos = $this->aula->alumnosDelCurso($cursoId);
        $tareas = DB::table('aula_tareas as t')->join('aula_unidades as u', 'u.id', '=', 't.aula_unidad_id')->where('u.aula_curso_id', $cursoId)->whereNotNull('t.publicada_at')->pluck('t.id');
        $entregas = DB::table('aula_entregas')->whereIn('aula_tarea_id', $tareas)->get()->groupBy('alumno_id');
        $cuestionarios = DB::table('aula_cuestionarios as q')->join('aula_unidades as u', 'u.id', '=', 'q.aula_unidad_id')->where('u.aula_curso_id', $cursoId)->whereNotNull('q.publicado_at')->get(['q.*']);
        $accesos = DB::table('aula_accesos')->where('aula_curso_id', $cursoId)->whereIn('user_id', $alumnos->pluck('user_id')->filter())
            ->groupBy('user_id')->get(['user_id', DB::raw('sum(segundos) as segundos'), DB::raw('max(created_at) as ultima')])->keyBy('user_id');
        $video = DB::table('aula_progreso as p')->join('aula_recursos as r', 'r.id', '=', 'p.aula_recurso_id')->join('aula_unidades as u', 'u.id', '=', 'r.aula_unidad_id')
            ->where('u.aula_curso_id', $cursoId)->where('r.tipo', 'video')->groupBy('p.alumno_id')->selectRaw('round(avg(p.porcentaje)) as valor, p.alumno_id as clave')->pluck('valor', 'clave');
        $observaciones = DB::table('aula_observaciones')->where('aula_curso_id', $cursoId)->groupBy('alumno_id')->selectRaw('count(*) as valor, alumno_id as clave')->pluck('valor', 'clave');

        return [
            'tareas' => $tareas->count(), 'cuestionarios' => $cuestionarios->count(),
            'alumnos' => $alumnos->map(function ($a) use ($cursoId, $entregas, $cuestionarios, $accesos, $video, $observaciones) {
                $acc = $a->user_id ? ($accesos[$a->user_id] ?? null) : null;
                $notas = $cuestionarios->map(fn ($q) => $this->evaluaciones->notaFinal($q, (int) $a->id))->filter();

                return [
                    'alumno_id' => (int) $a->id, 'alumno' => $a->nombre, 'tiene_cuenta' => (bool) $a->user_id, 'avance' => $this->aula->avance($cursoId, (int) $a->id),
                    'minutos' => (int) round(((int) ($acc->segundos ?? 0)) / 60), 'ultima_actividad' => $acc->ultima ?? null,
                    'video_pct' => isset($video[$a->id]) ? (int) $video[$a->id] : null,
                    'tareas_entregadas' => collect($entregas[$a->id] ?? [])->count(), 'tareas_tardias' => collect($entregas[$a->id] ?? [])->where('tardia', true)->count(),
                    'cuestionarios_rendidos' => $notas->count(), 'promedio_cuestionarios' => $notas->isNotEmpty() ? round($notas->avg('porcentaje'), 1) : null,
                    'observaciones' => (int) ($observaciones[$a->id] ?? 0),
                ];
            })->values()->all(),
        ];
    }

    /** Aviso del docente o del tutor a la familia de un alumno desde el seguimiento. */
    public function avisarFamilia(int $cursoId, int $alumnoId, string $mensaje, User $usuario): int
    {
        $this->rolEnCurso($cursoId, $usuario, [Aula::ROL_DOCENTE, Aula::ROL_SEGUIMIENTO]);
        abort_unless($this->aula->alumnosDelCurso($cursoId)->contains('id', $alumnoId), 404);
        $familia = DB::table('alumno_apoderado as aa')->join('apoderados as p', 'p.id', '=', 'aa.apoderado_id')->where('aa.alumno_id', $alumnoId)->whereNotNull('p.user_id')->pluck('p.user_id')->all();
        $curso = DB::table('aula_cursos')->where('id', $cursoId)->value('titulo');

        return $this->notificador->enviar($familia, ['app'], 'tareas', "Aula: {$curso}", trim($mensaje), ['pantalla' => 'aula', 'id' => $cursoId])['app'];
    }

    private function rolEnCurso(int $cursoId, User $usuario, array $roles): string
    {
        $rol = $this->aula->rol($cursoId, $usuario);
        abort_unless(in_array($rol, $roles, true), 403);

        return $rol;
    }

    private function foroDelCurso(int $cursoId, int $foroId): void
    {
        abort_unless(DB::table('aula_foros')->where('id', $foroId)->where('aula_curso_id', $cursoId)->exists(), 404, 'El foro no existe.');
    }
}
