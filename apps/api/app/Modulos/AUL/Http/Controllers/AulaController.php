<?php

namespace App\Modulos\AUL\Http\Controllers;

use App\Modulos\AUL\Services\Aula;
use App\Modulos\AUL\Services\Comunidad;
use App\Modulos\AUL\Services\Evaluaciones;
use App\Modulos\AUL\Services\GeneradorPreguntas;
use App\Modulos\AUL\Services\Tareas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * S27–S29 · Aula virtual a medida (EDUN-123 a 142). El acceso a cada curso lo decide Aula::rol():
 * docente de la carga, alumno de la sección, familia o seguimiento (tutoría y coordinación).
 */
class AulaController
{
    public function __construct(private Aula $aula, private Tareas $tareas, private Evaluaciones $evaluaciones, private Comunidad $comunidad) {}

    // ─── Cursos, unidades y materiales

    public function cursos(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->aula->misCursos($request->user())]);
    }

    public function curso(Request $request, int $curso): JsonResponse
    {
        $r = $this->aula->curso($curso, $request->user());
        if ($r['rol'] === Aula::ROL_ALUMNO) {
            $this->aula->registrarAcceso($curso, (int) $request->user()->id, 'curso', null);
        }
        if ($r['rol'] === Aula::ROL_DOCENTE) {
            $r['competencias'] = DB::table('competencias')->where('area_curricular_id', $r['area_curricular_id'])->where('vigente', true)->orderBy('orden')->get(['id', 'nombre']);
        }

        return response()->json($r);
    }

    public function configurar(Request $request, int $curso): JsonResponse
    {
        $d = $request->validate([
            'titulo' => ['sometimes', 'string', 'min:3', 'max:150'], 'visible' => ['sometimes', 'boolean'], 'secuencial' => ['sometimes', 'boolean'],
            'video_sin_adelantar' => ['sometimes', 'boolean'], 'video_pct_completado' => ['sometimes', 'integer', 'between:10,100'],
            'encuesta_activa' => ['sometimes', 'boolean'], 'avance_visible_familia' => ['sometimes', 'boolean'], 'pdf_descargable' => ['sometimes', 'boolean'],
        ]);

        return response()->json($this->aula->configurar($curso, $d, $request->user()));
    }

    public function guardarUnidad(Request $request, int $curso, ?int $unidad = null): JsonResponse
    {
        $d = $request->validate(['titulo' => ['required', 'string', 'min:2', 'max:150'], 'orden' => ['nullable', 'integer', 'min:0', 'max:500'], 'visible_desde' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json($this->aula->guardarUnidad($curso, $unidad, $d, $request->user()), $unidad ? 200 : 201);
    }

    public function quitarUnidad(Request $request, int $curso, int $unidad): JsonResponse
    {
        $this->aula->quitarUnidad($curso, $unidad, $request->user());

        return response()->json(['ok' => true]);
    }

    public function guardarRecurso(Request $request, int $curso, int $unidad, ?int $recurso = null): JsonResponse
    {
        $d = $request->validate([
            'tipo' => ['required', Rule::in(['archivo', 'pdf', 'video', 'enlace', 'texto'])],
            'titulo' => ['required', 'string', 'min:2', 'max:150'],
            'url' => ['nullable', 'url:https', 'max:500'],
            'contenido' => ['nullable', 'string', 'max:20000'],
            'duracion_seg' => ['nullable', 'integer', 'min:1'],
            'visible' => ['boolean'],
            'orden' => ['nullable', 'integer', 'min:0', 'max:500'],
            'archivo' => ['nullable', 'file', 'max:102400', 'mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,jpg,jpeg,png,mp4,webm,mp3,zip'],
        ], ['url.url' => 'El enlace debe empezar con https://', 'archivo.max' => 'El material puede pesar hasta 100 MB.']);

        return response()->json($this->aula->guardarRecurso($curso, $unidad, $recurso, $d, $request->file('archivo'), $request->user()), $recurso ? 200 : 201);
    }

    public function quitarRecurso(Request $request, int $curso, int $recurso): JsonResponse
    {
        $this->aula->quitarRecurso($curso, $recurso, $request->user());

        return response()->json(['ok' => true]);
    }

    public function archivoRecurso(Request $request, int $curso, int $recurso): JsonResponse
    {
        return response()->json(['url' => $this->aula->urlRecurso($curso, $recurso, $request->user())]);
    }

    public function avanzar(Request $request, int $curso, int $recurso): JsonResponse
    {
        $d = $request->validate(['porcentaje' => ['required', 'integer', 'between:0,100'], 'segundos' => ['nullable', 'integer', 'min:0']]);

        return response()->json($this->aula->avanzar($curso, $recurso, (int) $d['porcentaje'], (int) ($d['segundos'] ?? 0), $request->user()));
    }

    public function aviso(Request $request, int $curso): JsonResponse
    {
        $d = $request->validate(['texto' => ['required', 'string', 'min:3', 'max:2000']]);

        return response()->json($this->aula->publicarAviso($curso, $d['texto'], $request->user()), 201);
    }

    public function quitarAviso(Request $request, int $curso, int $aviso): JsonResponse
    {
        $this->aula->quitarAviso($curso, $aviso, $request->user());

        return response()->json(['ok' => true]);
    }

    // ─── Tareas

    public function guardarTarea(Request $request, int $curso, ?int $tarea = null): JsonResponse
    {
        $d = $request->validate([
            'aula_unidad_id' => ['required', 'integer'], 'titulo' => ['required', 'string', 'min:3', 'max:150'], 'instrucciones' => ['required', 'string', 'min:5', 'max:10000'],
            'vence_at' => ['required', 'date'], 'acepta_tardias' => ['boolean'], 'competencia_id' => ['nullable', 'integer'], 'publicar' => ['boolean'],
            'archivo' => ['nullable', 'file', 'max:20480'],
        ]);

        return response()->json($this->tareas->guardar($curso, $tarea, $d, $request->file('archivo'), $request->user()), $tarea ? 200 : 201);
    }

    public function publicarTarea(Request $request, int $curso, int $tarea): JsonResponse
    {
        $this->tareas->publicar($curso, $tarea, $request->user());

        return response()->json(['ok' => true]);
    }

    public function quitarTarea(Request $request, int $curso, int $tarea): JsonResponse
    {
        $this->tareas->quitar($curso, $tarea, $request->user());

        return response()->json(['ok' => true]);
    }

    public function entregar(Request $request, int $curso, int $tarea): JsonResponse
    {
        $request->validate(['texto' => ['nullable', 'string', 'max:20000'], 'archivo' => ['nullable', 'file', 'max:20480', 'mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,jpg,jpeg,png,webp,heic,mp4,zip']],
            ['archivo.max' => 'El archivo puede pesar hasta 20 MB.']);

        return response()->json($this->tareas->entregar($curso, $tarea, $request->input('texto'), $request->file('archivo'), $request->user()));
    }

    public function entregas(Request $request, int $curso, int $tarea): JsonResponse
    {
        return response()->json($this->tareas->entregas($curso, $tarea, $request->user()));
    }

    public function revisar(Request $request, int $curso, int $tarea, int $alumno): JsonResponse
    {
        $d = $request->validate(['nota' => ['required', 'string', 'max:2'], 'comentario' => ['nullable', 'string', 'max:2000']]);

        return response()->json($this->tareas->revisar($curso, $tarea, $alumno, $d['nota'], $d['comentario'] ?? null, $request->user()));
    }

    public function archivoEntrega(Request $request, int $curso, int $tarea, int $alumno): JsonResponse
    {
        return response()->json(['url' => $this->tareas->urlArchivoEntrega($curso, $tarea, $alumno, $request->user())]);
    }

    public function archivoTarea(Request $request, int $curso, int $tarea): JsonResponse
    {
        return response()->json(['url' => $this->tareas->urlArchivoTarea($curso, $tarea, $request->user())]);
    }

    public function tareasHijos(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->tareas->deHijos($request->user())]);
    }

    // ─── Bancos, preguntas y cuestionarios

    public function bancos(Request $request, int $curso): JsonResponse
    {
        return response()->json(['data' => $this->evaluaciones->bancos($curso, $request->user()), 'ia' => GeneradorPreguntas::proveedor()]);
    }

    public function crearBanco(Request $request, int $curso): JsonResponse
    {
        $d = $request->validate(['nombre' => ['required', 'string', 'min:3', 'max:150'], 'alcance' => ['required', Rule::in(['area', 'curso'])]]);

        return response()->json($this->evaluaciones->crearBanco($curso, $d['nombre'], $d['alcance'], $request->user()), 201);
    }

    public function preguntas(Request $request, int $curso, int $banco): JsonResponse
    {
        return response()->json(['data' => $this->evaluaciones->preguntas($curso, $banco, $request->user())]);
    }

    public function guardarPregunta(Request $request, int $curso, int $banco, ?int $pregunta = null): JsonResponse
    {
        $d = $request->validate([
            'tipo' => ['required', Rule::in(Evaluaciones::TIPOS)], 'enunciado' => ['required', 'string', 'min:3', 'max:2000'],
            'opciones' => ['nullable', 'array'], 'respuesta_correcta' => ['present'], 'retroalimentacion' => ['nullable', 'string', 'max:1000'],
            'unidad' => ['nullable', 'string', 'max:60'], 'activa' => ['boolean'],
        ]);

        return response()->json($this->evaluaciones->guardarPregunta($curso, $banco, $pregunta, $d, $request->user()), $pregunta ? 200 : 201);
    }

    public function revisarPregunta(Request $request, int $curso, int $banco, int $pregunta): JsonResponse
    {
        $d = $request->validate(['aprobar' => ['required', 'boolean']]);
        $this->evaluaciones->revisar($curso, $banco, $pregunta, (bool) $d['aprobar'], $request->user());

        return response()->json(['ok' => true]);
    }

    public function generarIa(Request $request, int $curso, int $banco, GeneradorPreguntas $generador): JsonResponse
    {
        $d = $request->validate(['tema' => ['required', 'string', 'min:3', 'max:200'], 'cantidad' => ['required', 'integer', 'between:1,10'], 'competencia_id' => ['nullable', 'integer']]);

        return response()->json(['data' => $this->evaluaciones->generarConIa($curso, $banco, $d['competencia_id'] ?? null, $d['tema'], (int) $d['cantidad'], $request->user(), $generador), 'proveedor' => GeneradorPreguntas::proveedor()]);
    }

    public function guardarCuestionario(Request $request, int $curso, ?int $cuestionario = null): JsonResponse
    {
        $d = $request->validate([
            'aula_unidad_id' => ['required', 'integer'], 'titulo' => ['required', 'string', 'min:3', 'max:150'], 'competencia_id' => ['nullable', 'integer'],
            'abre_at' => ['nullable', 'date'], 'cierra_at' => ['nullable', 'date'], 'minutos' => ['nullable', 'integer', 'between:1,300'],
            'intentos_max' => ['integer', 'between:1,10'], 'aula_banco_id' => ['nullable', 'integer'], 'preguntas_azar' => ['nullable', 'integer', 'between:1,100'],
            'orden_aleatorio' => ['boolean'], 'nota_minima' => ['nullable', 'string', 'max:2'], 'conserva' => [Rule::in(['mejor', 'ultimo', 'promedio'])],
            'mostrar_respuestas' => [Rule::in(['al_terminar', 'al_cerrar', 'nunca'])], 'requiere_previos' => ['boolean'],
            'preguntas' => ['array', 'max:100'], 'preguntas.*.id' => ['required', 'integer'], 'preguntas.*.puntaje' => ['nullable', 'numeric', 'between:0.5,20'], 'publicar' => ['boolean'],
        ]);

        return response()->json($this->evaluaciones->guardarCuestionario($curso, $cuestionario, $d, $request->user()), $cuestionario ? 200 : 201);
    }

    public function cuestionario(Request $request, int $curso, int $cuestionario): JsonResponse
    {
        return response()->json($this->evaluaciones->detalleCuestionario($curso, $cuestionario, $request->user()));
    }

    public function publicarCuestionario(Request $request, int $curso, int $cuestionario): JsonResponse
    {
        $this->evaluaciones->publicarCuestionario($curso, $cuestionario, $request->user());

        return response()->json(['ok' => true]);
    }

    public function intentoExtra(Request $request, int $curso, int $cuestionario): JsonResponse
    {
        $d = $request->validate(['alumno_id' => ['required', 'integer'], 'cantidad' => ['required', 'integer', 'between:1,5'], 'motivo' => ['nullable', 'string', 'max:200']]);
        $this->evaluaciones->otorgarIntento($curso, $cuestionario, (int) $d['alumno_id'], (int) $d['cantidad'], $d['motivo'] ?? null, $request->user());

        return response()->json(['ok' => true]);
    }

    public function iniciar(Request $request, int $curso, int $cuestionario): JsonResponse
    {
        return response()->json($this->evaluaciones->iniciar($curso, $cuestionario, $request->user()));
    }

    public function enviar(Request $request, int $curso, int $cuestionario): JsonResponse
    {
        $d = $request->validate(['respuestas' => ['present', 'array']]);

        return response()->json($this->evaluaciones->enviar($curso, $cuestionario, $d['respuestas'], $request->user()));
    }

    public function encuesta(Request $request, int $curso, int $unidad): JsonResponse
    {
        $d = $request->validate(['valor' => ['required', 'integer', 'between:1,4'], 'comentario' => ['nullable', 'string', 'max:300']]);
        $this->evaluaciones->responderEncuesta($curso, $unidad, (int) $d['valor'], $d['comentario'] ?? null, $request->user());

        return response()->json(['ok' => true]);
    }

    public function encuestas(Request $request, int $curso): JsonResponse
    {
        return response()->json(['data' => $this->evaluaciones->resumenEncuestas($curso, $request->user())]);
    }

    // ─── Foros, clases, observaciones y seguimiento

    public function foros(Request $request, int $curso): JsonResponse
    {
        return response()->json(['data' => $this->comunidad->foros($curso, $request->user())]);
    }

    public function crearForo(Request $request, int $curso): JsonResponse
    {
        $d = $request->validate(['titulo' => ['required', 'string', 'min:3', 'max:150']]);

        return response()->json($this->comunidad->crearForo($curso, $d['titulo'], $request->user()), 201);
    }

    public function mensajes(Request $request, int $curso, int $foro): JsonResponse
    {
        return response()->json(['data' => $this->comunidad->mensajes($curso, $foro, $request->user())]);
    }

    public function escribir(Request $request, int $curso, int $foro): JsonResponse
    {
        $d = $request->validate(['contenido' => ['required', 'string', 'min:1', 'max:5000'], 'respuesta_a' => ['nullable', 'integer']]);

        return response()->json($this->comunidad->escribir($curso, $foro, $d['contenido'], $d['respuesta_a'] ?? null, $request->user()), 201);
    }

    public function ocultarMensaje(Request $request, int $curso, int $foro, int $mensaje): JsonResponse
    {
        $d = $request->validate(['oculto' => ['required', 'boolean']]);
        $this->comunidad->ocultar($curso, $foro, $mensaje, (bool) $d['oculto'], $request->user());

        return response()->json(['ok' => true]);
    }

    public function clases(Request $request, int $curso): JsonResponse
    {
        return response()->json(['data' => $this->comunidad->clases($curso, $request->user())]);
    }

    public function guardarClase(Request $request, int $curso, ?int $clase = null): JsonResponse
    {
        $d = $request->validate([
            'titulo' => ['required', 'string', 'min:3', 'max:150'], 'inicio' => ['required', 'date'], 'plataforma' => ['required', Rule::in(Comunidad::PLATAFORMAS)],
            'url' => ['required', 'string', 'max:500'], 'grabacion_url' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json($this->comunidad->guardarClase($curso, $clase, $d, $request->user()), $clase ? 200 : 201);
    }

    public function observaciones(Request $request, int $curso): JsonResponse
    {
        $d = $request->validate(['alumno_id' => ['nullable', 'integer']]);

        return response()->json(['data' => $this->comunidad->observaciones($curso, $d['alumno_id'] ?? null, $request->user())]);
    }

    public function observar(Request $request, int $curso): JsonResponse
    {
        $d = $request->validate(['alumno_id' => ['required', 'integer'], 'tipo' => ['required', Rule::in(['aspecto_mejorar', 'fortaleza', 'comentario'])], 'texto' => ['required', 'string', 'min:3', 'max:2000']]);

        return response()->json($this->comunidad->observar($curso, (int) $d['alumno_id'], $d['tipo'], $d['texto'], $request->user()), 201);
    }

    public function seguimiento(Request $request, int $curso): JsonResponse
    {
        return response()->json($this->comunidad->seguimiento($curso, $request->user()));
    }

    public function avisarFamilia(Request $request, int $curso, int $alumno): JsonResponse
    {
        $d = $request->validate(['mensaje' => ['required', 'string', 'min:5', 'max:500']]);

        return response()->json(['enviados' => $this->comunidad->avisarFamilia($curso, $alumno, $d['mensaje'], $request->user())]);
    }
}
