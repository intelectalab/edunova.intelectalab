<?php

namespace App\Modulos\SIT\Http\Controllers;

use App\Modulos\SIT\Services\BandejaLeads;
use App\Modulos\SIT\Services\EnvioCrm;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * EDUN-278 · /api/v1/cms/leads: bandeja de solicitudes y cambio de estado.
 * EDUN-282 · /api/v1/cms/leads/exportar (CSV) y /api/v1/cms/leads/sincronizar (reenvío al CRM).
 */
class LeadsCmsController
{
    public function __construct(private BandejaLeads $bandeja, private EnvioCrm $crm) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->bandeja->listar($this->filtros($request)))->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, int $lead): JsonResponse
    {
        $d = $request->validate(
            ['estado' => ['required', Rule::in(array_keys(BandejaLeads::ESTADOS))]],
            ['estado.*' => 'Elige un estado válido.'],
        );

        return response()->json(['data' => $this->bandeja->cambiarEstado($lead, $d['estado'], $request->user(), $request->ip()), 'message' => 'Estado actualizado.']);
    }

    public function exportar(Request $request): Response
    {
        $f = $this->filtros($request, conFechas: true);
        $nombre = 'solicitudes-edunova-'.CarbonImmutable::now('America/Lima')->format('Y-m-d').'.csv';

        return response($this->bandeja->csv($f), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$nombre}\"",
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function sincronizar(Request $request): JsonResponse
    {
        $d = $request->validate(['ids' => ['nullable', 'array', 'max:500'], 'ids.*' => ['integer', 'min:1']]);
        if (! $this->crm->configurado()) {
            return response()->json(['message' => 'Aún no hay un CRM configurado. Los leads quedan guardados y se enviarán cuando se configure.'], 409);
        }

        $n = $this->crm->sincronizar(isset($d['ids']) ? array_values(array_map('intval', $d['ids'])) : null, $request->user(), $request->ip());

        return response()->json([
            'encolados' => $n,
            'message' => match (true) {
                $n === 0 => 'No hay solicitudes pendientes de enviar al CRM.',
                $n === 1 => '1 solicitud en camino al CRM.',
                default => "{$n} solicitudes en camino al CRM.",
            },
        ]);
    }

    private function filtros(Request $request, bool $conFechas = false): array
    {
        return $request->validate([
            'estado' => ['nullable', Rule::in(array_keys(BandejaLeads::ESTADOS))],
            'plan' => ['nullable', 'string', 'max:20'],
            'ciudad' => ['nullable', 'string', 'max:80'],
            'q' => ['nullable', 'string', 'max:100'],
            'pagina' => ['nullable', 'integer', 'min:1', 'max:10000'],
            ...($conFechas ? [
                'desde' => ['nullable', 'date_format:Y-m-d'],
                'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            ] : []),
        ], ['hasta.after_or_equal' => 'La fecha final debe ser igual o posterior a la inicial.']);
    }
}
