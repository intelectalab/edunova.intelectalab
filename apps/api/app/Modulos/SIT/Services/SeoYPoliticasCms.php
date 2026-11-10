<?php

namespace App\Modulos\SIT\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-314 / SIT-057 · Ajustes SEO por página y EDUN-395 / SIT-032 · políticas legales con
 * versiones. Ambas pantallas publican al guardar («Guardar y actualizar metadatos» / «Publicar
 * versión»): dejan versión, auditoría y piden al sitio que se revalide.
 * Corre dentro de la transacción del middleware «cms» (app.rol_cms = superadmin).
 */
class SeoYPoliticasCms
{
    public const ROBOTS = ['index,follow', 'noindex,follow', 'noindex,nofollow'];

    public const TIPOS_POLITICA = ['privacidad' => 'Política de Privacidad', 'terminos' => 'Términos de Servicio', 'seguridad' => 'Seguridad de Datos', 'cookies' => 'Política de Cookies'];

    public const VERSIONES_VISIBLES = 5;

    public function __construct(private EditorCms $editor, private Politicas $politicas) {}

    // --- SEO (EDUN-314) ------------------------------------------------------------------

    public function paginasSeo(): array
    {
        return DB::table('cms.seo_paginas')->orderByRaw("slug = 'inicio' desc")->orderBy('slug')
            ->get(['slug', 'meta_titulo', 'robots', 'updated_at'])->all();
    }

    public function seo(string $slug): array
    {
        $p = DB::table('cms.seo_paginas as s')->leftJoin('cms.medios as m', 'm.id', '=', 's.og_imagen_id')->where('s.slug', $slug)
            ->first(['s.slug', 's.meta_titulo', 's.meta_descripcion', 's.canonical_url', 's.robots', 's.keywords', 's.og_titulo', 's.og_descripcion',
                's.og_imagen_id', 'm.url as og_imagen_url', 'm.alt as og_imagen_alt', 'm.ancho as og_imagen_ancho', 'm.alto as og_imagen_alto', 's.updated_at'])
            ?? abort(404, 'No encontramos esa página.');

        return [...(array) $p, 'keywords' => self::arregloPg($p->keywords)];
    }

    public static function reglasSeo(): array
    {
        return [
            'meta_titulo' => ['required', 'string', 'max:70'],
            'meta_descripcion' => ['required', 'string', 'max:160'],
            'canonical_url' => ['nullable', 'url:https', 'max:300'],
            'robots' => ['required', Rule::in(self::ROBOTS)],
            'keywords' => ['present', 'array', 'max:15'],
            'keywords.*' => ['string', 'max:60', 'distinct'],
            'og_titulo' => ['nullable', 'string', 'max:90'],
            'og_descripcion' => ['nullable', 'string', 'max:200'],
            'og_imagen_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function guardarSeo(string $slug, array $d, User $usuario, ?string $ip): array
    {
        $antes = $this->seo($slug);
        if (! empty($d['og_imagen_id']) && ! DB::table('cms.medios')->where('id', $d['og_imagen_id'])->whereIn('tipo', ['imagen'])->exists()) {
            throw ValidationException::withMessages(['og_imagen_id' => 'Elige una imagen JPG, PNG o WebP de la Biblioteca (1200 × 630).']);
        }
        $fila = [
            'meta_titulo' => trim($d['meta_titulo']),
            'meta_descripcion' => trim($d['meta_descripcion']),
            'canonical_url' => $d['canonical_url'] ?? null,
            'robots' => $d['robots'],
            'keywords' => '{'.collect($d['keywords'])->map(fn ($k) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], trim($k)).'"')->filter(fn ($k) => $k !== '""')->implode(',').'}',
            'og_titulo' => $d['og_titulo'] ?? null,
            'og_descripcion' => $d['og_descripcion'] ?? null,
            'og_imagen_id' => $d['og_imagen_id'] ?? null,
            'estado' => 'publicado',
            'updated_at' => now(),
        ];
        DB::table('cms.seo_paginas')->where('slug', $slug)->update($fila);
        $despues = $this->seo($slug);

        $this->registrar($usuario, $ip, 'seo', 'seo_paginas', $slug, "Ajustes SEO · {$slug}", $antes, $despues);
        DB::afterCommit(fn () => $this->editor->revalidarSitio(['landing']));

        return $despues;
    }

    // --- Políticas (EDUN-395) ------------------------------------------------------------

    public function politicas(): array
    {
        $hoy = CarbonImmutable::now('America/Lima')->toDateString();

        return collect(self::TIPOS_POLITICA)->map(function ($nombre, $tipo) use ($hoy) {
            $vigente = $this->politicas->vigente($tipo);
            $versiones = DB::table('cms.politicas')->where('tipo', $tipo)
                ->orderByDesc('vigente_desde')->orderByDesc('created_at')->orderByDesc('id')->limit(self::VERSIONES_VISIBLES)
                ->get(['id', 'version', 'vigente_desde', 'borrador', 'created_at', 'contenido'])
                ->map(fn ($v) => [
                    ...(array) $v,
                    'borrador' => (bool) $v->borrador,
                    'estado' => $vigente && $v->version === $vigente->version ? 'vigente' : ($v->vigente_desde > $hoy ? 'programada' : 'anterior'),
                ]);

            return ['tipo' => $tipo, 'nombre' => $nombre, 'versiones' => $versiones];
        })->values()->all();
    }

    public static function reglasPolitica(): array
    {
        return [
            'version' => ['required', 'string', 'max:15', 'regex:/^\d{1,3}(\.\d{1,3}){1,2}$/'],
            'vigente_desde' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.CarbonImmutable::now('America/Lima')->toDateString()],
            'contenido' => ['required', 'string', 'min:200', 'max:100000'],
            'revision_pendiente' => ['required', 'boolean'],
        ];
    }

    /** Publica una versión nueva (las versiones no se editan: son prueba de lo que aceptó cada persona). */
    public function publicarPolitica(string $tipo, array $d, User $usuario, ?string $ip): array
    {
        if (DB::table('cms.politicas')->where('tipo', $tipo)->where('version', $d['version'])->exists()) {
            throw ValidationException::withMessages(['version' => "La versión {$d['version']} ya existe. Usa un número nuevo."]);
        }
        $html = $this->politicas->limpiar($d['contenido']);
        if (mb_strlen(trim(strip_tags($html))) < 200) {
            throw ValidationException::withMessages(['contenido' => 'El texto de la política es demasiado corto (mínimo 200 caracteres).']);
        }
        $antes = $this->politicas->vigente($tipo);
        DB::table('cms.politicas')->insert([
            'tipo' => $tipo,
            'version' => $d['version'],
            'contenido' => $html,
            'vigente_desde' => $d['vigente_desde'],
            'borrador' => (bool) $d['revision_pendiente'],
        ]);
        $nombre = self::TIPOS_POLITICA[$tipo];
        $this->registrar($usuario, $ip, 'politicas', 'politicas', $tipo, "{$nombre} · versión {$d['version']} desde {$d['vigente_desde']}",
            $antes ? ['version' => $antes->version, 'vigente_desde' => $antes->vigente_desde] : null,
            ['version' => $d['version'], 'vigente_desde' => $d['vigente_desde'], 'revision_pendiente' => (bool) $d['revision_pendiente'], 'contenido' => $html]);
        DB::afterCommit(fn () => $this->editor->revalidarSitio(['politicas']));

        return collect($this->politicas())->firstWhere('tipo', $tipo);
    }

    // -------------------------------------------------------------------------------------

    private function registrar(User $usuario, ?string $ip, string $seccion, string $entidad, string $id, string $detalle, mixed $antes, mixed $despues): void
    {
        DB::table('cms.versiones')->insert([
            'seccion' => $seccion, 'entidad' => $entidad, 'entidad_id' => $id,
            'contenido' => json_encode($despues, JSON_UNESCAPED_UNICODE), 'publicado_por' => $usuario->id,
        ]);
        DB::table('cms.auditoria')->insert([
            'usuario_id' => $usuario->id,
            'usuario_rol' => 'superadmin',
            'seccion' => $seccion,
            'accion' => 'publicado',
            'entidad' => $entidad,
            'entidad_id' => $id,
            'detalle' => Str::limit($detalle, 250, ''),
            'valor_antes' => $antes === null ? null : json_encode($antes, JSON_UNESCAPED_UNICODE),
            'valor_despues' => json_encode($despues, JSON_UNESCAPED_UNICODE),
            'ip' => $ip,
        ]);
    }

    /** text[] de PostgreSQL ('{a,"b c"}') → arreglo de PHP. */
    private static function arregloPg(?string $v): array
    {
        if ($v === null || $v === '{}') {
            return [];
        }
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|([^,{}]+)/', $v, $m, PREG_SET_ORDER);

        return array_map(fn ($x) => isset($x[2]) && $x[2] !== '' ? $x[2] : stripcslashes($x[1]), $m);
    }
}
