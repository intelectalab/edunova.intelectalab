<?php

namespace App\Modulos\SIT\Http\Controllers;

use App\Modulos\SIT\Services\EditorCms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** EDUN-233 · /api/v1/cms/secciones/{clave}, /cms/publicar y /cms/estado. */
class EditorCmsController
{
    public function __construct(private EditorCms $editor) {}

    public function mostrar(string $clave): JsonResponse
    {
        return response()->json(['data' => $this->editor->vista($clave)]);
    }

    public function guardar(Request $request, string $clave): JsonResponse
    {
        $this->editor->guardarBorrador($clave, (array) $request->input('contenido', []), $request->user(), $request->ip());

        return response()->json(['data' => $this->editor->vista($clave), 'message' => 'Borrador guardado.']);
    }

    public function publicar(Request $request): JsonResponse
    {
        $datos = $request->validate(['claves' => ['required', 'array', 'min:1'], 'claves.*' => ['string', 'max:40']], [
            'claves.required' => 'Indica qué secciones publicar.',
        ]);
        $publicadas = $this->editor->publicar($datos['claves'], $request->user(), $request->ip());

        return response()->json([
            'publicadas' => $publicadas,
            'message' => $publicadas === [] ? 'No había cambios por publicar.' : 'Cambios publicados.',
        ] + $this->editor->estado());
    }

    public function estado(): JsonResponse
    {
        return response()->json($this->editor->estado());
    }
}
