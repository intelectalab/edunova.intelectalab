<?php

namespace App\Modulos\SIT\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * EDUN-278 / SIT-026 · Bandeja de solicitudes del Panel CMS: indicadores, pestañas por estado,
 * filtros por plan, ciudad y texto, 10 filas por página y cambio de estado con auditoría.
 * Corre dentro de la transacción del middleware «cms» (app.rol_cms = superadmin).
 */
class BandejaLeads
{
    public const POR_PAGINA = 10;

    public const ESTADOS = ['nuevo' => 'Nuevo', 'contactado' => 'Contactado', 'en_proceso' => 'En proceso', 'ganado' => 'Ganado', 'perdido' => 'Perdido'];

    /** Estados que cuentan para el pipeline (aún pueden convertirse en clientes). */
    private const ABIERTOS = ['nuevo', 'contactado', 'en_proceso'];

    /** @param  array{estado?: ?string, plan?: ?string, ciudad?: ?string, q?: ?string, pagina?: int}  $f */
    public function listar(array $f): array
    {
        $pagina = max(1, (int) ($f['pagina'] ?? 1));

        $filtrada = $this->consulta($f);
        $porEstado = (clone $filtrada)->select('l.estado', DB::raw('count(*) as n'))->groupBy('l.estado')->pluck('n', 'estado');
        $contadores = ['todos' => (int) $porEstado->sum()] + collect(array_keys(self::ESTADOS))->mapWithKeys(fn ($e) => [$e => (int) ($porEstado[$e] ?? 0)])->all();

        $conEstado = (clone $filtrada)->when(! empty($f['estado']), fn ($q) => $q->where('l.estado', $f['estado']));
        $total = (clone $conEstado)->count();
        $filas = $conEstado
            ->orderByDesc('l.created_at')->orderByDesc('l.id')
            ->offset(($pagina - 1) * self::POR_PAGINA)->limit(self::POR_PAGINA)
            ->get([
                'l.id', 'l.nombre', 'l.cargo', 'l.email', 'l.telefono', 'l.colegio', 'l.ciudad', 'l.alumnos', 'l.plan_interes',
                'p.nombre as plan_nombre', 'p.precio_mensual', 'l.estado', 'l.source', 'l.demo_fecha', 'l.demo_hora', 'l.mensaje',
                'l.utm_source', 'l.utm_medium', 'l.utm_campaign', 'l.page_url', 'l.created_at', 'l.primer_contacto_en',
            ])
            ->map(fn ($l) => [...(array) $l, 'demo_hora' => $l->demo_hora ? substr($l->demo_hora, 0, 5) : null]);

        return [
            'data' => $filas,
            'pagina' => $pagina,
            'por_pagina' => self::POR_PAGINA,
            'total' => $total,
            'paginas' => max(1, (int) ceil($total / self::POR_PAGINA)),
            'contadores' => $contadores,
            'indicadores' => $this->indicadores(),
            'ciudades' => DB::table('cms.leads')->whereNotNull('ciudad')->where('ciudad', '<>', '')->distinct()->orderBy('ciudad')->pluck('ciudad'),
            'planes' => DB::table('cms.planes_sitio')->where('estado', 'publicado')->orderBy('orden')->get(['clave', 'nombre']),
        ];
    }

    /** Cambia el estado y deja la auditoría en la misma transacción. */
    public function cambiarEstado(int $id, string $estado, User $usuario, ?string $ip): object
    {
        $lead = DB::table('cms.leads')->where('id', $id)->lockForUpdate()->first() ?? abort(404, 'No encontramos esa solicitud.');
        if ($lead->estado === $estado) {
            return $this->ficha($id);
        }

        DB::table('cms.leads')->where('id', $id)->update(['estado' => $estado]);
        DB::table('cms.auditoria')->insert([
            'usuario_id' => $usuario->id,
            'usuario_rol' => 'superadmin',
            'seccion' => 'leads',
            'accion' => 'editado',
            'entidad' => 'leads',
            'entidad_id' => (string) $id,
            'campo' => 'estado',
            'detalle' => Str::limit(sprintf('Bandeja de solicitudes · %s: %s → %s', $lead->colegio ?? "solicitud {$id}", self::ESTADOS[$lead->estado], self::ESTADOS[$estado]), 250, ''),
            'valor_antes' => json_encode($lead->estado),
            'valor_despues' => json_encode($estado),
            'ip' => $ip,
        ]);

        return $this->ficha($id);
    }

    private function ficha(int $id): object
    {
        return DB::table('cms.leads')->where('id', $id)->first(['id', 'estado', 'primer_contacto_en']);
    }

    private function consulta(array $f): Builder
    {
        return DB::table('cms.leads as l')
            ->leftJoin('cms.planes_sitio as p', 'p.clave', '=', 'l.plan_interes')
            ->when(! empty($f['plan']), fn ($q) => $q->where('l.plan_interes', $f['plan']))
            ->when(! empty($f['ciudad']), fn ($q) => $q->where('l.ciudad', $f['ciudad']))
            ->when(! empty($f['q']), function ($q) use ($f) {
                $texto = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($f['q'])).'%';
                $q->where(fn ($w) => $w->where('l.colegio', 'ilike', $texto)->orWhere('l.nombre', 'ilike', $texto)
                    ->orWhere('l.email', 'ilike', $texto)->orWhere('l.telefono', 'ilike', $texto));
            });
    }

    /**
     * Total del mes (hora de Lima), tiempo de respuesta promedio (de la llegada al primer
     * cambio de estado, últimos 90 días), demos agendadas desde hoy y valor mensual estimado
     * del pipeline (precio publicado del plan de interés de las solicitudes abiertas).
     */
    private function indicadores(): array
    {
        $ahora = CarbonImmutable::now('America/Lima');
        $inicioMes = $ahora->startOfMonth();

        $respuesta = DB::table('cms.leads')->whereNotNull('primer_contacto_en')->where('created_at', '>=', $ahora->subDays(90))
            ->selectRaw('avg(extract(epoch from (primer_contacto_en - created_at))) / 3600 as horas')->value('horas');

        $pipeline = DB::table('cms.leads as l')->join('cms.planes_sitio as p', 'p.clave', '=', 'l.plan_interes')
            ->whereIn('l.estado', self::ABIERTOS)->whereNotNull('p.precio_mensual')
            ->selectRaw('coalesce(sum(p.precio_mensual), 0) as total, count(*) as n')->first();

        return [
            'total_mes' => DB::table('cms.leads')->where('created_at', '>=', $inicioMes)->where('created_at', '<', $inicioMes->addMonth())->count(),
            'sin_contactar' => DB::table('cms.leads')->where('estado', 'nuevo')->count(),
            'respuesta_horas' => $respuesta !== null ? round((float) $respuesta, 1) : null,
            'demos_agendadas' => DB::table('cms.leads')->whereNotNull('demo_fecha')->where('demo_fecha', '>=', $ahora->toDateString())->where('estado', '<>', 'perdido')->count(),
            'demos_hoy' => DB::table('cms.leads')->where('demo_fecha', $ahora->toDateString())->where('estado', '<>', 'perdido')->count(),
            'pipeline_mensual' => number_format((float) $pipeline->total, 2, '.', ''),
            'pipeline_leads' => (int) $pipeline->n,
        ];
    }
}
