<?php

namespace App\Modulos\SIT\Http\Controllers;

use App\Modulos\SIT\Http\Middleware\VisitanteSitio;
use App\Modulos\SIT\Http\Requests\LeadPopupRequest;
use App\Modulos\SIT\Http\Requests\SolicitudDemoRequest;
use App\Modulos\SIT\Services\Politicas;
use App\Modulos\SIT\Services\RegistroConsentimientos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Sprint 6 · Rutas públicas de conversión y legal (TRASPASO §8):
 *   GET  /sitio/politicas/{tipo}           versión vigente (EDUN-287)
 *   POST /sitio/leads                      demo con calendario y consentimiento (EDUN-267, EDUN-291)
 *   POST /sitio/leads/popup                correo o WhatsApp del popup de salida (EDUN-271)
 *   POST /sitio/consentimientos/cookies    preferencias del banner (EDUN-306)
 */
class SitioLegalController
{
    public function __construct(private Politicas $politicas, private RegistroConsentimientos $registro) {}

    public function politica(string $tipo): JsonResponse
    {
        $politica = DB::transaction(function () use ($tipo) {
            DB::select("select set_config('app.rol_cms', 'publico', true)");

            return $this->politicas->publica($tipo);
        });

        return $politica
            ? response()->json($politica)->header('Cache-Control', 'public, max-age=60')
            : response()->json(['message' => 'Esta política aún no está publicada.'], 404);
    }

    public function lead(SolicitudDemoRequest $request): JsonResponse
    {
        $d = $request->validated();
        $origen = $d['origen'] ?? null;

        $lead = $this->registro->registrarLead([
            'source' => $d['source'],
            'nombre' => trim($d['nombre']),
            'cargo' => $d['cargo'],
            'email' => mb_strtolower(trim($d['email'])),
            'telefono' => trim($d['telefono']),
            'colegio' => trim($d['colegio']),
            'ciudad' => isset($d['ciudad']) && trim($d['ciudad']) !== '' ? trim($d['ciudad']) : null,
            'alumnos' => (int) $d['alumnos'],
            'plan_interes' => $d['plan_interes'] ?? null,
            'sistema_actual' => isset($d['sistema_actual']) && trim($d['sistema_actual']) !== '' ? trim($d['sistema_actual']) : null,
            'urgencia' => $d['urgencia'] ?? null,
            'demo_fecha' => $d['demo_fecha'],
            'demo_hora' => $d['demo_hora'],
            'mensaje' => $origen ? "Demo solicitada desde: {$origen}" : null,
            'page_url' => $d['page_url'] ?? null,
            'utm_source' => $d['utm_source'] ?? null,
            'utm_medium' => $d['utm_medium'] ?? null,
            'utm_campaign' => $d['utm_campaign'] ?? null,
            'utm_content' => $d['utm_content'] ?? null,
            'utm_term' => $d['utm_term'] ?? null,
        ], VisitanteSitio::ip($request), VisitanteSitio::agente($request));

        return response()->json([
            'message' => 'Solicitud registrada.',
            'demo_fecha' => $d['demo_fecha'],
            'demo_hora' => $d['demo_hora'],
            'email' => mb_strtolower(trim($d['email'])),
            'consentimiento_version' => $lead['consentimiento_version'],
        ], 201);
    }

    public function leadPopup(LeadPopupRequest $request): JsonResponse
    {
        $d = $request->validated();
        if (empty($d['email']) && empty($d['telefono'])) {
            return response()->json(['message' => 'Escribe tu correo o tu WhatsApp.', 'errors' => ['email' => ['Escribe tu correo o tu WhatsApp.']]], 422);
        }

        $lead = $this->registro->registrarLead([
            'source' => 'popup',
            'email' => $d['email'] ?? null,
            'telefono' => $d['telefono'] ?? null,
            'mensaje' => 'Pidió información desde el popup de salida',
            'page_url' => $d['page_url'] ?? null,
            'utm_source' => $d['utm_source'] ?? null,
            'utm_medium' => $d['utm_medium'] ?? null,
            'utm_campaign' => $d['utm_campaign'] ?? null,
            'utm_content' => $d['utm_content'] ?? null,
            'utm_term' => $d['utm_term'] ?? null,
        ], VisitanteSitio::ip($request), VisitanteSitio::agente($request));

        return response()->json(['message' => 'Datos registrados.', 'consentimiento_version' => $lead['consentimiento_version']], 201);
    }

    public function cookies(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'categorias' => ['present', 'array', 'max:4'],
            'categorias.*' => ['string', Rule::in(RegistroConsentimientos::CATEGORIAS_COOKIES)],
        ], ['categorias.*' => 'Categoría de cookies no válida.']);

        $version = $this->registro->registrarCookies($datos['categorias'], VisitanteSitio::ip($request), VisitanteSitio::agente($request));

        return response()->json(['message' => 'Preferencias guardadas.', 'version_politica' => $version], 201);
    }
}
