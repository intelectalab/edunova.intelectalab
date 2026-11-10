<?php

namespace App\Modulos\SIT\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * EDUN-383 / SIT-029 · Dashboard del CMS y EDUN-407 / SIT-065 · usuarios con acceso.
 *
 * El dashboard muestra solo lo que Edunova mide: leads, demos, reclamaciones, borradores y
 * envíos al CRM. Visitas y conversión no se muestran hasta conectar una fuente real de
 * analítica (TRASPASO §7: nada sin respaldo).
 */
class PanelCms
{
    public const DIAS_TENDENCIA = 30;

    public function __construct(private EditorCms $editor, private GestionReclamaciones $reclamos) {}

    public function dashboard(): array
    {
        $ahora = CarbonImmutable::now('America/Lima');
        $hoy = $ahora->startOfDay();
        $desde = $hoy->subDays(self::DIAS_TENDENCIA - 1);

        $porDia = DB::table('cms.leads')->where('created_at', '>=', $desde)
            ->selectRaw("(created_at at time zone 'America/Lima')::date as dia, count(*) as n")->groupBy('dia')->pluck('n', 'dia');
        $tendencia = collect(range(0, self::DIAS_TENDENCIA - 1))->map(function ($i) use ($desde, $porDia) {
            $dia = $desde->addDays($i)->toDateString();

            return ['dia' => $dia, 'leads' => (int) ($porDia[$dia] ?? 0)];
        });

        $ultimos7 = DB::table('cms.leads')->where('created_at', '>=', $hoy->subDays(6))->count();
        $previos7 = DB::table('cms.leads')->whereBetween('created_at', [$hoy->subDays(13), $hoy->subDays(7)])->count();
        $reclamos = $this->reclamos->listar(['pagina' => 1])['indicadores'];

        return [
            'leads_hoy' => DB::table('cms.leads')->where('created_at', '>=', $hoy)->count(),
            'leads_7d' => $ultimos7,
            'leads_7d_previos' => $previos7,
            'leads_sin_contactar' => DB::table('cms.leads')->where('estado', 'nuevo')->count(),
            'demos_hoy' => DB::table('cms.leads')->where('demo_fecha', $ahora->toDateString())->where('estado', '<>', 'perdido')->count(),
            'por_origen' => DB::table('cms.leads')->where('created_at', '>=', $desde)->select('source', DB::raw('count(*) as n'))
                ->groupBy('source')->orderByDesc('n')->pluck('n', 'source'),
            'tendencia' => $tendencia,
            'reclamos' => ['abiertos' => $reclamos['abiertos'], 'vencidos' => $reclamos['vencidos'], 'por_vencer' => $reclamos['por_vencer']],
            'borradores' => $this->editor->estado()['pendientes'],
            'crm_pendientes' => DB::table('cms.leads_pendientes')->count(),
            'ultimos_leads' => DB::table('cms.leads')->orderByDesc('created_at')->limit(5)
                ->get(['id', 'colegio', 'nombre', 'email', 'telefono', 'source', 'estado', 'created_at']),
        ];
    }

    /** Superadmins activos: los únicos que entran al CMS (alta y baja en la consola de plataforma). */
    public function usuarios(): array
    {
        return collect(DB::select('select * from cms.usuarios_cms()'))->map(fn ($u) => [
            'id' => $u->id,
            'nombre' => trim("{$u->nombres} {$u->apellidos}"),
            'email' => $u->email,
            'ultimo_acceso_at' => $u->ultimo_acceso_at,
            'desde' => $u->desde,
            'dos_pasos' => (bool) $u->dos_pasos,
            'cambios_30d' => DB::table('cms.auditoria')->where('usuario_id', $u->id)->where('created_at', '>=', now()->subDays(30))->count(),
        ])->all();
    }
}
