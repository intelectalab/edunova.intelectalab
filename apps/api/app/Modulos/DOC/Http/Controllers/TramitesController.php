<?php

namespace App\Modulos\DOC\Http\Controllers;

use App\Modulos\DOC\Services\Emision;
use App\Modulos\DOC\Services\Plantillas;
use App\Modulos\DOC\Services\Tramites;
use App\Modulos\SEG\Http\Controllers\FamiliaController;
use App\Modulos\SEG\Services\AlmacenArchivos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * S25 · Trámites en línea (EDUN-113 a 118).
 *
 *   GET|POST|PUT /tramites/catalogo           catálogo (director)
 *   GET|POST|PUT /documentos/plantillas       plantillas con variables y versiones
 *   GET  /tramites                            bandeja con semáforo · GET /tramites/{id} detalle
 *   POST /tramites/{id}/estado                atender, observar, pasar a firma o rechazar
 *   POST /tramites/firmar                     firma por lote · POST /tramites/{id}/entrega (con archivo)
 *   GET|POST /familia/tramites                la familia pide y sigue sus trámites
 *   GET  /verificar/{codigo}                  verificación pública (sin sesión)
 */
class TramitesController
{
    public function __construct(private Tramites $tramites, private Plantillas $plantillas, private Emision $emision) {}

    // ─── Catálogo

    public function catalogo(): JsonResponse
    {
        return response()->json([
            'data' => $this->tramites->catalogo(), 'conceptos' => $this->tramites->conceptos(),
            'plantillas' => DB::table('plantillas_documento')->where('activa', true)->orderBy('nombre')->get(['id', 'nombre', 'version']),
        ]);
    }

    public function guardarTipo(Request $request, ?int $tipo = null): JsonResponse
    {
        $d = $request->validate([
            'nombre' => ['required', 'string', 'min:3', 'max:120'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'concepto_cobro_id' => ['nullable', 'integer'],
            'plazo_dias' => ['required', 'integer', 'min:1', 'max:60'],
            'requiere_sin_deuda' => ['boolean'],
            'plantilla_documento_id' => ['nullable', 'integer'],
            'firma_cargo' => ['nullable', 'string', 'max:40'],
            'activo' => ['boolean'],
            'requisitos' => ['array', 'max:15'],
            'requisitos.*.id' => ['nullable', 'integer'],
            'requisitos.*.descripcion' => ['required', 'string', 'min:3', 'max:200'],
            'requisitos.*.obligatorio' => ['boolean'],
        ], ['plazo_dias.max' => 'El plazo puede ser de hasta 60 días hábiles.']);

        return response()->json($this->tramites->guardarTipo($tipo, $d), $tipo ? 200 : 201);
    }

    // ─── Plantillas

    public function plantillas(): JsonResponse
    {
        return response()->json(['data' => $this->plantillas->lista(), 'variables' => collect(Plantillas::VARIABLES)->map(fn ($d, $v) => ['variable' => '{'.$v.'}', 'descripcion' => $d])->values()]);
    }

    public function guardarPlantilla(Request $request, ?int $plantilla = null): JsonResponse
    {
        $d = $request->validate([
            'nombre' => ['required', 'string', 'min:3', 'max:120'],
            'tipo' => ['required', Rule::in(Plantillas::TIPOS)],
            'contenido_html' => ['required', 'string', 'min:10', 'max:60000'],
            'con_qr' => ['boolean'],
        ]);

        return response()->json($this->plantillas->guardar($plantilla, $d, (int) $request->user()->id), $plantilla ? 200 : 201);
    }

    public function restaurarPlantilla(Request $request, int $plantilla): JsonResponse
    {
        return response()->json($this->plantillas->restaurar($plantilla, (int) $request->user()->id));
    }

    public function quitarPlantilla(int $plantilla): JsonResponse
    {
        $this->plantillas->desactivar($plantilla);

        return response()->json(['ok' => true]);
    }

    public function versionPlantilla(int $plantilla): JsonResponse
    {
        $p = DB::table('plantillas_documento')->find($plantilla, ['id', 'nombre', 'tipo', 'contenido_html', 'con_qr', 'version', 'activa']);
        abort_if(! $p, 404);

        return response()->json($p);
    }

    /** Vista previa en PDF con los datos reales de un alumno. */
    public function vistaPrevia(Request $request, int $plantilla): Response
    {
        $d = $request->validate(['alumno_id' => ['required', 'integer']]);
        abort_if(! DB::table('alumnos')->where('id', $d['alumno_id'])->whereNull('eliminado_at')->exists(), 404, 'El alumno no existe.');

        return response($this->emision->vistaPrevia($plantilla, (int) $d['alumno_id'], (int) $request->user()->id), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="vista-previa.pdf"', 'Cache-Control' => 'no-store',
        ]);
    }

    /** Firma del usuario (imagen) que se imprime en los documentos que firma. */
    public function miFirma(Request $request, AlmacenArchivos $almacen): JsonResponse
    {
        $personal = DB::table('personal')->where('user_id', $request->user()->id)->first(['id', 'firma_archivo_id']);
        abort_if(! $personal, 422, 'Tu usuario no está en el personal del colegio.');
        if ($request->isMethod('get')) {
            return response()->json(['tiene_firma' => (bool) $personal->firma_archivo_id]);
        }
        $request->validate(['firma' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:1024']], ['firma.mimes' => 'Sube la firma como imagen PNG o JPG (mejor con fondo blanco o transparente).']);
        $a = $almacen->guardar($request->file('firma'), (int) $request->user()->id, true);
        DB::table('personal')->where('id', $personal->id)->update(['firma_archivo_id' => $a->id]);

        return response()->json(['tiene_firma' => true]);
    }

    // ─── Bandeja

    public function bandeja(Request $request): JsonResponse
    {
        $d = $request->validate(['estado' => ['nullable', Rule::in([...Tramites::ESTADOS, 'activos', 'todos'])], 'buscar' => ['nullable', 'string', 'max:80']]);

        return response()->json(['data' => $this->tramites->bandeja($d['estado'] ?? null, $d['buscar'] ?? null)]);
    }

    public function detalle(int $tramite): JsonResponse
    {
        return response()->json($this->tramites->detalle($tramite));
    }

    public function mover(Request $request, int $tramite): JsonResponse
    {
        $d = $request->validate(['accion' => ['required', Rule::in(['atender', 'observar', 'a_firma', 'rechazar'])], 'comentario' => ['nullable', 'string', 'max:500']]);

        return response()->json($this->tramites->mover($tramite, $d['accion'], $d['comentario'] ?? null, $request->user()));
    }

    public function firmar(Request $request): JsonResponse
    {
        $d = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:50'], 'ids.*' => ['integer']]);

        return response()->json(['resultados' => $this->tramites->firmarLote(array_map('intval', $d['ids']), $request->user())]);
    }

    public function entregar(Request $request, int $tramite): JsonResponse
    {
        $request->validate(['documento' => ['required', 'file', 'mimes:pdf', 'max:10240']], ['documento.mimes' => 'El documento debe ser un PDF.']);

        return response()->json($this->tramites->entregarArchivo($tramite, $request->file('documento'), $request->user()));
    }

    public function adjunto(int $tramite, int $adjunto): JsonResponse
    {
        return response()->json(['url' => $this->tramites->urlAdjunto($tramite, $adjunto)]);
    }

    public function documento(int $tramite): JsonResponse
    {
        return response()->json(['url' => $this->tramites->urlDocumento($tramite)]);
    }

    public function anularDocumento(Request $request, int $documento): JsonResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:300']], ['motivo.required' => 'Indica por qué se anula el documento.']);
        $this->emision->anular($documento, $d['motivo'], (int) $request->user()->id);

        return response()->json(['ok' => true]);
    }

    // ─── Familia

    public function tiposFamilia(): JsonResponse
    {
        return response()->json(['data' => collect($this->tramites->catalogo(true))->map(fn ($t) => [
            'id' => $t['id'], 'nombre' => $t['nombre'], 'descripcion' => $t['descripcion'], 'costo' => $t['costo'], 'plazo_dias' => $t['plazo_dias'],
            'requiere_sin_deuda' => (bool) $t['requiere_sin_deuda'], 'requisitos' => $t['requisitos'],
        ])->values()]);
    }

    public function misTramites(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->tramites->deFamiliaLista(FamiliaController::alumnosDe((int) $request->user()->id))]);
    }

    public function solicitar(Request $request): JsonResponse
    {
        $d = $request->validate([
            'tramite_tipo_id' => ['required', 'integer'],
            'alumno_id' => ['required', 'integer'],
            'comentario' => ['nullable', 'string', 'max:500'],
            'adjuntos' => ['array', 'max:15'],
            'adjuntos.*' => ['file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:5120'],
        ], ['adjuntos.*.mimes' => 'Los requisitos se adjuntan como foto o PDF.', 'adjuntos.*.max' => 'Cada archivo puede pesar hasta 5 MB.']);
        abort_unless(in_array((int) $d['alumno_id'], FamiliaController::alumnosDe((int) $request->user()->id), true), 403, 'Solo puedes pedir trámites de tus hijos.');

        return response()->json($this->tramites->solicitar((int) $d['tramite_tipo_id'], (int) $d['alumno_id'], $d['comentario'] ?? null, $this->adjuntos($request), $request->user()), 201);
    }

    public function responder(Request $request, int $tramite): JsonResponse
    {
        $request->validate([
            'comentario' => ['nullable', 'string', 'max:500'],
            'adjuntos' => ['array', 'max:15'],
            'adjuntos.*' => ['file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:5120'],
        ]);

        return response()->json($this->tramites->responder($tramite, $request->input('comentario'), $this->adjuntos($request), $request->user(), FamiliaController::alumnosDe((int) $request->user()->id)));
    }

    public function documentoFamilia(Request $request, int $tramite): JsonResponse
    {
        return response()->json(['url' => $this->tramites->documentoFamilia($tramite, FamiliaController::alumnosDe((int) $request->user()->id))]);
    }

    // ─── Público

    public function verificar(string $codigo): JsonResponse
    {
        $r = Emision::verificar($codigo);

        return $r ? response()->json($r) : response()->json(['message' => 'No encontramos un documento con ese código.'], 404);
    }

    /** @return array<int, UploadedFile> requisito_id (0 = adicional) => archivo */
    private function adjuntos(Request $request): array
    {
        $r = [];
        foreach ((array) $request->file('adjuntos', []) as $clave => $archivo) {
            if ($archivo) {
                $r[(int) $clave] = $archivo;
            }
        }

        return $r;
    }
}
