<?php

namespace App\Modulos\SIT\Http\Controllers;

use App\Modulos\SIT\Services\BibliotecaMedios;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** EDUN-237 · /api/v1/cms/medios: listar, subir y cambiar el texto alternativo. */
class MediosCmsController
{
    public function __construct(private BibliotecaMedios $biblioteca) {}

    public function index(Request $request): JsonResponse
    {
        $tipo = $request->query('tipo');
        $medios = DB::table('cms.medios')
            ->when(in_array($tipo, ['imagen', 'svg', 'pdf'], true), fn ($q) => $q->where('tipo', $tipo))
            ->when($tipo === 'imagenes', fn ($q) => $q->whereIn('tipo', ['imagen', 'svg']))
            ->orderByDesc('created_at')->limit(100)
            ->get(['id', 'tipo', 'nombre', 'url', 'mime', 'bytes', 'ancho', 'alto', 'alt', 'created_at']);

        return response()->json(['data' => $medios]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'archivo' => ['required', 'file'],
            'alt' => ['nullable', 'string', 'max:250'],
        ], ['archivo.required' => 'Elige un archivo.', 'archivo.file' => 'Elige un archivo.']);

        $medio = $this->biblioteca->subir($request->file('archivo'), $request->input('alt'), $request->user(), $request->ip());

        return response()->json(['data' => $medio, 'message' => 'Archivo subido.'], 201);
    }

    public function update(Request $request, int $medio): JsonResponse
    {
        $datos = $request->validate(['alt' => ['required', 'string', 'max:250']], ['alt.required' => 'Escribe el texto alternativo.']);

        return response()->json(['data' => $this->biblioteca->cambiarAlt($medio, $datos['alt'], $request->user(), $request->ip())]);
    }
}
