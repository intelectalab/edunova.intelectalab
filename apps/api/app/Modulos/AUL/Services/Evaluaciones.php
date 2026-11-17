<?php

namespace App\Modulos\AUL\Services;

use App\Models\User;
use App\Modulos\COM\Services\Notificador;
use App\Modulos\Compartido\ContextoInstitucion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-128 / 129 / 130 / 137 / 138 / 139 · Bancos de preguntas, cuestionarios, intentos y encuesta.
 *
 * Preguntas (respuesta_correcta en JSON):
 * - opcion_multiple: opciones = ["…", "…"], respuesta = índice de la correcta.
 * - verdadero_falso: respuesta = true | false.
 * - relacionar: opciones = {izquierda: [...], derecha: [...]}, respuesta = {índice izquierda: índice derecha};
 *   es correcta solo si todos los pares coinciden.
 * - respuesta_corta: respuesta = ["aceptada", "variante"] (sin tildes ni mayúsculas).
 *
 * Las preguntas generadas con IA quedan marcadas e inactivas hasta que el docente las revisa.
 * Cuestionario: preguntas fijas o al azar de un banco, orden aleatorio, intentos máximos más los
 * extra que otorga el docente, tiempo límite, y se conserva la mejor nota, la última o el promedio.
 */
class Evaluaciones
{
    public const TIPOS = ['opcion_multiple', 'verdadero_falso', 'relacionar', 'respuesta_corta'];

    public function __construct(private Aula $aula, private Tareas $tareas, private Notificador $notificador) {}

    // ───────────── Bancos y preguntas (EDUN-137)

    public function bancos(int $cursoId, User $usuario): array
    {
        $this->aula->exigirDocente($cursoId, $usuario);
        $area = $this->areaDelCurso($cursoId);

        return DB::table('aula_bancos as b')->where('b.area_curricular_id', $area)
            ->where(fn ($q) => $q->where('b.alcance', 'area')->orWhere('b.aula_curso_id', $cursoId))->orderBy('b.alcance')->orderBy('b.nombre')
            ->get(['b.id', 'b.nombre', 'b.alcance', 'b.aula_curso_id',
                DB::raw('(select count(*) from aula_preguntas p where p.aula_banco_id = b.id and p.activa) as activas'),
                DB::raw('(select count(*) from aula_preguntas p where p.aula_banco_id = b.id and p.generada_ia and p.revisada_por is null) as por_revisar')])
            ->map(fn ($b) => array_merge((array) $b, ['activas' => (int) $b->activas, 'por_revisar' => (int) $b->por_revisar]))->all();
    }

    public function crearBanco(int $cursoId, string $nombre, string $alcance, User $usuario): array
    {
        $this->aula->exigirDocente($cursoId, $usuario);
        $id = (int) DB::table('aula_bancos')->insertGetId(['institucion_id' => ContextoInstitucion::requerido(), 'nombre' => trim($nombre), 'alcance' => $alcance,
            'area_curricular_id' => $this->areaDelCurso($cursoId), 'aula_curso_id' => $alcance === 'curso' ? $cursoId : null, 'creado_por' => $usuario->id]);

        return collect($this->bancos($cursoId, $usuario))->firstWhere('id', $id);
    }

    public function preguntas(int $cursoId, int $bancoId, User $usuario): array
    {
        $this->bancoDelCurso($cursoId, $bancoId, $usuario);

        return DB::table('aula_preguntas')->where('aula_banco_id', $bancoId)->orderByDesc('activa')->orderBy('id')->get()
            ->map(fn ($p) => $this->pregunta($p, true))->all();
    }

    /**
     * @param  array{tipo: string, enunciado: string, opciones?: mixed, respuesta_correcta: mixed, retroalimentacion?: string|null, unidad?: string|null, activa?: bool}  $d
     */
    public function guardarPregunta(int $cursoId, int $bancoId, ?int $id, array $d, User $usuario): array
    {
        $banco = $this->bancoDelCurso($cursoId, $bancoId, $usuario);
        $this->validarPregunta($d['tipo'], $d['opciones'] ?? null, $d['respuesta_correcta']);
        $fila = ['tipo' => $d['tipo'], 'enunciado' => trim($d['enunciado']), 'opciones' => isset($d['opciones']) ? json_encode($d['opciones'], JSON_UNESCAPED_UNICODE) : null,
            'respuesta_correcta' => json_encode($d['respuesta_correcta'], JSON_UNESCAPED_UNICODE), 'retroalimentacion' => $d['retroalimentacion'] ?? null, 'unidad' => $d['unidad'] ?? null];
        if ($id) {
            $p = DB::table('aula_preguntas')->where('id', $id)->where('aula_banco_id', $bancoId)->first();
            abort_if(! $p, 404);
            // Editar una pregunta de IA cuenta como revisarla.
            DB::table('aula_preguntas')->where('id', $id)->update($fila + ['activa' => (bool) ($d['activa'] ?? $p->activa), 'revisada_por' => $p->generada_ia ? $usuario->id : $p->revisada_por]);
        } else {
            $id = (int) DB::table('aula_preguntas')->insertGetId(['institucion_id' => ContextoInstitucion::requerido(), 'aula_banco_id' => $bancoId,
                'area_curricular_id' => $banco->area_curricular_id, 'grado_id' => DB::table('aula_cursos as ac')->join('cargas_docentes as c', 'c.id', '=', 'ac.carga_docente_id')->join('secciones as s', 's.id', '=', 'c.seccion_id')->where('ac.id', $cursoId)->value('s.grado_id'),
                'activa' => (bool) ($d['activa'] ?? true)] + $fila);
        }

        return $this->pregunta(DB::table('aula_preguntas')->find($id), true);
    }

    /** La pregunta de IA revisada pasa a activa (o se descarta). */
    public function revisar(int $cursoId, int $bancoId, int $id, bool $aprobar, User $usuario): void
    {
        $this->bancoDelCurso($cursoId, $bancoId, $usuario);
        $n = DB::table('aula_preguntas')->where('id', $id)->where('aula_banco_id', $bancoId)->update(['revisada_por' => $usuario->id, 'activa' => $aprobar]);
        abort_if(! $n, 404);
    }

    /** EDUN-129 · Sugerencias de la IA por competencia y grado: quedan para revisión, nunca activas. */
    public function generarConIa(int $cursoId, int $bancoId, ?int $competenciaId, string $tema, int $cantidad, User $usuario, GeneradorPreguntas $generador): array
    {
        $banco = $this->bancoDelCurso($cursoId, $bancoId, $usuario);
        $info = DB::table('aula_cursos as ac')->join('cargas_docentes as c', 'c.id', '=', 'ac.carga_docente_id')->join('secciones as s', 's.id', '=', 'c.seccion_id')
            ->join('grados as g', 'g.id', '=', 's.grado_id')->join('areas_curriculares as a', 'a.id', '=', 'c.area_curricular_id')->where('ac.id', $cursoId)
            ->first(['a.nombre as area', 'g.nombre as grado', 'g.nivel', 's.grado_id']);
        $competencia = $competenciaId ? DB::table('competencias')->where('id', $competenciaId)->where('area_curricular_id', $banco->area_curricular_id)->value('nombre') : null;
        $sugerencias = $generador->generar(['area' => $info->area, 'grado' => $info->grado, 'nivel' => $info->nivel, 'competencia' => $competencia, 'tema' => trim($tema)], min(10, max(1, $cantidad)));
        $ids = [];
        foreach ($sugerencias as $s) {
            try {
                $this->validarPregunta($s['tipo'], $s['opciones'] ?? null, $s['respuesta_correcta']);
            } catch (ValidationException) {
                continue;
            }
            $ids[] = (int) DB::table('aula_preguntas')->insertGetId(['institucion_id' => ContextoInstitucion::requerido(), 'aula_banco_id' => $bancoId,
                'area_curricular_id' => $banco->area_curricular_id, 'grado_id' => $info->grado_id, 'tipo' => $s['tipo'], 'enunciado' => mb_substr(trim($s['enunciado']), 0, 2000),
                'opciones' => isset($s['opciones']) ? json_encode($s['opciones'], JSON_UNESCAPED_UNICODE) : null, 'respuesta_correcta' => json_encode($s['respuesta_correcta'], JSON_UNESCAPED_UNICODE),
                'retroalimentacion' => $s['retroalimentacion'] ?? null, 'unidad' => mb_substr(trim($tema), 0, 60), 'generada_ia' => true, 'activa' => false]);
        }

        return DB::table('aula_preguntas')->whereIn('id', $ids)->get()->map(fn ($p) => $this->pregunta($p, true))->all();
    }

    // ───────────── Cuestionarios (EDUN-128 / 138)

    /**
     * @param  array{aula_unidad_id: int, titulo: string, competencia_id?: int|null, abre_at?: string|null, cierra_at?: string|null, minutos?: int|null, intentos_max?: int, aula_banco_id?: int|null, preguntas_azar?: int|null, orden_aleatorio?: bool, nota_minima?: string|null, conserva?: string, mostrar_respuestas?: string, requiere_previos?: bool, preguntas?: list<array{id: int, puntaje?: float}>, publicar?: bool}  $d
     */
    public function guardarCuestionario(int $cursoId, ?int $id, array $d, User $usuario): array
    {
        $this->aula->exigirDocente($cursoId, $usuario);
        abort_unless(DB::table('aula_unidades')->where('id', $d['aula_unidad_id'])->where('aula_curso_id', $cursoId)->exists(), 404, 'La unidad no es de este curso.');
        $area = $this->areaDelCurso($cursoId);
        if (! empty($d['aula_banco_id']) && ! DB::table('aula_bancos')->where('id', $d['aula_banco_id'])->where('area_curricular_id', $area)->exists()) {
            throw ValidationException::withMessages(['aula_banco_id' => ['Ese banco no es del área del curso.']]);
        }
        $preguntas = collect($d['preguntas'] ?? []);
        if ($preguntas->isNotEmpty() && DB::table('aula_preguntas')->whereIn('id', $preguntas->pluck('id'))->where('area_curricular_id', $area)->where('activa', true)->count() !== $preguntas->pluck('id')->unique()->count()) {
            throw ValidationException::withMessages(['preguntas' => ['Usa preguntas activas del área del curso (las de IA, revisadas).']]);
        }
        if ($preguntas->isEmpty() && (empty($d['aula_banco_id']) || empty($d['preguntas_azar']))) {
            throw ValidationException::withMessages(['preguntas' => ['Elige las preguntas o un banco con la cantidad al azar.']]);
        }
        if (! empty($d['abre_at']) && ! empty($d['cierra_at']) && Carbon::parse($d['cierra_at'])->lessThanOrEqualTo(Carbon::parse($d['abre_at']))) {
            throw ValidationException::withMessages(['cierra_at' => ['El cierre debe ser después de la apertura.']]);
        }
        $fila = array_intersect_key($d, array_flip(['aula_unidad_id', 'titulo', 'competencia_id', 'abre_at', 'cierra_at', 'minutos', 'intentos_max', 'aula_banco_id', 'preguntas_azar',
            'orden_aleatorio', 'nota_minima', 'conserva', 'mostrar_respuestas', 'requiere_previos']));

        return DB::transaction(function () use ($cursoId, $id, $fila, $preguntas, $d, $usuario) {
            if ($id) {
                $this->cuestionarioDelCurso($cursoId, $id);
                if (DB::table('aula_intentos')->where('aula_cuestionario_id', $id)->exists() && $preguntas->isNotEmpty()) {
                    throw ValidationException::withMessages(['preguntas' => ['Ya hay intentos: no se cambian las preguntas (sí las fechas y los intentos).']]);
                }
                DB::table('aula_cuestionarios')->where('id', $id)->update($fila);
            } else {
                $id = (int) DB::table('aula_cuestionarios')->insertGetId(['institucion_id' => ContextoInstitucion::requerido(), 'creado_por' => $usuario->id] + $fila);
            }
            if ($preguntas->isNotEmpty()) {
                DB::table('aula_cuestionario_preguntas')->where('aula_cuestionario_id', $id)->delete();
                foreach ($preguntas->values() as $i => $p) {
                    DB::table('aula_cuestionario_preguntas')->insert(['institucion_id' => ContextoInstitucion::requerido(), 'aula_cuestionario_id' => $id, 'aula_pregunta_id' => $p['id'], 'orden' => $i, 'puntaje' => $p['puntaje'] ?? 1]);
                }
            }
            if (! empty($d['publicar'])) {
                $this->publicarCuestionario($cursoId, $id, $usuario);
            }

            return $this->detalleCuestionario($cursoId, $id, $usuario);
        });
    }

    public function publicarCuestionario(int $cursoId, int $id, User $usuario): void
    {
        $this->aula->exigirDocente($cursoId, $usuario);
        $q = $this->cuestionarioDelCurso($cursoId, $id);
        if ($q->publicado_at) {
            return;
        }
        DB::table('aula_cuestionarios')->where('id', $id)->update(['publicado_at' => now()]);
        $curso = DB::table('aula_cursos')->where('id', $cursoId)->value('titulo');
        $this->notificador->enviar($this->aula->usuariosDelCurso($cursoId, false), ['app'], 'tareas', "Nuevo cuestionario: {$q->titulo}", $curso, ['pantalla' => 'aula', 'id' => $cursoId]);
    }

    public function detalleCuestionario(int $cursoId, int $id, User $usuario): array
    {
        $rol = $this->aula->rol($cursoId, $usuario);
        $q = $this->cuestionarioDelCurso($cursoId, $id);
        $r = (array) $q;
        if ($rol === Aula::ROL_DOCENTE) {
            $r['preguntas'] = DB::table('aula_cuestionario_preguntas as cp')->join('aula_preguntas as p', 'p.id', '=', 'cp.aula_pregunta_id')->where('cp.aula_cuestionario_id', $id)
                ->orderBy('cp.orden')->get(['p.*', 'cp.puntaje'])->map(fn ($p) => array_merge($this->pregunta($p, true), ['puntaje' => (float) $p->puntaje]))->all();
            $r['resultados'] = $this->resultados($cursoId, $id);
        } elseif ($rol === Aula::ROL_ALUMNO) {
            $alumno = $this->aula->alumnoDe($cursoId, $usuario);
            $r['mis_intentos'] = $this->intentosAlumno($id, $alumno);
            $r['intentos_disponibles'] = $this->disponibles($q, $alumno);
            $r['nota_final'] = $this->notaFinal($q, $alumno);
        }

        return $r;
    }

    public function otorgarIntento(int $cursoId, int $id, int $alumnoId, int $cantidad, ?string $motivo, User $usuario): void
    {
        $this->aula->exigirDocente($cursoId, $usuario);
        $this->cuestionarioDelCurso($cursoId, $id);
        abort_unless($this->aula->alumnosDelCurso($cursoId)->contains('id', $alumnoId), 404);
        DB::table('aula_intentos_extra')->insert(['institucion_id' => ContextoInstitucion::requerido(), 'aula_cuestionario_id' => $id, 'alumno_id' => $alumnoId,
            'cantidad' => $cantidad, 'otorgado_por' => $usuario->id, 'motivo' => $motivo]);
    }

    // ───────────── Rendir (EDUN-130)

    /** Inicia (o retoma) un intento: devuelve las preguntas sin las respuestas. */
    public function iniciar(int $cursoId, int $id, User $usuario): array
    {
        $alumno = $this->aula->alumnoDe($cursoId, $usuario);
        $q = $this->cuestionarioDelCurso($cursoId, $id);
        abort_if(! $q->publicado_at, 404);
        $abierto = DB::table('aula_intentos')->where('aula_cuestionario_id', $id)->where('alumno_id', $alumno)->whereNull('enviado_at')->first();
        if (! $abierto) {
            if ($q->abre_at && now()->lessThan(Carbon::parse($q->abre_at))) {
                throw ValidationException::withMessages(['cuestionario' => ['El cuestionario todavía no abre.']]);
            }
            if ($q->cierra_at && now()->greaterThan(Carbon::parse($q->cierra_at))) {
                throw ValidationException::withMessages(['cuestionario' => ['El cuestionario ya cerró.']]);
            }
            if ($q->requiere_previos && $this->unidadIncompleta($cursoId, (int) $q->aula_unidad_id, $alumno)) {
                throw ValidationException::withMessages(['cuestionario' => ['Completa primero los materiales de la unidad.']]);
            }
            if ($this->disponibles($q, $alumno) <= 0) {
                throw ValidationException::withMessages(['cuestionario' => ['Ya usaste todos tus intentos. Si necesitas otro, pídelo a tu docente.']]);
            }
            $ids = $this->elegirPreguntas($q);
            if (! $ids) {
                throw ValidationException::withMessages(['cuestionario' => ['El cuestionario no tiene preguntas activas.']]);
            }
            $numero = (int) DB::table('aula_intentos')->where('aula_cuestionario_id', $id)->where('alumno_id', $alumno)->max('numero') + 1;
            DB::table('aula_intentos')->insert(['institucion_id' => ContextoInstitucion::requerido(), 'aula_cuestionario_id' => $id, 'alumno_id' => $alumno, 'numero' => $numero,
                'respuestas' => json_encode(['_preguntas' => $ids])]);
            $abierto = DB::table('aula_intentos')->where('aula_cuestionario_id', $id)->where('alumno_id', $alumno)->where('numero', $numero)->first();
            $this->aula->registrarAcceso($cursoId, $usuario->id, 'cuestionario', $id);
        }
        $ids = json_decode($abierto->respuestas, true)['_preguntas'] ?? [];
        $preguntas = DB::table('aula_preguntas')->whereIn('id', $ids)->get()->keyBy('id');

        return [
            'intento_id' => (int) $abierto->id, 'numero' => (int) $abierto->numero, 'iniciado_at' => $abierto->iniciado_at,
            'termina_at' => $q->minutos ? Carbon::parse($abierto->iniciado_at)->addMinutes((int) $q->minutos)->toIso8601String() : null,
            'preguntas' => collect($ids)->map(fn ($i) => isset($preguntas[$i]) ? $this->pregunta($preguntas[$i], false) : null)->filter()->values()->all(),
        ];
    }

    /** Envía el intento: corrige solo y devuelve el resultado (y las respuestas, si corresponde). */
    public function enviar(int $cursoId, int $id, array $respuestas, User $usuario): array
    {
        $alumno = $this->aula->alumnoDe($cursoId, $usuario);
        $q = $this->cuestionarioDelCurso($cursoId, $id);
        $intento = DB::table('aula_intentos')->where('aula_cuestionario_id', $id)->where('alumno_id', $alumno)->whereNull('enviado_at')->lockForUpdate()->first();
        if (! $intento) {
            throw ValidationException::withMessages(['cuestionario' => ['No tienes un intento abierto.']]);
        }
        $ids = json_decode($intento->respuestas, true)['_preguntas'] ?? [];
        $puntajes = DB::table('aula_cuestionario_preguntas')->where('aula_cuestionario_id', $id)->pluck('puntaje', 'aula_pregunta_id');
        $preguntas = DB::table('aula_preguntas')->whereIn('id', $ids)->get()->keyBy('id');
        $total = 0.0;
        $obtenido = 0.0;
        $detalle = [];
        foreach ($ids as $pid) {
            $p = $preguntas[$pid] ?? null;
            if (! $p) {
                continue;
            }
            $valor = (float) ($puntajes[$pid] ?? 1);
            $total += $valor;
            $ok = self::corregir($p->tipo, json_decode((string) $p->respuesta_correcta, true), $respuestas[$pid] ?? ($respuestas[(string) $pid] ?? null));
            $obtenido += $ok ? $valor : 0;
            $detalle[$pid] = $ok;
        }
        $pct = $total > 0 ? round($obtenido * 100 / $total, 1) : 0;
        $nota = Tareas::notaDesdePorcentaje($pct, $this->tareas->escala($cursoId));
        DB::table('aula_intentos')->where('id', $intento->id)->update([
            'enviado_at' => now(), 'puntaje' => $pct, 'nota' => $nota,
            'respuestas' => json_encode(['_preguntas' => $ids, 'valores' => $respuestas, 'correctas' => $detalle], JSON_UNESCAPED_UNICODE),
        ]);
        $mostrar = $q->mostrar_respuestas === 'al_terminar' || ($q->mostrar_respuestas === 'al_cerrar' && $q->cierra_at && now()->greaterThan(Carbon::parse($q->cierra_at)));

        return [
            'porcentaje' => $pct, 'nota' => $nota, 'aprobado' => $q->nota_minima ? $this->alcanza($nota, $q->nota_minima) : null,
            'intentos_disponibles' => $this->disponibles($q, $alumno), 'nota_final' => $this->notaFinal($q, $alumno),
            'revision' => $mostrar ? collect($ids)->map(fn ($pid) => isset($preguntas[$pid]) ? [
                'id' => (int) $pid, 'correcta' => $detalle[$pid] ?? false, 'respuesta_correcta' => json_decode((string) $preguntas[$pid]->respuesta_correcta, true),
                'retroalimentacion' => $preguntas[$pid]->retroalimentacion,
            ] : null)->filter()->values()->all() : null,
        ];
    }

    /** ¿La respuesta es correcta? Relacionar: solo si todos los pares coinciden. */
    public static function corregir(string $tipo, mixed $correcta, mixed $dada): bool
    {
        $normal = fn ($t) => preg_replace('/\s+/', ' ', trim(mb_strtolower(strtr((string) $t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u']))));

        return match ($tipo) {
            'opcion_multiple' => $dada !== null && (int) $dada === (int) $correcta,
            'verdadero_falso' => $dada !== null && filter_var($dada, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === (bool) $correcta,
            'relacionar' => is_array($dada) && is_array($correcta) && count($correcta) > 0
                && collect($correcta)->every(fn ($der, $izq) => isset($dada[$izq]) && (int) $dada[$izq] === (int) $der),
            'respuesta_corta' => $dada !== null && trim((string) $dada) !== '' && in_array($normal($dada), array_map($normal, (array) $correcta), true),
            default => false,
        };
    }

    // ───────────── Encuesta de la unidad (EDUN-139)

    public function responderEncuesta(int $cursoId, int $unidadId, int $valor, ?string $comentario, User $usuario): void
    {
        $alumno = $this->aula->alumnoDe($cursoId, $usuario);
        abort_unless(DB::table('aula_cursos')->where('id', $cursoId)->value('encuesta_activa'), 404);
        abort_unless(DB::table('aula_unidades')->where('id', $unidadId)->where('aula_curso_id', $cursoId)->exists(), 404);
        if (DB::table('aula_encuestas')->where('aula_unidad_id', $unidadId)->where('alumno_id', $alumno)->exists()) {
            throw ValidationException::withMessages(['encuesta' => ['Ya respondiste la encuesta de esta unidad.']]);
        }
        DB::table('aula_encuestas')->insert(['institucion_id' => ContextoInstitucion::requerido(), 'aula_unidad_id' => $unidadId, 'alumno_id' => $alumno, 'valor' => $valor,
            'comentario' => $comentario ? mb_substr(trim($comentario), 0, 300) : null]);
    }

    /** El docente ve el promedio, la cantidad y los comentarios (sin nombres: es anónima). */
    public function resumenEncuestas(int $cursoId, User $usuario): array
    {
        $this->aula->exigirDocente($cursoId, $usuario);

        return DB::table('aula_unidades as u')->leftJoin('aula_encuestas as e', 'e.aula_unidad_id', '=', 'u.id')->where('u.aula_curso_id', $cursoId)
            ->groupBy('u.id', 'u.titulo', 'u.orden')->orderBy('u.orden')
            ->get(['u.id', 'u.titulo', DB::raw('count(e.id) as respuestas'), DB::raw('round(avg(e.valor)::numeric, 2) as promedio'),
                DB::raw("coalesce(json_agg(e.comentario) filter (where e.comentario is not null), '[]') as comentarios")])
            ->map(fn ($u) => ['unidad_id' => (int) $u->id, 'unidad' => $u->titulo, 'respuestas' => (int) $u->respuestas, 'promedio' => $u->promedio !== null ? (float) $u->promedio : null,
                'comentarios' => json_decode((string) $u->comentarios, true)])->all();
    }

    // ───────────── Apoyo

    public function resultados(int $cursoId, int $id): array
    {
        $q = $this->cuestionarioDelCurso($cursoId, $id);

        return $this->aula->alumnosDelCurso($cursoId)->map(fn ($a) => [
            'alumno_id' => (int) $a->id, 'alumno' => $a->nombre, 'intentos' => DB::table('aula_intentos')->where('aula_cuestionario_id', $id)->where('alumno_id', $a->id)->whereNotNull('enviado_at')->count(),
            'nota_final' => $this->notaFinal($q, (int) $a->id), 'extra' => (int) DB::table('aula_intentos_extra')->where('aula_cuestionario_id', $id)->where('alumno_id', $a->id)->sum('cantidad'),
        ])->values()->all();
    }

    /** Nota que se conserva según la configuración (mejor, último o promedio). */
    public function notaFinal(object $q, int $alumno): ?array
    {
        $intentos = DB::table('aula_intentos')->where('aula_cuestionario_id', $q->id)->where('alumno_id', $alumno)->whereNotNull('enviado_at')->orderBy('numero')->pluck('puntaje')->map(fn ($p) => (float) $p);
        if ($intentos->isEmpty()) {
            return null;
        }
        $pct = match ($q->conserva) {
            'ultimo' => $intentos->last(),
            'promedio' => round($intentos->avg(), 1),
            default => $intentos->max(),
        };

        return ['porcentaje' => $pct, 'nota' => Tareas::notaDesdePorcentaje($pct, $this->tareas->escala((int) DB::table('aula_unidades')->where('id', $q->aula_unidad_id)->value('aula_curso_id')))];
    }

    private function intentosAlumno(int $id, int $alumno): array
    {
        return DB::table('aula_intentos')->where('aula_cuestionario_id', $id)->where('alumno_id', $alumno)->orderBy('numero')
            ->get(['numero', 'iniciado_at', 'enviado_at', 'puntaje', 'nota'])->map(fn ($i) => array_merge((array) $i, ['puntaje' => $i->puntaje !== null ? (float) $i->puntaje : null]))->all();
    }

    private function disponibles(object $q, int $alumno): int
    {
        $usados = DB::table('aula_intentos')->where('aula_cuestionario_id', $q->id)->where('alumno_id', $alumno)->whereNotNull('enviado_at')->count();
        $extra = (int) DB::table('aula_intentos_extra')->where('aula_cuestionario_id', $q->id)->where('alumno_id', $alumno)->sum('cantidad');

        return max(0, (int) $q->intentos_max + $extra - $usados);
    }

    /** @return list<int> */
    private function elegirPreguntas(object $q): array
    {
        $fijas = DB::table('aula_cuestionario_preguntas as cp')->join('aula_preguntas as p', 'p.id', '=', 'cp.aula_pregunta_id')->where('cp.aula_cuestionario_id', $q->id)->where('p.activa', true)
            ->orderBy('cp.orden')->pluck('p.id')->map(fn ($i) => (int) $i);
        if ($fijas->isEmpty() && $q->aula_banco_id && $q->preguntas_azar) {
            $fijas = DB::table('aula_preguntas')->where('aula_banco_id', $q->aula_banco_id)->where('activa', true)
                ->where(fn ($w) => $w->where('generada_ia', false)->orWhereNotNull('revisada_por'))->inRandomOrder()->limit((int) $q->preguntas_azar)->pluck('id')->map(fn ($i) => (int) $i);
        }

        return ($q->orden_aleatorio ? $fijas->shuffle() : $fijas)->values()->all();
    }

    private function unidadIncompleta(int $cursoId, int $unidadId, int $alumno): bool
    {
        $recursos = DB::table('aula_recursos')->where('aula_unidad_id', $unidadId)->where('visible', true)->pluck('id');
        $previas = DB::table('aula_unidades')->where('aula_curso_id', $cursoId)->where('orden', '<', DB::table('aula_unidades')->where('id', $unidadId)->value('orden'))->pluck('id');
        if (DB::table('aula_cursos')->where('id', $cursoId)->value('secuencial')) {
            $recursos = $recursos->merge(DB::table('aula_recursos')->whereIn('aula_unidad_id', $previas)->where('visible', true)->pluck('id'));
        }
        $hechos = DB::table('aula_progreso')->where('alumno_id', $alumno)->whereIn('aula_recurso_id', $recursos)->whereNotNull('completado_at')->count();

        return $hechos < $recursos->count();
    }

    private function alcanza(string $nota, string $minima): bool
    {
        $orden = ['C' => 1, 'B' => 2, 'A' => 3, 'AD' => 4];

        return isset($orden[$nota], $orden[$minima]) ? $orden[$nota] >= $orden[$minima] : (int) $nota >= (int) $minima;
    }

    private function validarPregunta(string $tipo, mixed $opciones, mixed $correcta): void
    {
        $error = fn (string $m) => throw ValidationException::withMessages(['respuesta_correcta' => [$m]]);
        match ($tipo) {
            'opcion_multiple' => ! is_array($opciones) || count($opciones) < 2 || ! is_numeric($correcta) || ! isset($opciones[(int) $correcta])
                ? $error('Escribe al menos dos opciones y marca la correcta.') : null,
            'verdadero_falso' => ! is_bool($correcta) && ! in_array($correcta, [0, 1, '0', '1', 'true', 'false'], true) ? $error('Marca si es verdadero o falso.') : null,
            'relacionar' => ! is_array($opciones) || count($opciones['izquierda'] ?? []) < 2 || count($opciones['derecha'] ?? []) < 2 || ! is_array($correcta)
                || count($correcta) !== count($opciones['izquierda']) ? $error('Relaciona cada elemento de la izquierda con uno de la derecha.') : null,
            'respuesta_corta' => ! is_array($correcta) || ! array_filter(array_map('trim', array_map('strval', $correcta))) ? $error('Escribe al menos una respuesta aceptada.') : null,
            default => $error('Tipo de pregunta no válido.'),
        };
    }

    private function pregunta(object $p, bool $conRespuesta): array
    {
        $r = ['id' => (int) $p->id, 'tipo' => $p->tipo, 'enunciado' => $p->enunciado, 'opciones' => $p->opciones ? json_decode((string) $p->opciones, true) : null];
        if ($conRespuesta) {
            $r += ['respuesta_correcta' => json_decode((string) $p->respuesta_correcta, true), 'retroalimentacion' => $p->retroalimentacion, 'unidad' => $p->unidad,
                'activa' => (bool) $p->activa, 'generada_ia' => (bool) $p->generada_ia, 'revisada' => $p->revisada_por !== null];
        }

        return $r;
    }

    private function areaDelCurso(int $cursoId): int
    {
        return (int) DB::table('aula_cursos as ac')->join('cargas_docentes as c', 'c.id', '=', 'ac.carga_docente_id')->where('ac.id', $cursoId)->value('c.area_curricular_id');
    }

    private function bancoDelCurso(int $cursoId, int $bancoId, User $usuario): object
    {
        $this->aula->exigirDocente($cursoId, $usuario);
        $b = DB::table('aula_bancos')->where('id', $bancoId)->where('area_curricular_id', $this->areaDelCurso($cursoId))
            ->where(fn ($q) => $q->where('alcance', 'area')->orWhere('aula_curso_id', $cursoId))->first();
        abort_if(! $b, 404, 'El banco no existe.');

        return $b;
    }

    private function cuestionarioDelCurso(int $cursoId, int $id): object
    {
        $q = DB::table('aula_cuestionarios as q')->join('aula_unidades as u', 'u.id', '=', 'q.aula_unidad_id')->where('q.id', $id)->where('u.aula_curso_id', $cursoId)->first(['q.*']);
        abort_if(! $q, 404, 'El cuestionario no existe.');

        return $q;
    }
}
