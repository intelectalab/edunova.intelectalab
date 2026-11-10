<?php

namespace App\Modulos\SIT\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
            'ia' => ['proveedor' => $i['ia']['proveedor'] ?? null, 'clave' => ['configurado' => ! empty($i['ia']['clave'])]],
            'wordpress' => ['url' => self::urlWordpress()],
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
