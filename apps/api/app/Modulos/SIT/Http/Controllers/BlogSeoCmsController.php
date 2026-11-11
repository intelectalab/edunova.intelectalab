<?php

namespace App\Modulos\SIT\Http\Controllers;

use App\Modulos\SIT\Services\AsistenteSeo;
use App\Modulos\SIT\Services\BlogCms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sprint 23 · Panel CMS:
 *   GET  /cms/blog, GET|PATCH /cms/blog/{slug}, POST /cms/blog/sincronizar   EDUN-368 (SIT-023)
 *   GET|PUT /cms/seo-asistente, POST /cms/seo/{slug}/sugerencias|analisis   EDUN-412 (SIT-030)
 * Solo el superadmin del SaaS (middleware «cms»).
 */
class BlogSeoCmsController
{
    public function __construct(private BlogCms $blog, private AsistenteSeo $asistente) {}

    public function blog(): JsonResponse
    {
        return response()->json($this->blog->listado())->header('Cache-Control', 'no-store');
    }

    public function vistaPrevia(string $slug): JsonResponse
    {
        return response()->json(['data' => $this->blog->vistaPrevia($slug)])->header('Cache-Control', 'no-store');
    }

    public function sincronizarBlog(Request $request): JsonResponse
    {
        return response()->json([...$this->blog->sincronizar($request->user(), $request->ip()), 'message' => 'Blog sincronizado con WordPress.']);
    }

    public function visibilidad(Request $request, string $slug): JsonResponse
    {
        $d = $request->validate(['oculto' => ['required', 'boolean']]);
        $vista = $this->blog->visibilidad($slug, (bool) $d['oculto'], $request->user(), $request->ip());

        return response()->json(['data' => $vista, 'message' => $vista['oculto'] ? 'El artículo ya no se muestra en el sitio.' : 'El artículo se muestra en el sitio.']);
    }

    public function configuracionAsistente(): JsonResponse
    {
        return response()->json(['data' => $this->asistente->configuracion()])->header('Cache-Control', 'no-store');
    }

    public function guardarAsistente(Request $request): JsonResponse
    {
        $d = $request->validate([
            'proveedor' => ['required', Rule::in(array_keys(AsistenteSeo::PROVEEDORES))],
            'modelo' => ['nullable', Rule::in(AsistenteSeo::MODELOS)],
        ]);

        return response()->json(['data' => $this->asistente->guardarConfiguracion($d, $request->user(), $request->ip()), 'message' => 'Asistente SEO actualizado.']);
    }

    public function sugerir(Request $request, string $slug): JsonResponse
    {
        $d = $request->validate(['contexto' => ['nullable', 'string', 'max:2000']], ['contexto.max' => 'El contexto admite hasta 2000 caracteres.']);

        return response()->json(['data' => $this->asistente->sugerir($slug, $d['contexto'] ?? null, $request->user())]);
    }

    public function analizar(Request $request, string $slug): JsonResponse
    {
        $d = $request->validate(['meta_titulo' => ['present', 'nullable', 'string', 'max:200'], 'meta_descripcion' => ['present', 'nullable', 'string', 'max:400']]);

        return response()->json(['data' => $this->asistente->analizar($slug, (string) $d['meta_titulo'], (string) $d['meta_descripcion'])]);
    }
}
