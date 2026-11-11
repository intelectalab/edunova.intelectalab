<?php

namespace App\Modulos\SIT\Services;

use App\Models\User;
use App\Modulos\SIT\Services\Seo\GeneradorSeo;
use App\Modulos\SIT\Services\Seo\GeneradorSeoClaude;
use App\Modulos\SIT\Services\Seo\GeneradorSeoSimulado;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * EDUN-412 / SIT-030 · Asistente SEO con IA.
 *
 * - Sugerir: 3 meta descripciones, un título y palabras clave (proveedor configurable, D-SIT-04).
 *   Cada pedido queda en cms.seo_sugerencias. No publica nada: el editor elige y guarda en
 *   Ajustes SEO, que es lo que publica, versiona y audita.
 * - Analizar (sin IA): legibilidad (Fernández-Huerta, para español), largo para el resultado de
 *   Google y títulos o descripciones repetidos en otras páginas.
 */
class AsistenteSeo
{
    public const PROVEEDORES = ['simulado' => 'Simulado (sin IA, para pruebas)', 'claude' => 'Claude (Anthropic)'];

    public const MODELOS = ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'];

    public function __construct(private SeoYPoliticasCms $seo) {}

    public function configuracion(): array
    {
        $c = json_decode((string) DB::table('cms.config')->where('clave', 'asistente_seo')->value('valor'), true) ?: [];

        return [
            'proveedor' => array_key_exists($c['proveedor'] ?? '', self::PROVEEDORES) ? $c['proveedor'] : 'simulado',
            'modelo' => in_array($c['modelo'] ?? '', self::MODELOS, true) ? $c['modelo'] : self::MODELOS[0],
            'proveedores' => self::PROVEEDORES,
            'modelos' => self::MODELOS,
            // Solo se informa si hay clave; nunca se devuelve.
            'clave_configurada' => (bool) config('services.anthropic.clave'),
        ];
    }

    public function guardarConfiguracion(array $d, User $usuario, ?string $ip): array
    {
        $antes = array_intersect_key($this->configuracion(), ['proveedor' => 1, 'modelo' => 1]);
        $nueva = ['proveedor' => $d['proveedor'], 'modelo' => $d['modelo'] ?? $antes['modelo']];
        DB::table('cms.config')->updateOrInsert(['clave' => 'asistente_seo'], ['valor' => json_encode($nueva), 'updated_at' => now()]);
        DB::table('cms.auditoria')->insert([
            'usuario_id' => $usuario->id, 'usuario_rol' => 'superadmin', 'seccion' => 'asistente_seo', 'accion' => 'publicado', 'entidad' => 'config',
            'entidad_id' => 'asistente_seo', 'detalle' => 'Asistente SEO · proveedor '.self::PROVEEDORES[$nueva['proveedor']],
            'valor_antes' => json_encode($antes), 'valor_despues' => json_encode($nueva), 'ip' => $ip,
        ]);

        return $this->configuracion();
    }

    public function generador(string $proveedor): GeneradorSeo
    {
        return $proveedor === 'claude' ? app(GeneradorSeoClaude::class) : app(GeneradorSeoSimulado::class);
    }

    public function sugerir(string $slug, ?string $contexto, User $usuario): array
    {
        $pagina = $this->seo->seo($slug);
        $config = $this->configuracion();
        $entrada = [
            'slug' => $slug, 'meta_titulo' => (string) $pagina['meta_titulo'], 'meta_descripcion' => (string) $pagina['meta_descripcion'],
            'keywords' => $pagina['keywords'], 'contexto' => $contexto !== null ? Str::limit(trim($contexto), 2000, '') : null,
        ];
        $registro = ['slug' => $slug, 'proveedor' => $config['proveedor'], 'modelo' => $config['proveedor'] === 'claude' ? $config['modelo'] : null,
            'entrada' => json_encode($entrada, JSON_UNESCAPED_UNICODE), 'usuario_id' => $usuario->id];
        try {
            $r = $this->generador($config['proveedor'])->sugerir($entrada, $config['modelo']);
        } catch (Throwable $e) {
            $mensaje = $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : 'Error del proveedor.';
            // Queda registrado aunque falle (la excepción se convierte en respuesta dentro de la transacción del CMS).
            DB::table('cms.seo_sugerencias')->insert($registro + ['error' => Str::limit((string) $mensaje, 300, '')]);
            throw $e;
        }
        DB::table('cms.seo_sugerencias')->insert($registro + [
            'modelo' => $r['modelo'] ?? $registro['modelo'], 'salida' => json_encode($r, JSON_UNESCAPED_UNICODE),
            'tokens_entrada' => $r['tokens_entrada'], 'tokens_salida' => $r['tokens_salida'],
        ]);

        return [
            'proveedor' => $config['proveedor'],
            'titulo' => $r['titulo'],
            'keywords' => $r['keywords'],
            'descripciones' => array_map(fn ($d) => ['texto' => $d, ...$this->analizarTexto($d)], $r['descripciones']),
        ];
    }

    /** Legibilidad, largo y duplicados de un título y una descripción (lo escrito o lo sugerido). */
    public function analizar(string $slug, string $titulo, string $descripcion): array
    {
        $this->seo->seo($slug);
        $otras = DB::table('cms.seo_paginas')->where('slug', '<>', $slug)->get(['slug', 'meta_titulo', 'meta_descripcion']);
        $parecido = function (string $a, string $b): bool {
            $a = mb_strtolower(trim($a));
            $b = mb_strtolower(trim($b));
            if ($a === '' || $b === '') {
                return false;
            }
            similar_text($a, $b, $porcentaje);

            return $a === $b || $porcentaje >= 85;
        };

        return [
            'titulo' => ['largo' => mb_strlen($titulo), 'ideal' => [30, 60], 'estado' => $this->estadoLargo(mb_strlen($titulo), 30, 60),
                'duplicado_en' => $otras->filter(fn ($o) => $parecido($titulo, (string) $o->meta_titulo))->pluck('slug')->values()->all()],
            'descripcion' => [...$this->analizarTexto($descripcion),
                'duplicado_en' => $otras->filter(fn ($o) => $parecido($descripcion, (string) $o->meta_descripcion))->pluck('slug')->values()->all()],
        ];
    }

    /** @return array{largo: int, ideal: array{int, int}, estado: string, legibilidad: int, nivel: string} */
    public function analizarTexto(string $texto): array
    {
        $l = self::legibilidad($texto);

        return ['largo' => mb_strlen($texto), 'ideal' => [120, 155], 'estado' => $this->estadoLargo(mb_strlen($texto), 120, 155), 'legibilidad' => $l, 'nivel' => self::nivel($l)];
    }

    private function estadoLargo(int $largo, int $min, int $max): string
    {
        return $largo === 0 ? 'vacio' : ($largo < $min ? 'corto' : ($largo > $max ? 'largo' : 'bien'));
    }

    /** Índice de Fernández-Huerta (0–100; más alto = más fácil). */
    public static function legibilidad(string $texto): int
    {
        $palabras = preg_split('/[^\p{L}]+/u', mb_strtolower($texto), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (! $palabras) {
            return 0;
        }
        $silabas = array_sum(array_map([self::class, 'silabas'], $palabras));
        $frases = max(1, preg_match_all('/[.!?¿¡…]+/u', $texto) ?: 1);
        $n = count($palabras);
        $indice = 206.84 - 0.60 * ($silabas * 100 / $n) - 1.02 * ($frases * 100 / $n);

        return (int) round(max(0, min(100, $indice)));
    }

    private static function silabas(string $palabra): int
    {
        // Núcleos vocálicos: dos vocales fuertes (a, e, o) o una débil acentuada forman hiato (dos sílabas).
        $grupos = preg_match_all('/[aeiouáéíóúü]+/u', $palabra, $m);
        $extra = 0;
        foreach ($m[0] as $g) {
            $extra += preg_match_all('/(?<=[aeoáéó])[aeoáéó]|[íú](?=[aeo])|(?<=[aeo])[íú]/u', $g);
        }

        return max(1, $grupos + $extra);
    }

    private static function nivel(int $l): string
    {
        return match (true) {
            $l >= 80 => 'fácil',
            $l >= 60 => 'normal',
            $l >= 40 => 'algo difícil',
            default => 'difícil',
        };
    }
}
