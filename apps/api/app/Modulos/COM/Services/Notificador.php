<?php

namespace App\Modulos\COM\Services;

use App\Modulos\COM\Correo\AvisoColegio;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\Compartido\Correo\CorreoTransaccional;
use App\Modulos\SEG\Services\NotificacionesPush;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Regla 1 de COM · Envío por canales: push de la app (por defecto), WhatsApp y correo. Cada envío
 * queda en `notificaciones` con su estado; WhatsApp registra su costo por mensaje (EDUN-68).
 * WhatsApp usa un conector simulado hasta configurar el proveedor (WHATSAPP_SIMULADO).
 */
class Notificador
{
    public const CANALES = ['app' => 'App', 'whatsapp' => 'WhatsApp', 'correo' => 'Correo'];

    public function __construct(private NotificacionesPush $push) {}

    /**
     * @param  list<int>  $usuarios
     * @param  list<string>  $canales
     * @param  array<string, mixed>  $datos  pantalla e id para abrir en la app
     * @return array{app: int, whatsapp: int, correo: int}
     */
    public function enviar(array $usuarios, array $canales, string $tipo, string $titulo, string $cuerpo, array $datos): array
    {
        $usuarios = array_values(array_unique(array_map('intval', $usuarios)));
        $r = ['app' => 0, 'whatsapp' => 0, 'correo' => 0];
        if (! $usuarios) {
            return $r;
        }
        if (in_array('app', $canales, true)) {
            $r['app'] = $this->push->enviar($usuarios, $tipo, $titulo, $cuerpo, $datos)['enviados'];
        }
        $contactos = DB::table('users as u')->leftJoin('apoderados as a', 'a.user_id', '=', 'u.id')->whereIn('u.id', $usuarios)
            ->get(['u.id', 'u.email', DB::raw('coalesce(u.celular, a.celular) as celular')])->unique('id');
        $inst = ContextoInstitucion::requerido();
        $colegio = DB::table('instituciones')->where('id', $inst)->value('nombre');
        foreach ($contactos as $c) {
            if (in_array('whatsapp', $canales, true) && $c->celular) {
                $ok = $this->whatsapp((string) $c->celular, "*{$titulo}*\n{$cuerpo}\n— {$colegio}");
                $this->registrar($inst, (int) $c->id, 'whatsapp', $tipo, $titulo, $cuerpo, $datos, $ok, $ok ? (float) config('services.whatsapp.costo_mensaje') : null, (string) $c->celular);
                $r['whatsapp'] += $ok ? 1 : 0;
            }
            if (in_array('correo', $canales, true) && $c->email) {
                $ok = true;
                try {
                    app(CorreoTransaccional::class)->enviar(new AvisoColegio($colegio, $titulo, $cuerpo), (string) $c->email, 'aviso_'.$tipo, $inst);
                } catch (\Throwable $e) {
                    $ok = false;
                    Log::warning('Correo no enviado: '.$e->getMessage());
                }
                $this->registrar($inst, (int) $c->id, 'email', $tipo, $titulo, $cuerpo, $datos, $ok, null, (string) $c->email);
                $r['correo'] += $ok ? 1 : 0;
            }
        }

        return $r;
    }

    /** Conector de WhatsApp: simulado hasta contratar el proveedor (Cloud API u otro). */
    private function whatsapp(string $celular, string $texto): bool
    {
        if (config('services.whatsapp.simular')) {
            return preg_match('/^9\d{8}$/', preg_replace('/\D/', '', $celular)) === 1;
        }
        $clase = config('services.whatsapp.adaptador');

        return $clase ? (bool) app($clase)->enviar($celular, $texto) : false;
    }

    private function registrar(int $inst, int $usuario, string $canal, string $tipo, string $titulo, string $cuerpo, array $datos, bool $ok, ?float $costo, string $destino): void
    {
        DB::table('notificaciones')->insert([
            'institucion_id' => $inst, 'user_id' => $usuario, 'canal' => $canal, 'plantilla' => $tipo, 'destino' => mb_substr($destino, 0, 150),
            'datos' => json_encode(['titulo' => $titulo, 'cuerpo' => mb_substr($cuerpo, 0, 500)] + $datos), 'estado' => $ok ? 'enviado' : 'fallido',
            'costo' => $costo, 'enviado_at' => $ok ? now() : null,
        ]);
    }

    /** EDUN-68 · Bitácora: estado por envío y costo de WhatsApp por mes. */
    public function bitacora(array $f): array
    {
        $base = DB::table('notificaciones as n')->leftJoin('users as u', 'u.id', '=', 'n.user_id')
            ->when($f['canal'] ?? null, fn ($q, $v) => $q->where('n.canal', $v))->when($f['estado'] ?? null, fn ($q, $v) => $q->where('n.estado', $v))
            ->when($f['mes'] ?? null, fn ($q, $v) => $q->whereRaw("to_char(n.created_at at time zone 'America/Lima', 'YYYY-MM') = ?", [$v]));

        return [
            'data' => (clone $base)->orderByDesc('n.id')->limit(300)->get(['n.id', 'n.canal', 'n.plantilla', 'n.estado', 'n.costo', 'n.destino', 'n.created_at', 'n.datos',
                DB::raw("u.nombres || ' ' || u.apellidos as usuario")])->map(fn ($n) => array_merge((array) $n, ['costo' => $n->costo !== null ? (float) $n->costo : null, 'datos' => json_decode($n->datos, true)]))->all(),
            'por_mes' => DB::table('notificaciones')->selectRaw("to_char(created_at at time zone 'America/Lima', 'YYYY-MM') as mes, canal, count(*) as envios, sum(case when estado = 'fallido' then 1 else 0 end) as fallidos, coalesce(sum(costo), 0) as costo")
                ->groupByRaw('1, canal')->orderByDesc('mes')->limit(36)->get()->map(fn ($m) => ['mes' => $m->mes, 'canal' => $m->canal, 'envios' => (int) $m->envios, 'fallidos' => (int) $m->fallidos, 'costo' => round((float) $m->costo, 4)])->all(),
        ];
    }
}
