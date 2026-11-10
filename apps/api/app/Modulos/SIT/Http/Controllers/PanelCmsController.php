<?php

namespace App\Modulos\SIT\Http\Controllers;

use App\Modulos\SIT\Services\AuditoriaCms;
use App\Modulos\SIT\Services\EditorCms;
use App\Modulos\SIT\Services\Integraciones;
use App\Modulos\SIT\Services\PanelCms;
use App\Modulos\SIT\Services\SeoYPoliticasCms;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Sprint 10 · Panel CMS:
 *   GET  /cms/dashboard                         EDUN-383
 *   GET  /cms/auditoria, /cms/auditoria/exportar EDUN-342
 *   POST /cms/auditoria/{id}/restaurar          EDUN-342 (deja un borrador, no publica)
 *   GET  /cms/usuarios                          EDUN-407 (solo lectura)
 *   GET  /cms/seo, GET|PUT /cms/seo/{slug}      EDUN-314
 *   GET  /cms/politicas, POST /cms/politicas/{tipo}  EDUN-395
 *   GET|PUT /cms/integraciones                  EDUN-403
 * La auditoría no tiene rutas de edición ni de borrado (y la base la hace inmutable).
 */
class PanelCmsController
{
    public function __construct(
        private PanelCms $panel,
        private AuditoriaCms $auditoria,
        private EditorCms $editor,
        private SeoYPoliticasCms $seo,
        private Integraciones $integraciones,
    ) {}

    public function integraciones(): JsonResponse
    {
        return response()->json(['data' => $this->integraciones->vista()])->header('Cache-Control', 'no-store');
    }

    public function guardarIntegraciones(Request $request): JsonResponse
    {
        $d = $request->validate(Integraciones::reglas(), Integraciones::mensajes());

        return response()->json(['data' => $this->integraciones->guardar($d, $request->user(), $request->ip()), 'message' => 'Integraciones actualizadas.']);
    }

    public function dashboard(): JsonResponse
    {
        return response()->json($this->panel->dashboard())->header('Cache-Control', 'no-store');
    }

    public function auditoria(Request $request): JsonResponse
    {
        return response()->json($this->auditoria->listar($this->filtrosAuditoria($request)))->header('Cache-Control', 'no-store');
    }

    public function exportarAuditoria(Request $request): Response
    {
        $nombre = 'auditoria-cms-'.CarbonImmutable::now('America/Lima')->format('Y-m-d').'.csv';

        return response($this->auditoria->csv($this->filtrosAuditoria($request)), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$nombre}\"",
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function restaurar(Request $request, int $registro): JsonResponse
    {
        $vista = $this->editor->restaurar($registro, $request->user(), $request->ip());

        return response()->json(['data' => $vista, 'message' => 'Valor anterior restaurado como borrador. Revísalo y publícalo cuando esté listo.']);
    }

    public function usuarios(): JsonResponse
    {
        return response()->json(['data' => $this->panel->usuarios()])->header('Cache-Control', 'no-store');
    }

    public function paginasSeo(): JsonResponse
    {
        return response()->json(['data' => $this->seo->paginasSeo()]);
    }

    public function seo(string $slug): JsonResponse
    {
        return response()->json(['data' => $this->seo->seo($slug)]);
    }

    public function guardarSeo(Request $request, string $slug): JsonResponse
    {
        $d = $request->validate(SeoYPoliticasCms::reglasSeo(), [
            'meta_titulo.max' => 'El título admite hasta 70 caracteres.',
            'meta_descripcion.max' => 'La meta descripción admite hasta 160 caracteres.',
            'required' => 'Este campo es obligatorio.',
            'canonical_url.url' => 'Escribe una dirección https completa.',
            'og_titulo.max' => 'Máximo 90 caracteres.',
            'og_descripcion.max' => 'Máximo 200 caracteres.',
        ]);

        return response()->json(['data' => $this->seo->guardarSeo($slug, $d, $request->user(), $request->ip()), 'message' => 'Metadatos actualizados.']);
    }

    public function politicas(): JsonResponse
    {
        return response()->json(['data' => $this->seo->politicas()])->header('Cache-Control', 'no-store');
    }

    public function publicarPolitica(Request $request, string $tipo): JsonResponse
    {
        $d = $request->validate(SeoYPoliticasCms::reglasPolitica(), [
            'version.regex' => 'Usa un número de versión como 1.1 o 2.0.',
            'vigente_desde.after_or_equal' => 'La vigencia empieza hoy o en una fecha futura.',
            'contenido.min' => 'El texto de la política es demasiado corto (mínimo 200 caracteres).',
            'required' => 'Este campo es obligatorio.',
        ]);

        return response()->json(['data' => $this->seo->publicarPolitica($tipo, $d, $request->user(), $request->ip()), 'message' => 'Versión publicada.'], 201);
    }

    private function filtrosAuditoria(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'usuario' => ['nullable', 'integer', 'min:1'],
            'seccion' => ['nullable', 'string', 'max:40'],
            'accion' => ['nullable', Rule::in(array_keys(AuditoriaCms::ACCIONES))],
            'pagina' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);
    }
}
