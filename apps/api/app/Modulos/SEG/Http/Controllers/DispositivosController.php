<?php

namespace App\Modulos\SEG\Http\Controllers;

use App\Modulos\SEG\Services\NotificacionesPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** EDUN-195 / EDUN-196 · App móvil: dispositivo para push y preferencias por tipo. */
class DispositivosController
{
    public function __construct(private NotificacionesPush $push) {}

    public function registrar(Request $request): JsonResponse
    {
        $d = $request->validate([
            'token' => ['required', 'string', 'max:200'],
            'plataforma' => ['required', Rule::in(['ios', 'android'])],
            'app_version' => ['nullable', 'string', 'max:20'],
        ]);
        $this->push->registrar((int) $request->user()->id, $d['token'], $d['plataforma'], $d['app_version'] ?? null);

        return response()->json(['message' => 'Dispositivo registrado.']);
    }

    public function quitar(Request $request): JsonResponse
    {
        $d = $request->validate(['token' => ['required', 'string', 'max:200']]);
        $this->push->quitar((int) $request->user()->id, $d['token']);

        return response()->json(['message' => 'Dispositivo quitado.']);
    }

    public function preferencias(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->push->preferencias((int) $request->user()->id)]);
    }

    public function guardarPreferencias(Request $request): JsonResponse
    {
        $d = $request->validate(['preferencias' => ['required', 'array'], 'preferencias.*' => ['boolean']]);

        return response()->json(['data' => $this->push->guardarPreferencias((int) $request->user()->id, $d['preferencias'])]);
    }

    /** Aviso de prueba al propio usuario (lo abre en la pantalla de notificaciones). */
    public function probar(Request $request): JsonResponse
    {
        $r = $this->push->enviar([(int) $request->user()->id], 'comunicados', 'Notificación de prueba', 'Si ves esto, las notificaciones de Edunova funcionan.', ['pantalla' => 'notificaciones']);

        return response()->json($r);
    }
}
