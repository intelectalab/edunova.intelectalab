<?php

namespace App\Modulos\SIT\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** EDUN-226 · GET /api/v1/cms/sesion: quién está en el Panel CMS (pie del menú lateral). */
class SesionCmsController
{
    public function __invoke(Request $request): JsonResponse
    {
        $usuario = $request->user();

        return response()->json([
            'usuario' => [
                'id' => $usuario->id,
                'nombre' => $usuario->nombreCompleto(),
                // El nombre visible del rol («Superadministrador») es texto del sitio, no de la API.
                'rol' => 'superadmin',
            ],
            // Confirma que la transacción quedó con el rol del CMS (lo usa el RLS de cms.*).
            'rol_cms' => DB::selectOne("select current_setting('app.rol_cms', true) as r")->r,
        ]);
    }
}
