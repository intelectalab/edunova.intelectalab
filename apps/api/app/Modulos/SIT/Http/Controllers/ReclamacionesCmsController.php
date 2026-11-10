<?php

namespace App\Modulos\SIT\Http\Controllers;

use App\Modulos\SIT\Services\GestionReclamaciones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** EDUN-302 · /api/v1/cms/reclamaciones: lista, ficha, respuesta y adjuntos. */
class ReclamacionesCmsController
{
    public function __construct(private GestionReclamaciones $gestion) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'estado' => ['nullable', Rule::in(array_keys(GestionReclamaciones::ESTADOS))],
            'tipo' => ['nullable', Rule::in(['queja', 'reclamo'])],
            'plazo' => ['nullable', Rule::in(['vencidos', 'por_vencer'])],
            'q' => ['nullable', 'string', 'max:100'],
            'pagina' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        return response()->json($this->gestion->listar($f))->header('Cache-Control', 'no-store');
    }

    public function show(int $reclamacion): JsonResponse
    {
        return response()->json(['data' => $this->gestion->detalle($reclamacion)])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, int $reclamacion): JsonResponse
    {
        $d = $request->validate([
            'estado' => ['nullable', Rule::in(array_keys(GestionReclamaciones::ESTADOS))],
            'respuesta_cliente' => ['nullable', 'string', 'max:5000'],
            'notas_internas' => ['nullable', 'string', 'max:5000'],
        ], [
            'estado.*' => 'Elige un estado válido.',
            'respuesta_cliente.max' => 'La respuesta admite hasta 5000 caracteres.',
            'notas_internas.max' => 'Las notas admiten hasta 5000 caracteres.',
        ]);

        $respondido = ($d['estado'] ?? null) === 'respondido';

        return response()->json([
            'data' => $this->gestion->actualizar($reclamacion, $d, $request->user(), $request->ip()),
            'message' => $respondido ? 'Respuesta enviada al cliente.' : 'Expediente actualizado.',
        ]);
    }

    public function adjunto(int $reclamacion, int $indice): StreamedResponse
    {
        return $this->gestion->adjunto($reclamacion, $indice);
    }
}
