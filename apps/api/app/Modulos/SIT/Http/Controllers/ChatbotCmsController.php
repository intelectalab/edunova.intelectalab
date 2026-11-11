<?php

namespace App\Modulos\SIT\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * EDUN-380 / SIT-028 · Historial de sesiones del chatbot en el Panel CMS:
 *   GET /cms/chatbot/sesiones        filtros: desde, hasta, resultado, lead (si|no), q, pagina
 *   GET /cms/chatbot/sesiones/{id}   conversación completa y contacto (si lo dejó)
 * Solo lectura; solo el superadmin del SaaS (middleware «cms» y RLS de cms.chatbot_sesiones).
 */
class ChatbotCmsController
{
    public const POR_PAGINA = 25;

    public const RESULTADOS = ['captura' => 'Dejó su contacto', 'whatsapp' => 'Pasó a WhatsApp', 'url' => 'Abrió un enlace', 'fin' => 'Llegó al final', 'abandono' => 'Cerró a medias'];

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'resultado' => ['nullable', Rule::in(array_keys(self::RESULTADOS))],
            'lead' => ['nullable', Rule::in(['si', 'no'])],
            'q' => ['nullable', 'string', 'max:100'],
            'pagina' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);
        $zona = "(s.created_at at time zone 'America/Lima')::date";
        $q = DB::table('cms.chatbot_sesiones as s')
            ->when($f['desde'] ?? null, fn ($q, $v) => $q->whereRaw("$zona >= ?", [$v]))
            ->when($f['hasta'] ?? null, fn ($q, $v) => $q->whereRaw("$zona <= ?", [$v]))
            ->when($f['resultado'] ?? null, fn ($q, $v) => $q->where('s.resultado', $v))
            ->when(($f['lead'] ?? null) === 'si', fn ($q) => $q->where('s.lead_capturado', true))
            ->when(($f['lead'] ?? null) === 'no', fn ($q) => $q->where('s.lead_capturado', false))
            ->when(isset($f['q']) && trim($f['q']) !== '', fn ($q) => $q->whereExists(fn ($m) => $m->from('cms.chatbot_mensajes as m')->whereColumn('m.sesion_id', 's.id')
                ->whereRaw('m.contenido ilike ?', ['%'.addcslashes(trim($f['q']), '%_\\').'%'])));
        $total = (clone $q)->count();
        $pagina = (int) ($f['pagina'] ?? 1);
        $porResultado = (clone $q)->selectRaw("coalesce(s.resultado, 'abandono') as r, count(*) as n")->groupBy('r')->pluck('n', 'r');

        return response()->json([
            'data' => $q->orderByDesc('s.created_at')->orderByDesc('s.id')->offset(($pagina - 1) * self::POR_PAGINA)->limit(self::POR_PAGINA)
                ->get(['s.id', 's.created_at', 's.resultado', 's.lead_capturado', 's.lead_id', 's.pagina', 's.nodos_visitados'])
                ->map(fn ($s) => [
                    'id' => $s->id, 'created_at' => $s->created_at, 'resultado' => $s->resultado ?? 'abandono', 'lead_capturado' => (bool) $s->lead_capturado,
                    'lead_id' => $s->lead_id, 'pagina' => $s->pagina, 'pasos' => count(json_decode((string) $s->nodos_visitados, true) ?: []),
                    'ultimo' => collect(json_decode((string) $s->nodos_visitados, true) ?: [])->last(),
                ]),
            'total' => $total,
            'pagina' => $pagina,
            'paginas' => max(1, (int) ceil($total / self::POR_PAGINA)),
            'por_resultado' => collect(self::RESULTADOS)->map(fn ($_, $k) => (int) ($porResultado[$k] ?? 0)),
            'resultados' => self::RESULTADOS,
        ])->header('Cache-Control', 'no-store');
    }

    public function show(int $sesion): JsonResponse
    {
        $s = DB::table('cms.chatbot_sesiones as s')->leftJoin('cms.leads as l', 'l.id', '=', 's.lead_id')->where('s.id', $sesion)
            ->first(['s.id', 's.created_at', 's.resultado', 's.lead_capturado', 's.pagina', 's.lead_id', 'l.nombre as lead_nombre', 'l.email as lead_email', 'l.telefono as lead_telefono', 'l.estado as lead_estado'])
            ?? abort(404, 'No encontramos esa sesión.');

        return response()->json(['data' => [
            ...(array) $s, 'resultado' => $s->resultado ?? 'abandono', 'lead_capturado' => (bool) $s->lead_capturado,
            'mensajes' => DB::table('cms.chatbot_mensajes')->where('sesion_id', $sesion)->orderBy('id')->get(['rol', 'contenido', 'created_at']),
        ]])->header('Cache-Control', 'no-store');
    }
}
