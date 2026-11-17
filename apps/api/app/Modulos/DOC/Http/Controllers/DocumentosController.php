<?php

namespace App\Modulos\DOC\Http\Controllers;

use App\Modulos\DOC\Services\Autorizaciones;
use App\Modulos\DOC\Services\Expedientes;
use App\Modulos\DOC\Services\Institucionales;
use App\Modulos\SEG\Http\Controllers\FamiliaController;
use App\Modulos\SEG\Services\AlcanceAcademico;
use App\Modulos\SEG\Services\Autorizacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * S26 · Expedientes (EDUN-119, 120), documentos institucionales (EDUN-121) y autorizaciones
 * firmadas por los padres (EDUN-122).
 */
class DocumentosController
{
    public function __construct(private Expedientes $expedientes, private Institucionales $institucionales, private Autorizaciones $autorizaciones) {}

    // ─── Expedientes

    public function expediente(Request $request, string $tipo, int $titular, Autorizacion $permisos): JsonResponse
    {
        $this->puedeVerPersonal($request, $tipo, $permisos);

        return response()->json(['data' => $this->expedientes->lista($tipo, $titular), 'categorias' => Expedientes::CATEGORIAS[$tipo]]);
    }

    public function subir(Request $request, string $tipo, int $titular, Autorizacion $permisos): JsonResponse
    {
        $this->puedeVerPersonal($request, $tipo, $permisos);
        $d = $request->validate([
            'categoria' => ['required', 'string', 'max:30'],
            'nombre' => ['nullable', 'string', 'max:150'],
            'vence' => ['nullable', 'date_format:Y-m-d'],
            'archivo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ], ['archivo.mimes' => 'Sube un PDF o una foto.', 'archivo.max' => 'El archivo puede pesar hasta 10 MB.']);

        return response()->json($this->expedientes->subir($tipo, $titular, $d['categoria'], $d['nombre'] ?? null, $request->file('archivo'), $d['vence'] ?? null, (int) $request->user()->id), 201);
    }

    /** El documento del expediente del personal solo lo maneja quien ve al personal (dirección). */
    private function exigirTitular(Request $request, int $documento, Autorizacion $permisos): void
    {
        $tipo = DB::table('expediente_documentos')->where('id', $documento)->value('titular_tipo');
        abort_if(! $tipo, 404);
        $this->puedeVerPersonal($request, (string) $tipo, $permisos);
    }

    public function actualizarDocumento(Request $request, int $documento, Autorizacion $permisos): JsonResponse
    {
        $this->exigirTitular($request, $documento, $permisos);
        $d = $request->validate(['vence' => ['nullable', 'date_format:Y-m-d'], 'verificar' => ['boolean']]);

        return response()->json($this->expedientes->actualizar($documento, $d['vence'] ?? null, (bool) ($d['verificar'] ?? false), (int) $request->user()->id));
    }

    public function quitarDocumento(Request $request, int $documento, Autorizacion $permisos): JsonResponse
    {
        $this->exigirTitular($request, $documento, $permisos);
        $this->expedientes->quitar($documento);

        return response()->json(['ok' => true]);
    }

    public function archivoDocumento(Request $request, int $documento, Autorizacion $permisos): JsonResponse
    {
        $this->exigirTitular($request, $documento, $permisos);

        return response()->json(['url' => $this->expedientes->url($documento)]);
    }

    public function porVencer(Request $request, Autorizacion $permisos): JsonResponse
    {
        $d = $request->validate(['tipo' => ['nullable', Rule::in(['alumno', 'personal'])]]);
        $tipo = $d['tipo'] ?? null;
        if (! $permisos->tiene($request->user(), 'adm.personal.ver')) {
            $tipo = 'alumno';
        }

        return response()->json(['data' => $this->expedientes->porVencer($tipo)]);
    }

    // ─── Documentos institucionales

    public function institucionales(): JsonResponse
    {
        return response()->json(['data' => $this->institucionales->lista(), 'tipos' => Institucionales::TIPOS]);
    }

    public function publicar(Request $request): JsonResponse
    {
        $d = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(Institucionales::TIPOS))],
            'nombre' => ['required', 'string', 'min:3', 'max:150'],
            'version' => ['required', 'string', 'max:20'],
            'requiere_lectura' => ['boolean'],
            'archivo' => ['required', 'file', 'mimes:pdf', 'max:20480'],
        ], ['archivo.mimes' => 'Publica el documento en PDF.']);

        return response()->json($this->institucionales->publicar($d['tipo'], $d['nombre'], $d['version'], $request->file('archivo'), $request->boolean('requiere_lectura'), (int) $request->user()->id), 201);
    }

    public function lecturas(int $documento): JsonResponse
    {
        return response()->json(['data' => $this->institucionales->lecturas($documento)]);
    }

    public function recordarLectura(int $documento): JsonResponse
    {
        return response()->json(['enviados' => $this->institucionales->recordar($documento)]);
    }

    public function archivoInstitucional(int $documento): JsonResponse
    {
        return response()->json(['url' => $this->institucionales->url($documento)]);
    }

    public function institucionalesFamilia(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->institucionales->deFamilia((int) $request->user()->id)]);
    }

    public function leer(Request $request, int $documento): JsonResponse
    {
        $this->institucionales->marcarLeido($documento, (int) $request->user()->id);

        return response()->json(['ok' => true]);
    }

    public function archivoFamilia(int $documento): JsonResponse
    {
        return response()->json(['url' => $this->institucionales->url($documento, true)]);
    }

    // ─── Autorizaciones a padres

    public function autorizaciones(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->autorizaciones->lista($request->user())]);
    }

    public function crearAutorizacion(Request $request): JsonResponse
    {
        $d = $request->validate([
            'titulo' => ['required', 'string', 'min:5', 'max:150'],
            'contenido' => ['required', 'string', 'min:10', 'max:5000'],
            'secciones' => ['required', 'array', 'min:1', 'max:60'],
            'secciones.*' => ['integer'],
            'vence' => ['required', 'date_format:Y-m-d'],
        ], ['secciones.required' => 'Elige al menos una sección.']);

        return response()->json($this->autorizaciones->crear($d['titulo'], $d['contenido'], $d['secciones'], $d['vence'], $request->user()), 201);
    }

    public function seguimiento(Request $request, int $autorizacion): JsonResponse
    {
        $this->autorizaciones->exigirAutor($autorizacion, $request->user());

        return response()->json($this->autorizaciones->seguimiento($autorizacion));
    }

    public function recordarAutorizacion(Request $request, int $autorizacion): JsonResponse
    {
        $this->autorizaciones->exigirAutor($autorizacion, $request->user());

        return response()->json(['enviados' => $this->autorizaciones->recordar($autorizacion)]);
    }

    public function anularFirma(Request $request, int $autorizacion, int $alumno): JsonResponse
    {
        $this->autorizaciones->exigirAutor($autorizacion, $request->user(), true);

        $this->autorizaciones->anularFirma($autorizacion, $alumno);

        return response()->json(['ok' => true]);
    }

    public function autorizacionesFamilia(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->autorizaciones->deFamilia(FamiliaController::alumnosDe((int) $request->user()->id))]);
    }

    public function firmar(Request $request, int $autorizacion): JsonResponse
    {
        $d = $request->validate(['alumno_id' => ['required', 'integer'], 'acepta' => ['required', 'boolean']]);
        $this->autorizaciones->firmar($autorizacion, (int) $d['alumno_id'], (bool) $d['acepta'], (int) $request->user()->id, FamiliaController::alumnosDe((int) $request->user()->id), $request->ip());

        return response()->json(['ok' => true]);
    }

    /** Secciones que el usuario puede elegir al crear una autorización. */
    public function secciones(Request $request, AlcanceAcademico $alcance): JsonResponse
    {
        $q = DB::table('secciones as s')->join('grados as g', 'g.id', '=', 's.grado_id')->join('anios_escolares as y', 'y.id', '=', 's.anio_escolar_id')->where('y.estado', 'abierto');
        if (! $alcance->veTodoElColegio($request->user())) {
            $q->whereIn('s.id', $alcance->seccionesVisibles($request->user())->select('id'));
        }

        return response()->json(['data' => $q->orderBy('g.nivel')->orderBy('g.numero')->orderBy('s.nombre')->get(['s.id', DB::raw("g.nombre || ' ' || s.nombre as nombre")])]);
    }

    /** El expediente del personal es de dirección: además del permiso del módulo, exige ver al personal. */
    private function puedeVerPersonal(Request $request, string $tipo, Autorizacion $permisos): void
    {
        abort_unless(in_array($tipo, ['alumno', 'personal'], true), 404);
        abort_if($tipo === 'personal' && ! $permisos->tiene($request->user(), 'adm.personal.ver'), 403, 'El expediente del personal lo gestiona dirección.');
    }
}
