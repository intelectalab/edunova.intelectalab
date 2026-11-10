<?php

namespace App\Modulos\PLA\Http\Controllers;

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\PLA\Services\MesaDeAyuda;
use App\Modulos\SEG\Services\AlmacenArchivos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Mesa de ayuda.
 * - Consola (/plataforma/tickets, EDUN-29/31/32): todos los tickets, respuestas internas, estado,
 *   prioridad, asignación y cumplimiento del SLA.
 * - Colegio (/soporte/tickets, EDUN-33): crear con categoría, prioridad sugerida y adjuntos, ver el
 *   plazo comprometido y el avance (sin notas internas), responder y calificar al cerrar.
 */
class SoporteController
{
    // ───── Consola

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'estado' => ['nullable', Rule::in([...array_keys(MesaDeAyuda::ESTADOS), 'activos'])],
            'prioridad' => ['nullable', Rule::in(['critica', 'alta', 'media', 'baja'])],
            'institucion_id' => ['nullable', 'integer'],
        ]);
        $mesa = MesaDeAyuda::plataforma();

        return response()->json([
            'data' => $mesa->listar($f + ['estado' => $f['estado'] ?? 'activos']),
            'staff' => DB::connection('plataforma')->table('roles_plataforma as rp')->join('users as u', 'u.id', '=', 'rp.user_id')->where('rp.activo', true)
                ->distinct()->get(['u.id', DB::raw("u.nombres || ' ' || u.apellidos as nombre")]),
        ]);
    }

    public function show(int $ticket): JsonResponse
    {
        return response()->json(MesaDeAyuda::plataforma()->detalle($ticket, verInternos: true));
    }

    public function responder(Request $request, int $ticket): JsonResponse
    {
        $d = $request->validate(['mensaje' => ['required', 'string', 'max:5000'], 'interno' => ['nullable', 'boolean']], ['mensaje.required' => 'Escribe la respuesta.']);
        $mesa = MesaDeAyuda::plataforma();
        $mesa->responder($ticket, $request->user()->id, $d['mensaje'], (bool) ($d['interno'] ?? false), esStaff: true);

        return response()->json($mesa->detalle($ticket, verInternos: true));
    }

    public function actualizar(Request $request, int $ticket): JsonResponse
    {
        $d = $request->validate([
            'estado' => ['required', Rule::in(array_keys(MesaDeAyuda::ESTADOS))],
            'prioridad' => ['nullable', Rule::in(['critica', 'alta', 'media', 'baja'])],
            'asignado_a' => ['nullable', 'integer'],
        ]);
        $mesa = MesaDeAyuda::plataforma();
        $mesa->cambiarEstado($ticket, $d['estado'], array_key_exists('asignado_a', $d) ? (int) $d['asignado_a'] : null, $d['prioridad'] ?? null);

        return response()->json($mesa->detalle($ticket, verInternos: true));
    }

    public function cumplimiento(Request $request): JsonResponse
    {
        $d = $request->validate(['desde' => ['nullable', 'date_format:Y-m-d'], 'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde']]);
        $desde = $d['desde'] ?? now()->subMonths(5)->startOfMonth()->toDateString();
        $hasta = $d['hasta'] ?? now()->toDateString();

        return response()->json(['desde' => $desde, 'hasta' => $hasta] + MesaDeAyuda::plataforma()->cumplimiento($desde, $hasta)
            + ['politicas' => DB::connection('plataforma')->table('sla_politicas')->orderBy('horas_primera_respuesta')->get()]);
    }

    // ───── Colegio

    public function misTickets(): JsonResponse
    {
        return response()->json(['data' => (new MesaDeAyuda)->listar(['estado' => null]), 'categorias' => MesaDeAyuda::CATEGORIAS,
            'politicas' => DB::table('sla_politicas')->orderBy('horas_primera_respuesta')->get(['prioridad', 'horas_primera_respuesta', 'horas_resolucion', 'descripcion'])]);
    }

    public function crear(Request $request, AlmacenArchivos $almacen): JsonResponse
    {
        $d = $request->validate([
            'asunto' => ['required', 'string', 'max:200'],
            'descripcion' => ['required', 'string', 'max:5000'],
            'categoria' => ['required', Rule::in(array_keys(MesaDeAyuda::CATEGORIAS))],
            'prioridad' => ['required', Rule::in(['critica', 'alta', 'media', 'baja'])],
            'adjuntos' => ['nullable', 'array', 'max:5'],
            'adjuntos.*' => ['file', 'max:10240', 'extensions:pdf,jpg,jpeg,png,webp,xlsx,xls,csv,docx,txt'],
        ], ['asunto.required' => 'Escribe el asunto.', 'descripcion.required' => 'Cuéntanos qué pasa.', 'adjuntos.*.extensions' => 'Adjunta imágenes, PDF u hojas de cálculo.']);
        $id = (new MesaDeAyuda)->crear(ContextoInstitucion::requerido(), $d, $request->user()->id);
        foreach ($request->file('adjuntos', []) as $archivo) {
            $a = $almacen->guardar($archivo, $request->user()->id);
            DB::table('ticket_adjuntos')->insert(['institucion_id' => ContextoInstitucion::requerido(), 'ticket_id' => $id, 'archivo_id' => $a->id]);
        }

        return response()->json((new MesaDeAyuda)->detalle($id, verInternos: false), 201);
    }

    public function miTicket(int $ticket): JsonResponse
    {
        return response()->json((new MesaDeAyuda)->detalle($ticket, verInternos: false));
    }

    public function responderColegio(Request $request, int $ticket): JsonResponse
    {
        $d = $request->validate(['mensaje' => ['required', 'string', 'max:5000']], ['mensaje.required' => 'Escribe tu respuesta.']);
        $mesa = new MesaDeAyuda;
        $mesa->responder($ticket, $request->user()->id, $d['mensaje'], false, esStaff: false);

        return response()->json($mesa->detalle($ticket, verInternos: false));
    }

    public function calificar(Request $request, int $ticket): JsonResponse
    {
        $d = $request->validate(['calificacion' => ['required', 'integer', 'between:1,5'], 'comentario' => ['nullable', 'string', 'max:500']]);
        $mesa = new MesaDeAyuda;
        $mesa->calificar($ticket, (int) $d['calificacion'], $d['comentario'] ?? null);

        return response()->json($mesa->detalle($ticket, verInternos: false));
    }
}
