<?php

namespace App\Modulos\SIT\Http\Controllers;

use App\Modulos\SIT\Services\BandejaLeads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** EDUN-278 · /api/v1/cms/leads: bandeja de solicitudes y cambio de estado. */
class LeadsCmsController
{
    public function __construct(private BandejaLeads $bandeja) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'estado' => ['nullable', Rule::in(array_keys(BandejaLeads::ESTADOS))],
            'plan' => ['nullable', 'string', 'max:20'],
            'ciudad' => ['nullable', 'string', 'max:80'],
            'q' => ['nullable', 'string', 'max:100'],
            'pagina' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        return response()->json($this->bandeja->listar($f))->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, int $lead): JsonResponse
    {
        $d = $request->validate(
            ['estado' => ['required', Rule::in(array_keys(BandejaLeads::ESTADOS))]],
            ['estado.*' => 'Elige un estado válido.'],
        );

        return response()->json(['data' => $this->bandeja->cambiarEstado($lead, $d['estado'], $request->user(), $request->ip()), 'message' => 'Estado actualizado.']);
    }
}
