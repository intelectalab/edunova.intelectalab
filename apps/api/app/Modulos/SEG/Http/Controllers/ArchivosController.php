<?php

namespace App\Modulos\SEG\Http\Controllers;

use App\Modulos\SEG\Models\Archivo;
use App\Modulos\SEG\Services\AlmacenArchivos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * EDUN-42 · SEG-H08 Archivos en el almacenamiento de objetos: subir y pedir un enlace de
 * descarga firmado que vence a los 5 minutos. Los confidenciales solo los descarga quien los
 * subió o quien tiene el permiso seg.archivos.confidenciales (ArchivoPolicy).
 */
class ArchivosController
{
    public const EXTENSIONES = 'pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip';

    public function __construct(private AlmacenArchivos $almacen) {}

    public function store(Request $request): JsonResponse
    {
        // El tamaño se valida antes de subir nada, con un mensaje claro (EDUN-207).
        if ($request->file('archivo')) {
            AlmacenArchivos::validarTamano((int) $request->file('archivo')->getSize());
        }
        $datos = $request->validate([
            'archivo' => ['required', 'file', 'extensions:'.self::EXTENSIONES],
            'confidencial' => ['nullable', 'boolean'],
        ], [
            'archivo.required' => 'Elige un archivo.',
            'archivo.extensions' => 'Ese tipo de archivo no está permitido.',
        ]);
        $archivo = $this->almacen->guardar($datos['archivo'], $request->user()->id, (bool) ($datos['confidencial'] ?? false));

        return response()->json(self::datos($archivo), 201);
    }

    public function enlace(int $archivo): JsonResponse
    {
        // Se busca dentro del colegio activo (el RLS ya está fijado por el middleware).
        $archivo = Archivo::query()->findOrFail($archivo);
        Gate::authorize('descargar', $archivo);

        return response()->json([
            'url' => $this->almacen->urlTemporal($archivo),
            'vence_en_segundos' => (int) config('edunova.archivos.url_minutos') * 60,
        ]);
    }

    public static function datos(Archivo $a): array
    {
        return [
            'id' => $a->id, 'nombre' => $a->nombre_original, 'mime' => $a->mime, 'bytes' => $a->bytes,
            'confidencial' => $a->confidencial, 'creado' => $a->created_at?->toIso8601String(),
        ];
    }
}
