<?php

namespace App\Modulos\MDL\Http\Controllers;

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\Auditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * EDUN-26 · Configuración del conector de Moodle: solo existe si el colegio eligió Moodle o
 * ambas (ruta con middleware modulo:MDL). El token se guarda cifrado y nunca se devuelve.
 * La sincronización llega con el módulo MDL.
 */
class ConectorMoodleController
{
    public function __construct(private Auditoria $auditoria) {}

    public function show(): JsonResponse
    {
        return response()->json(self::datos(DB::table('moodle_conexiones')->first()));
    }

    public function update(Request $request): JsonResponse
    {
        $actual = DB::table('moodle_conexiones')->first();
        $datos = $request->validate([
            'url' => ['required', 'url:https', 'max:300'],
            'token' => [$actual ? 'nullable' : 'required', 'string', 'min:16', 'max:200'],
            'sso_activo' => ['nullable', 'boolean'],
            'frecuencia_minutos' => ['nullable', 'integer', 'between:15,1440'],
        ], [
            'url.required' => 'Escribe la dirección de tu Moodle.',
            'url.url' => 'La dirección debe empezar con https://',
            'token.required' => 'Pega el token del servicio web de Moodle.',
            'token.min' => 'El token no parece completo.',
        ]);

        $fila = [
            'url' => rtrim($datos['url'], '/'),
            'sso_activo' => (bool) ($datos['sso_activo'] ?? true),
            'frecuencia_minutos' => (int) ($datos['frecuencia_minutos'] ?? 60),
        ];
        if (! empty($datos['token'])) {
            $fila['token_cifrado'] = Crypt::encryptString($datos['token']);
        }
        if ($actual) {
            DB::table('moodle_conexiones')->where('id', $actual->id)->update($fila);
        } else {
            DB::table('moodle_conexiones')->insert($fila + ['institucion_id' => ContextoInstitucion::requerido()]);
        }
        $nueva = DB::table('moodle_conexiones')->first();
        $this->auditoria->registrar('moodle_configurado', 'moodle_conexiones', (int) $nueva->id,
            $actual ? self::datos($actual) : null, self::datos($nueva) + ['token_cambiado' => ! empty($datos['token'])]);

        return response()->json(self::datos($nueva));
    }

    private static function datos(?object $c): array
    {
        return [
            'configurado' => $c !== null,
            'url' => $c->url ?? null,
            'tiene_token' => ! empty($c?->token_cifrado),
            'sso_activo' => (bool) ($c->sso_activo ?? true),
            'frecuencia_minutos' => (int) ($c->frecuencia_minutos ?? 60),
            'estado' => $c->estado ?? null,
            'ultima_sincronizacion_at' => $c->ultima_sincronizacion_at ?? null,
        ];
    }
}
