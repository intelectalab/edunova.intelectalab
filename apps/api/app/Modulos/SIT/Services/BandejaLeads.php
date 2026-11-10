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
    public function __construct(private EnvioCrm $crm) {}

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
                'l.crm_enviado_en', 'lp.intentos as crm_intentos', 'lp.ultimo_error as crm_error',
            ])
            ->map(fn ($l) => [...(array) $l, 'demo_hora' => $l->demo_hora ? substr($l->demo_hora, 0, 5) : null, 'crm' => self::estadoCrm($l)]);

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
            // EDUN-275 / EDUN-282 · Estado del envío al CRM.
            'crm' => [
                'configurado' => $this->crm->configurado(),
                'pendientes' => DB::table('cms.leads_pendientes')->where('intentos', '<', EnvioCrm::MAX_INTENTOS)->count(),
                'con_error' => DB::table('cms.leads_pendientes')->where('intentos', '>=', EnvioCrm::MAX_INTENTOS)->count(),
            ],
        ];
    }

    /** 'enviado' | 'pendiente' | 'error' (agotó los 5 intentos) | null (sin fila de salida). */
    public static function estadoCrm(object $l): ?string
    {
        if ($l->crm_enviado_en) {
            return 'enviado';
        }
        if ($l->crm_intentos === null) {
            return null;
        }

        return $l->crm_intentos >= EnvioCrm::MAX_INTENTOS ? 'error' : 'pendiente';
    }

    /** Columnas del CSV, en orden (EDUN-282). */
    public const COLUMNAS_CSV = [
        'ID', 'Recibida (hora de Lima)', 'Estado', 'Origen', 'Institución educativa', 'Ciudad', 'Alumnos', 'Plan de interés', 'Contacto', 'Cargo',
        'Correo', 'Teléfono', 'Fecha de demo', 'Hora de demo', 'utm_source', 'utm_medium', 'utm_campaign', 'Página', 'Mensaje', 'CRM',
    ];

    public const MAX_CSV = 20000;

    /**
     * EDUN-282 / SIT-027 · CSV con los mismos filtros de la bandeja (UTF-8 con BOM para Excel).
     * Las celdas que empiezan con = + - @ se escapan con ' (inyección de fórmulas).
     *
     * @param  array{estado?: ?string, plan?: ?string, ciudad?: ?string, q?: ?string, desde?: ?string, hasta?: ?string}  $f
     */
    public function csv(array $f): string
    {
        $filas = $this->consulta($f)
            ->when(! empty($f['estado']), fn ($q) => $q->where('l.estado', $f['estado']))
            ->when(! empty($f['desde']), fn ($q) => $q->where('l.created_at', '>=', CarbonImmutable::parse($f['desde'], 'America/Lima')->startOfDay()))
            ->when(! empty($f['hasta']), fn ($q) => $q->where('l.created_at', '<', CarbonImmutable::parse($f['hasta'], 'America/Lima')->addDay()->startOfDay()))
            ->orderByDesc('l.created_at')->orderByDesc('l.id')->limit(self::MAX_CSV)
            ->get(['l.*', 'p.nombre as plan_nombre', 'lp.intentos as crm_intentos']);

        $salida = fopen('php://temp', 'r+');
        fwrite($salida, "\u{FEFF}");
        fputcsv($salida, self::COLUMNAS_CSV, ',', '"', '');
        foreach ($filas as $l) {
            fputcsv($salida, array_map(self::celda(...), [
                $l->id,
                CarbonImmutable::parse($l->created_at)->setTimezone('America/Lima')->format('Y-m-d H:i'),
                self::ESTADOS[$l->estado] ?? $l->estado,
                $l->source,
                $l->colegio,
                $l->ciudad,
                $l->alumnos,
                $l->plan_nombre,
                $l->nombre,
                $l->cargo,
                $l->email,
                $l->telefono,
                $l->demo_fecha,
                $l->demo_hora ? substr($l->demo_hora, 0, 5) : null,
                $l->utm_source,
                $l->utm_medium,
                $l->utm_campaign,
                $l->page_url,
                $l->mensaje,
                match (self::estadoCrm($l)) {
                    'enviado' => 'Enviado', 'pendiente' => 'Pendiente', 'error' => 'Con error', default => ''
                },
            ]), ',', '"', '');
        }
        rewind($salida);
        $csv = (string) stream_get_contents($salida);
        fclose($salida);

        return $csv;
    }

    /** Evita que Excel ejecute una celda como fórmula. */
    private static function celda(mixed $v): string
    {
        $v = $v === null ? '' : (string) $v;

        return preg_match('/^[=+\-@\t\r]/', $v) === 1 ? "'".$v : $v;
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
            ->leftJoin('cms.leads_pendientes as lp', 'lp.lead_id', '=', 'l.id')
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
