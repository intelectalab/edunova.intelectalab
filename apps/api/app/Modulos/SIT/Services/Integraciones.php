<?php

namespace App\Modulos\SIT\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * EDUN-403 / SIT-033 · Tracking e integraciones desde el Panel CMS, sin deploy.
 *
 * - Tracking (cms.tracking): GTM, GA4, Meta Pixel, LinkedIn, Clarity y la verificación de Search
 *   Console. Solo salen al sitio los IDs con formato válido y activos; el sitio los carga con el
 *   consentimiento de cookies que corresponda (EDUN-306).
 * - Integraciones (cms.config.integraciones): endpoint y clave del CRM, SMTP, proveedor de IA y
 *   URL de WordPress. Las claves se guardan cifradas (APP_KEY) y la API nunca las devuelve: solo
 *   dice si están configuradas. En la auditoría quedan como «[oculto]».
 * Se guarda y se aplica al instante (como Ajustes SEO), con versión y auditoría.
 */
class Integraciones
{
    public const TRACKING = [
        'gtm' => '/^GTM-[A-Z0-9]{4,10}$/',
        'ga4' => '/^G-[A-Z0-9]{6,12}$/',
        'meta_pixel' => '/^\d{12,20}$/',
        'linkedin' => '/^\d{5,12}$/',
        'clarity' => '/^[a-z0-9]{8,14}$/',
        'search_console' => '/^[A-Za-z0-9_-]{20,100}$/',
    ];

    /** Valores de ejemplo que nunca se publican aunque tengan formato válido. */
    private const DE_EJEMPLO = ['GTM-XXXXXXX', 'G-XXXXXXXXXX'];

    private const CLAVE_CACHE = 'edunova:integraciones';

    public function __construct(private EditorCms $editor) {}

    /** Lo que ve el panel (las claves, enmascaradas). */
    public function vista(): array
    {
        $tracking = DB::table('cms.tracking')->whereIn('nombre', array_keys(self::TRACKING))->get(['nombre', 'valor', 'activo'])->keyBy('nombre');
        $i = $this->guardadas();

        return [
            'tracking' => collect(self::TRACKING)->map(fn ($r, $n) => ['valor' => $tracking[$n]->valor ?? null, 'activo' => (bool) ($tracking[$n]->activo ?? false)])->all(),
            'crm' => ['url' => $i['crm']['url'] ?? null, 'token' => ['configurado' => ! empty($i['crm']['token'])]],
            'smtp' => [
                'host' => $i['smtp']['host'] ?? null, 'puerto' => $i['smtp']['puerto'] ?? 587, 'usuario' => $i['smtp']['usuario'] ?? null,
                'cifrado' => $i['smtp']['cifrado'] ?? 'tls', 'remitente' => $i['smtp']['remitente'] ?? null, 'clave' => ['configurado' => ! empty($i['smtp']['clave'])],
            ],
            'ia' => ['proveedor' => $i['ia']['proveedor'] ?? null, 'clave' => ['configurado' => ! empty($i['ia']['clave']), 'servidor' => (bool) config('services.anthropic.clave')]],
            'wordpress' => ['url' => self::urlWordpress()],
            'recaptcha' => [
                'sitio' => $i['recaptcha']['sitio'] ?? null, 'umbral' => (float) ($i['recaptcha']['umbral'] ?? config('services.recaptcha.umbral', 0.5)),
                'secreto' => ['configurado' => ! empty($i['recaptcha']['secreto']), 'servidor' => (string) config('services.recaptcha.secreto') !== ''],
            ],
            'alertas' => ['webhook' => ['configurado' => ! empty($i['alertas']['webhook']), 'servidor' => (bool) config('services.alertas.webhook')]],
            'correo' => ['webhook_secreto' => ['configurado' => ! empty($i['correo']['webhook_secreto']), 'servidor' => (string) config('services.correo.webhook_secreto') !== '']],
            'demo' => self::demoDe($i['demo'] ?? null) + ['colegios' => DB::connection('propietario')->table('instituciones')->orderBy('nombre')->get(['slug', 'nombre'])->map(fn ($c) => (array) $c)->all()],
            // Solo lectura: los conectores simulados se encienden en el servidor, porque en
            // producción permitirían marcar pagos o comprobantes sin cobrar ni emitir de verdad.
            'conectores' => [
                'produccion' => app()->environment('production'),
                'pasarela_simulada' => (bool) config('services.pasarela.simular'),
                'facturacion_simulada' => (bool) config('services.facturacion.simular'),
                'whatsapp_simulado' => (bool) config('services.whatsapp.simular'),
            ],
        ];
    }

    public static function reglas(): array
    {
        $reglas = [];
        foreach (self::TRACKING as $nombre => $regex) {
            $reglas["tracking.{$nombre}.valor"] = ['nullable', 'string', "regex:{$regex}"];
            $reglas["tracking.{$nombre}.activo"] = ['required', 'boolean'];
        }

        return $reglas + [
            'crm.url' => ['nullable', 'url:https', 'max:300'],
            // Claves: null = no cambiar, texto = reemplazar; *_borrar = true las elimina.
            'crm.token' => ['nullable', 'string', 'max:500'],
            'crm.token_borrar' => ['sometimes', 'boolean'],
            'smtp.clave_borrar' => ['sometimes', 'boolean'],
            'ia.clave_borrar' => ['sometimes', 'boolean'],
            'smtp.host' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9.-]+$/'],
            'smtp.puerto' => ['nullable', 'integer', Rule::in([25, 465, 587, 2525])],
            'smtp.usuario' => ['nullable', 'string', 'max:200'],
            'smtp.clave' => ['nullable', 'string', 'max:500'],
            'smtp.cifrado' => ['nullable', Rule::in(['tls', 'ssl', 'ninguno'])],
            'smtp.remitente' => ['nullable', 'email:rfc', 'max:160'],
            'ia.proveedor' => ['nullable', Rule::in(['anthropic', 'openai', 'otro'])],
            'ia.clave' => ['nullable', 'string', 'max:500'],
            'wordpress.url' => ['nullable', 'url:https', 'max:300'],
            'recaptcha' => ['sometimes', 'array'],
            'recaptcha.sitio' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{20,60}$/'],
            'recaptcha.secreto' => ['nullable', 'string', 'max:200'],
            'recaptcha.secreto_borrar' => ['sometimes', 'boolean'],
            'recaptcha.umbral' => ['nullable', 'numeric', 'between:0.1,0.9'],
            'alertas' => ['sometimes', 'array'],
            'alertas.webhook' => ['nullable', 'url:https', 'max:500'],
            'alertas.webhook_borrar' => ['sometimes', 'boolean'],
            'correo' => ['sometimes', 'array'],
            'correo.webhook_secreto' => ['nullable', 'string', 'min:16', 'max:200'],
            'correo.webhook_secreto_borrar' => ['sometimes', 'boolean'],
            'demo' => ['sometimes', 'array'],
            'demo.activo' => ['required_with:demo', 'boolean'],
            'demo.colegio' => ['nullable', 'string', 'max:60'],
        ];
    }

    public static function mensajes(): array
    {
        return [
            'tracking.gtm.valor.regex' => 'El ID de Google Tag Manager tiene la forma GTM-XXXXXXX.',
            'tracking.ga4.valor.regex' => 'El ID de GA4 tiene la forma G-XXXXXXXXXX.',
            'tracking.meta_pixel.valor.regex' => 'El ID del píxel de Meta son solo números (12 a 20).',
            'tracking.linkedin.valor.regex' => 'El Partner ID de LinkedIn son solo números.',
            'tracking.clarity.valor.regex' => 'El ID de proyecto de Clarity son letras minúsculas y números.',
            'tracking.search_console.valor.regex' => 'Pega solo el código de verificación (content="…") de Search Console.',
            '*.url' => 'Escribe una dirección https completa.',
            'smtp.host.regex' => 'Escribe el servidor SMTP sin protocolo (ej.: smtp.proveedor.com).',
            'smtp.remitente.email' => 'Escribe un correo válido.',
            'recaptcha.sitio.regex' => 'Pega la clave del sitio de reCAPTCHA v3 tal como la da Google.',
            'recaptcha.umbral.between' => 'El puntaje mínimo va de 0.1 a 0.9 (0.5 es lo recomendado).',
            'correo.webhook_secreto.min' => 'El secreto del webhook debe tener al menos 16 caracteres.',
        ];
    }

    public function guardar(array $d, User $usuario, ?string $ip): array
    {
        $antes = $this->vista();

        foreach (self::TRACKING as $nombre => $_) {
            $t = $d['tracking'][$nombre] ?? null;
            if ($t === null) {
                continue;
            }
            $valor = isset($t['valor']) && trim((string) $t['valor']) !== '' ? trim($t['valor']) : null;
            DB::table('cms.tracking')->updateOrInsert(['nombre' => $nombre], ['valor' => $valor, 'activo' => (bool) $t['activo'] && $valor !== null]);
        }

        $i = $this->guardadas();
        $i['crm'] = ['url' => $d['crm']['url'] ?? null, 'token' => self::secreto($d['crm']['token'] ?? null, $i['crm']['token'] ?? null, (bool) ($d['crm']['token_borrar'] ?? false))];
        $i['smtp'] = [
            'host' => $d['smtp']['host'] ?? null, 'puerto' => $d['smtp']['puerto'] ?? 587, 'usuario' => $d['smtp']['usuario'] ?? null,
            'cifrado' => $d['smtp']['cifrado'] ?? 'tls', 'remitente' => $d['smtp']['remitente'] ?? null,
            'clave' => self::secreto($d['smtp']['clave'] ?? null, $i['smtp']['clave'] ?? null, (bool) ($d['smtp']['clave_borrar'] ?? false)),
        ];
        $i['ia'] = ['proveedor' => $d['ia']['proveedor'] ?? null, 'clave' => self::secreto($d['ia']['clave'] ?? null, $i['ia']['clave'] ?? null, (bool) ($d['ia']['clave_borrar'] ?? false))];
        if (isset($d['recaptcha'])) {
            $r = $d['recaptcha'];
            $i['recaptcha'] = [
                'sitio' => $r['sitio'] ?? null, 'umbral' => isset($r['umbral']) ? round((float) $r['umbral'], 2) : null,
                'secreto' => self::secreto($r['secreto'] ?? null, $i['recaptcha']['secreto'] ?? null, (bool) ($r['secreto_borrar'] ?? false)),
            ];
        }
        if (isset($d['alertas'])) {
            $i['alertas'] = ['webhook' => self::secreto($d['alertas']['webhook'] ?? null, $i['alertas']['webhook'] ?? null, (bool) ($d['alertas']['webhook_borrar'] ?? false))];
        }
        if (isset($d['correo'])) {
            $i['correo'] = ['webhook_secreto' => self::secreto($d['correo']['webhook_secreto'] ?? null, $i['correo']['webhook_secreto'] ?? null, (bool) ($d['correo']['webhook_secreto_borrar'] ?? false))];
        }
        if (isset($d['demo'])) {
            $colegio = $d['demo']['colegio'] ?? null;
            if ($colegio && ! DB::connection('propietario')->table('instituciones')->where('slug', $colegio)->exists()) {
                throw ValidationException::withMessages(['demo.colegio' => ['Elige un colegio que exista.']]);
            }
            if ((bool) $d['demo']['activo'] && ! ($colegio ?? config('edunova.demo.colegio'))) {
                throw ValidationException::withMessages(['demo.colegio' => ['Elige el colegio de la demostración.']]);
            }
            $i['demo'] = ['activo' => (bool) $d['demo']['activo'], 'colegio' => $colegio];
        }
        DB::table('cms.config')->updateOrInsert(['clave' => 'integraciones'], ['valor' => json_encode($i)]);
        DB::table('cms.config')->updateOrInsert(['clave' => 'blog_wp_url'], ['valor' => json_encode($d['wordpress']['url'] ?? null)]);

        $despues = $this->vista();
        DB::table('cms.versiones')->insert(['seccion' => 'integraciones', 'entidad' => 'config', 'entidad_id' => 'integraciones', 'contenido' => json_encode($despues), 'publicado_por' => $usuario->id]);
        DB::table('cms.auditoria')->insert([
            'usuario_id' => $usuario->id, 'usuario_rol' => 'superadmin', 'seccion' => 'integraciones', 'accion' => 'publicado',
            'entidad' => 'config', 'entidad_id' => 'integraciones', 'detalle' => 'Tracking e integraciones · actualizado',
            // Las claves nunca llegan a la auditoría: la vista solo dice si están configuradas.
            'valor_antes' => json_encode($antes), 'valor_despues' => json_encode($despues), 'ip' => $ip,
        ]);
        DB::afterCommit(function () {
            Cache::forget(self::CLAVE_CACHE);
            $this->editor->revalidarSitio(['landing']);
        });

        return $despues;
    }

    /** IDs de tracking válidos y activos (lo que el sitio puede cargar con consentimiento). */
    public static function trackingPublico(): array
    {
        $filas = DB::table('cms.tracking')->whereIn('nombre', array_keys(self::TRACKING))->where('activo', true)->pluck('valor', 'nombre');

        return collect(self::TRACKING)->map(function ($regex, $nombre) use ($filas) {
            $v = trim((string) ($filas[$nombre] ?? ''));
            $v = $nombre === 'gtm' || $nombre === 'ga4' ? strtoupper($v) : $v;

            return $v !== '' && preg_match($regex, $v) && ! in_array($v, self::DE_EJEMPLO, true) ? $v : null;
        })->all();
    }

    /**
     * Integraciones descifradas para el uso interno (CRM, SMTP), en caché 60 s. Lo del panel gana
     * a las variables de entorno.
     *
     * @return array{crm: array{url: ?string, token: ?string}, smtp: array<string, mixed>, wordpress: ?string}
     */
    public static function internas(): array
    {
        return Cache::remember(self::CLAVE_CACHE, 60, function () {
            try {
                // Lectura sin tocar app.rol_cms: puede correr dentro de la transacción del Panel CMS
                // (cms.config se puede leer siempre; RLS solo limita la escritura).
                $fila = DB::table('cms.config')->whereIn('clave', ['integraciones', 'blog_wp_url'])->pluck('valor', 'clave');
                $i = json_decode((string) ($fila['integraciones'] ?? '{}'), true) ?: [];
                $wp = json_decode((string) ($fila['blog_wp_url'] ?? 'null'), true);
            } catch (Throwable) {
                $i = [];
                $wp = null;
            }

            return [
                'crm' => ['url' => $i['crm']['url'] ?? null, 'token' => self::descifrar($i['crm']['token'] ?? null)],
                'smtp' => [...($i['smtp'] ?? []), 'clave' => self::descifrar($i['smtp']['clave'] ?? null)],
                'wordpress' => is_string($wp) && $wp !== '' ? rtrim($wp, '/') : null,
                'ia' => ['proveedor' => $i['ia']['proveedor'] ?? null, 'clave' => self::descifrar($i['ia']['clave'] ?? null)],
                'recaptcha' => ['sitio' => $i['recaptcha']['sitio'] ?? null, 'umbral' => $i['recaptcha']['umbral'] ?? null, 'secreto' => self::descifrar($i['recaptcha']['secreto'] ?? null)],
                'alertas' => self::descifrar($i['alertas']['webhook'] ?? null),
                'correo' => self::descifrar($i['correo']['webhook_secreto'] ?? null),
                'demo' => $i['demo'] ?? null,
            ];
        });
    }

    /** CRM: lo del panel o, si no hay, CRM_WEBHOOK_URL / CRM_WEBHOOK_TOKEN. */
    public static function crm(): array
    {
        $i = self::internas()['crm'];

        return ['url' => $i['url'] ?: config('services.crm.url'), 'token' => $i['url'] ? $i['token'] : config('services.crm.token')];
    }

    /** Usa el SMTP del panel para los correos (si está completo). */
    public static function aplicarSmtp(): void
    {
        $s = self::internas()['smtp'];
        if (empty($s['host']) || empty($s['usuario']) || empty($s['clave'])) {
            return;
        }
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $s['host'],
            'mail.mailers.smtp.port' => (int) ($s['puerto'] ?? 587),
            'mail.mailers.smtp.username' => $s['usuario'],
            'mail.mailers.smtp.password' => $s['clave'],
            'mail.mailers.smtp.scheme' => ($s['cifrado'] ?? 'tls') === 'ssl' ? 'smtps' : 'smtp',
            ...(! empty($s['remitente']) ? ['mail.from.address' => $s['remitente']] : []),
        ]);
        app('mail.manager')->forgetMailers();
    }

    public static function urlWordpress(): ?string
    {
        return self::internas()['wordpress'];
    }

    public const PRUEBAS = ['wordpress', 'smtp', 'crm', 'alertas'];

    /**
     * «Probar conexión» con lo ya guardado: WordPress (lee su API REST), SMTP (envía un correo de
     * prueba a quien prueba), CRM y alertas (envían un aviso de prueba). Nunca devuelve claves.
     *
     * @return array{ok: bool, mensaje: string}
     */
    public function probar(string $servicio, User $usuario): array
    {
        Cache::forget(self::CLAVE_CACHE);
        try {
            return match ($servicio) {
                'wordpress' => $this->probarWordpress(),
                'smtp' => $this->probarSmtp($usuario),
                'crm' => $this->probarWebhook(self::crm()['url'], self::crm()['token'], ['prueba' => true, 'origen' => 'edunova', 'mensaje' => 'Prueba de conexión desde el Panel CMS de Edunova.'], 'el CRM'),
                'alertas' => $this->probarWebhook(self::webhookAlertas(), null, ['text' => 'Prueba de alertas de Edunova: si ves este mensaje, las alertas llegan bien.', 'content' => 'Prueba de alertas de Edunova.'], 'el webhook de alertas'),
            };
        } catch (Throwable $e) {
            Log::info('Prueba de integración fallida.', ['servicio' => $servicio, 'error' => class_basename($e)]);

            return ['ok' => false, 'mensaje' => 'No se pudo conectar: '.Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()), 180)];
        }
    }

    private function probarWordpress(): array
    {
        $base = self::urlWordpress();
        if (! $base) {
            return ['ok' => false, 'mensaje' => 'Primero escribe y guarda la dirección de WordPress.'];
        }
        $r = Http::timeout(8)->acceptJson()->get("{$base}/wp-json/wp/v2/posts", ['per_page' => 1, 'status' => 'publish', '_fields' => 'id']);
        if (! $r->successful() || ! is_array($r->json())) {
            return ['ok' => false, 'mensaje' => "WordPress respondió con el código {$r->status()}. Revisa la dirección y que la API REST (/wp-json) esté activa."];
        }
        $total = (int) ($r->header('X-WP-Total') ?: count($r->json()));

        return ['ok' => true, 'mensaje' => "Conectado con WordPress: {$total} ".($total === 1 ? 'artículo publicado' : 'artículos publicados').'.'];
    }

    private function probarSmtp(User $usuario): array
    {
        $s = self::internas()['smtp'];
        if (empty($s['host']) || empty($s['usuario']) || empty($s['clave'])) {
            return ['ok' => false, 'mensaje' => 'Completa y guarda el servidor, el usuario y la clave del SMTP.'];
        }
        if (! $usuario->email) {
            return ['ok' => false, 'mensaje' => 'Tu usuario no tiene correo para recibir la prueba.'];
        }
        self::aplicarSmtp();
        Mail::raw('Este es un correo de prueba del Panel CMS de Edunova. Si lo recibiste, el SMTP está bien configurado.', function ($m) use ($usuario) {
            $m->to($usuario->email)->subject('Prueba de correo de Edunova');
        });

        return ['ok' => true, 'mensaje' => "Enviamos un correo de prueba a {$usuario->email}. Revisa también la carpeta de spam."];
    }

    private function probarWebhook(?string $url, ?string $token, array $cuerpo, string $nombre): array
    {
        if (! $url) {
            return ['ok' => false, 'mensaje' => "Primero configura y guarda {$nombre}."];
        }
        $r = Http::timeout(8)->acceptJson()->withHeaders($token ? ['Authorization' => "Bearer {$token}"] : [])->post($url, $cuerpo);

        return $r->successful()
            ? ['ok' => true, 'mensaje' => 'Conectado: '.$nombre.' recibió el aviso de prueba.']
            : ['ok' => false, 'mensaje' => ucfirst($nombre)." respondió con el código {$r->status()}."];
    }

    /** Clave de Anthropic para el asistente SEO: la del panel (proveedor «anthropic») o ANTHROPIC_API_KEY. */
    public static function claveIa(): ?string
    {
        $ia = self::internas()['ia'] ?? [];

        return (($ia['proveedor'] ?? null) === 'anthropic' && ! empty($ia['clave'])) ? $ia['clave'] : (config('services.anthropic.clave') ?: null);
    }

    /** reCAPTCHA v3: lo del panel gana a RECAPTCHA_SECRETO / RECAPTCHA_SITIO / RECAPTCHA_UMBRAL. */
    public static function recaptcha(): array
    {
        $r = self::internas()['recaptcha'] ?? [];

        return [
            'sitio' => $r['sitio'] ?? null,
            'secreto' => ($r['secreto'] ?? null) ?: ((string) config('services.recaptcha.secreto') ?: null),
            'umbral' => (float) ($r['umbral'] ?? config('services.recaptcha.umbral', 0.5)),
        ];
    }

    /** Webhook de alertas del monitoreo: el del panel o ALERTAS_WEBHOOK_URL. */
    public static function webhookAlertas(): ?string
    {
        return (self::internas()['alertas'] ?? null) ?: (config('services.alertas.webhook') ?: null);
    }

    /** Secreto del webhook de rebotes y quejas del proveedor de correo. */
    public static function secretoCorreo(): string
    {
        return (string) ((self::internas()['correo'] ?? null) ?: config('services.correo.webhook_secreto'));
    }

    /** Acceso de demostración: lo del panel o EDUNOVA_DEMO_ACCESO / EDUNOVA_DEMO_COLEGIO. */
    public static function demo(): array
    {
        return self::demoDe(self::internas()['demo'] ?? null);
    }

    private static function demoDe(?array $d): array
    {
        return [
            'activo' => $d !== null ? (bool) ($d['activo'] ?? false) : (bool) config('edunova.demo.activo'),
            'colegio' => ($d['colegio'] ?? null) ?: (string) config('edunova.demo.colegio'),
        ];
    }

    private function guardadas(): array
    {
        return json_decode((string) DB::table('cms.config')->where('clave', 'integraciones')->value('valor'), true) ?: [];
    }

    private static function secreto(?string $nuevo, ?string $actual, bool $borrar = false): ?string
    {
        if ($borrar) {
            return null;
        }
        if ($nuevo === null || trim($nuevo) === '') {
            return $actual;
        }

        return Crypt::encryptString(trim($nuevo));
    }

    private static function descifrar(?string $v): ?string
    {
        if (! $v) {
            return null;
        }
        try {
            return Crypt::decryptString($v);
        } catch (Throwable) {
            return null;
        }
    }

    /** Recorta un ID para mostrarlo en registros (nunca se registran claves). */
    public static function resumen(?string $v): string
    {
        return $v ? Str::mask($v, '•', 4) : '—';
    }
}
