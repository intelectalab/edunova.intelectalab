<?php

namespace App\Modulos\SIT\Http\Controllers;

use App\Modulos\SIT\Http\Middleware\VisitanteSitio;
use App\Modulos\SIT\Services\BlogWordpress;
use App\Modulos\SIT\Services\RegistroConsentimientos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Sprint 11 · Rutas públicas de canales:
 *   GET  /sitio/blog, /sitio/blog/{slug}   artículos de WordPress ya limpios (EDUN-363)
 *   POST /sitio/chatbot/lead               contacto dejado en el chatbot (EDUN-375)
 */
class SitioCanalesController
{
    public function __construct(private BlogWordpress $blog, private RegistroConsentimientos $registro) {}

    public function blog(Request $request): JsonResponse
    {
        $f = $request->validate(['categoria' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/'], 'pagina' => ['nullable', 'integer', 'min:1', 'max:1000']]);

        return response()->json($this->blog->listado($f))->header('Cache-Control', 'public, max-age=60');
    }

    public function articulo(string $slug): JsonResponse
    {
        $a = $this->blog->articulo($slug);

        return $a ? response()->json($a)->header('Cache-Control', 'public, max-age=60') : response()->json(['message' => 'No encontramos ese artículo.'], 404);
    }

    public function leadChatbot(Request $request): JsonResponse
    {
        $d = $request->validate([
            'nombre' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'required_without:telefono', 'email:rfc', 'max:160'],
            'telefono' => ['nullable', 'required_without:email', 'string', 'regex:/^\+?[0-9 ()-]{6,20}$/'],
            'consentimiento' => ['accepted'],
            'page_url' => ['nullable', 'string', 'max:500'],
            'recorrido' => ['present', 'array', 'max:40'],
            'recorrido.*' => ['string', 'max:300'],
            'sitio_web' => ['prohibited'],
        ], [
            'email.required_without' => 'Escribe tu correo o tu WhatsApp.',
            'telefono.required_without' => 'Escribe tu correo o tu WhatsApp.',
            'email.email' => 'Escribe un correo válido.',
            'telefono.regex' => 'Escribe un teléfono válido.',
            'consentimiento.accepted' => 'Debes aceptar la Política de Privacidad para enviar tus datos.',
        ]);

        $lead = $this->registro->registrarLead([
            'source' => 'chatbot',
            'nombre' => isset($d['nombre']) && trim($d['nombre']) !== '' ? trim($d['nombre']) : null,
            'email' => isset($d['email']) ? mb_strtolower(trim($d['email'])) : null,
            'telefono' => isset($d['telefono']) ? trim($d['telefono']) : null,
            'mensaje' => Str::limit('Chatbot: '.implode(' › ', $d['recorrido']), 1000),
            'page_url' => $d['page_url'] ?? null,
        ], VisitanteSitio::ip($request), VisitanteSitio::agente($request));

        // La conversación queda para el equipo (el público solo inserta: RLS).
        DB::transaction(function () use ($d, $lead) {
            DB::select("select set_config('app.rol_cms', 'publico', true)");
            $sesion = (int) DB::selectOne("select nextval('cms.chatbot_sesiones_id_seq') as id")->id;
            DB::table('cms.chatbot_sesiones')->insert(['id' => $sesion, 'sesion_uuid' => (string) Str::uuid(), 'nodos_visitados' => json_encode($d['recorrido'], JSON_UNESCAPED_UNICODE),
                'lead_capturado' => true, 'lead_id' => $lead['id'], 'resultado' => 'captura', 'pagina' => self::pagina($d['page_url'] ?? null)]);
            foreach ($d['recorrido'] as $paso) {
                DB::table('cms.chatbot_mensajes')->insert(['sesion_id' => $sesion, 'rol' => 'visitante', 'contenido' => Str::limit($paso, 300)]);
            }
        });

        return response()->json(['message' => 'Datos registrados.', 'consentimiento_version' => $lead['consentimiento_version']], 201);
    }

    /**
     * EDUN-380 · Conversación del chatbot que terminó sin dejar contacto (llegó al fin, abrió un
     * enlace o WhatsApp, o se cerró a medias). Una sola vez por conversación: reenviar el mismo
     * identificador no duplica. Sin datos personales: solo el recorrido por los pasos.
     */
    public function sesionChatbot(Request $request): JsonResponse
    {
        $d = $request->validate([
            'sesion_uuid' => ['required', 'uuid'],
            'resultado' => ['required', Rule::in(['whatsapp', 'url', 'fin', 'abandono'])],
            'recorrido' => ['present', 'array', 'max:40'],
            'recorrido.*' => ['string', 'max:300'],
            'page_url' => ['nullable', 'string', 'max:500'],
        ]);
        DB::transaction(function () use ($d) {
            DB::select("select set_config('app.rol_cms', 'publico', true)");
            $sesion = (int) DB::selectOne("select nextval('cms.chatbot_sesiones_id_seq') as id")->id;
            $nueva = DB::table('cms.chatbot_sesiones')->insertOrIgnore(['id' => $sesion, 'sesion_uuid' => $d['sesion_uuid'],
                'nodos_visitados' => json_encode($d['recorrido'], JSON_UNESCAPED_UNICODE), 'resultado' => $d['resultado'], 'pagina' => self::pagina($d['page_url'] ?? null)]);
            if ($nueva) {
                foreach ($d['recorrido'] as $paso) {
                    $rol = str_starts_with($paso, 'Tú:') ? 'visitante' : 'bot';
                    DB::table('cms.chatbot_mensajes')->insert(['sesion_id' => $sesion, 'rol' => $rol, 'contenido' => Str::limit($paso, 300)]);
                }
            }
        });

        return response()->json(['message' => 'Registrado.'], 201);
    }

    /** Solo la ruta de la página (sin consulta ni fragmento, que pueden llevar datos). */
    private static function pagina(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        $ruta = parse_url($url, PHP_URL_PATH);

        return is_string($ruta) ? Str::limit($ruta, 300, '') : null;
    }
}
