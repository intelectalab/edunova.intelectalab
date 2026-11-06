<?php

namespace App\Modulos\SIT\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/sitio/landing · Contenido publicado y visible de la landing (TRASPASO §8).
 * Lo usa Next.js en el build y en el ISR. Nunca devuelve borradores (EDUN-233).
 */
class LandingController
{
    /** Datos de cms.config que el sitio muestra. El resto (CRM, tracking…) no sale de la API. */
    private const CONFIG_PUBLICA = ['razon_social', 'ruc', 'direccion', 'telefono_central', 'whatsapp', 'email_contacto', 'horario_soporte'];

    public function __invoke(): JsonResponse
    {
        return DB::transaction(function () {
            DB::select("select set_config('app.rol_cms', 'publico', true)");

            $secciones = DB::table('cms.secciones')->where('visible', true)->orderBy('orden')
                ->get(['clave', 'config'])
                ->mapWithKeys(fn ($s) => [$s->clave => json_decode($s->config, true)]);

            $menu = DB::table('cms.menu_items')->where('visible', true)->orderBy('ubicacion')->orderBy('orden')
                ->get(['ubicacion', 'columna', 'label', 'url', 'target']);

            $config = DB::table('cms.config')->whereIn('clave', self::CONFIG_PUBLICA)->get(['clave', 'valor'])
                ->mapWithKeys(fn ($c) => [$c->clave => json_decode($c->valor, true)]);

            return response()->json([
                'secciones' => $secciones,
                'config' => $config,
                'menu' => [
                    'principal' => $menu->where('ubicacion', 'principal')->values(),
                    'footer' => $menu->where('ubicacion', 'footer')->values(),
                ],
            ])->header('Cache-Control', 'public, max-age=60');
        });
    }
}
