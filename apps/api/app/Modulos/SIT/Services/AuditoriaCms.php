<?php

namespace App\Modulos\SIT\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * EDUN-342 / SIT-061 · Auditoría de cambios del Panel CMS: indicadores, filtros (texto,
 * usuario, sección y acción), tabla con antes → después y exportación CSV. La auditoría es de
 * solo inserción (trigger de la base); restaurar lo hace EditorCms::restaurar.
 */
class AuditoriaCms
{
    public const POR_PAGINA = 15;

    public const ACCIONES = ['editado' => 'Editado', 'borrador' => 'Borrador', 'publicado' => 'Publicado', 'ocultado' => 'Ocultado', 'restaurado' => 'Restaurado', 'eliminado' => 'Eliminado'];

    /** Nombres de las secciones que no son de la landing. */
    private const OTRAS = [
        'leads' => 'Bandeja de solicitudes', 'reclamaciones' => 'Libro de Reclamaciones', 'seo' => 'Ajustes SEO',
        'politicas' => 'Políticas legales', 'medios' => 'Biblioteca de medios',
    ];

    /** @param  array{q?: ?string, usuario?: ?int, seccion?: ?string, accion?: ?string, pagina?: int}  $f */
    public function listar(array $f): array
    {
        $pagina = max(1, (int) ($f['pagina'] ?? 1));
        $q = $this->consulta($f);
        $total = (clone $q)->count();

        $filas = $q->orderByDesc('a.created_at')->orderByDesc('a.id')
            ->offset(($pagina - 1) * self::POR_PAGINA)->limit(self::POR_PAGINA)
            ->get(['a.id', 'a.created_at', 'a.usuario_rol', 'a.seccion', 'a.accion', 'a.entidad', 'a.entidad_id', 'a.campo', 'a.detalle', 'a.valor_antes', 'a.valor_despues', DB::raw($this->nombreUsuario().' as usuario')])
            ->map(fn ($a) => [
                ...(array) $a,
                'valor_antes' => json_decode((string) $a->valor_antes, true),
                'valor_despues' => json_decode((string) $a->valor_despues, true),
                'restaurable' => $a->entidad === 'secciones' && $a->valor_antes !== null && in_array($a->accion, ['borrador', 'publicado', 'restaurado'], true)
                    && ReglasSecciones::para($a->entidad_id) !== null,
            ]);

        return [
            'data' => $filas,
            'pagina' => $pagina,
            'por_pagina' => self::POR_PAGINA,
            'total' => $total,
            'paginas' => max(1, (int) ceil($total / self::POR_PAGINA)),
            'indicadores' => $this->indicadores(),
            'secciones' => $this->secciones(),
            'usuarios' => DB::table('cms.auditoria as a')->join('users as u', 'u.id', '=', 'a.usuario_id')->distinct()
                ->get(['u.id', DB::raw($this->nombreUsuario().' as nombre')])->sortBy('nombre')->values(),
            'acciones' => self::ACCIONES,
        ];
    }

    /** CSV con los mismos filtros (UTF-8 con BOM, celdas protegidas contra fórmulas). */
    public function csv(array $f): string
    {
        $filas = $this->consulta($f)->orderByDesc('a.created_at')->orderByDesc('a.id')->limit(20000)
            ->get(['a.created_at', 'a.usuario_rol', 'a.seccion', 'a.accion', 'a.detalle', 'a.campo', 'a.valor_antes', 'a.valor_despues', 'a.ip', DB::raw($this->nombreUsuario().' as usuario')]);
        $secciones = $this->secciones();

        $salida = fopen('php://temp', 'r+');
        fwrite($salida, "\u{FEFF}");
        fputcsv($salida, ['Fecha (hora de Lima)', 'Usuario', 'Rol', 'Sección', 'Acción', 'Detalle', 'Campo', 'Antes', 'Después', 'IP'], ',', '"', '');
        foreach ($filas as $a) {
            fputcsv($salida, array_map(self::celda(...), [
                CarbonImmutable::parse($a->created_at)->setTimezone('America/Lima')->format('Y-m-d H:i:s'),
                $a->usuario, $a->usuario_rol, $secciones[$a->seccion] ?? $a->seccion, self::ACCIONES[$a->accion] ?? $a->accion,
                $a->detalle, $a->campo, mb_substr((string) $a->valor_antes, 0, 2000), mb_substr((string) $a->valor_despues, 0, 2000), $a->ip,
            ]), ',', '"', '');
        }
        rewind($salida);
        $csv = (string) stream_get_contents($salida);
        fclose($salida);

        return $csv;
    }

    /** @return array<string, string> clave → nombre visible */
    public function secciones(): array
    {
        $landing = DB::table('cms.secciones')->pluck('nombre', 'clave')->all();
        $usadas = DB::table('cms.auditoria')->distinct()->pluck('seccion')->all();

        return collect($usadas)->mapWithKeys(fn ($s) => [$s => $landing[$s] ?? self::OTRAS[$s] ?? $s])->sort()->all();
    }

    private function indicadores(): array
    {
        $hoy = CarbonImmutable::now('America/Lima')->startOfDay();

        return [
            'cambios_hoy' => DB::table('cms.auditoria')->where('created_at', '>=', $hoy)->count(),
            'publicaciones_7d' => DB::table('cms.auditoria')->where('accion', 'publicado')->where('created_at', '>=', $hoy->subDays(6))->count(),
            'usuarios_con_acceso' => count(DB::select('select id from cms.usuarios_cms()')),
            'versiones' => DB::table('cms.versiones')->count(),
        ];
    }

    private function consulta(array $f): Builder
    {
        return DB::table('cms.auditoria as a')->leftJoin('users as u', 'u.id', '=', 'a.usuario_id')
            ->when(! empty($f['usuario']), fn ($q) => $q->where('a.usuario_id', $f['usuario']))
            ->when(! empty($f['seccion']), fn ($q) => $q->where('a.seccion', $f['seccion']))
            ->when(! empty($f['accion']), fn ($q) => $q->where('a.accion', $f['accion']))
            ->when(! empty($f['q']), function ($q) use ($f) {
                $texto = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($f['q'])).'%';
                $q->where(fn ($w) => $w->where('a.detalle', 'ilike', $texto)->orWhere('a.campo', 'ilike', $texto)
                    ->orWhereRaw('a.valor_despues::text ilike ?', [$texto])->orWhereRaw('a.valor_antes::text ilike ?', [$texto]));
            });
    }

    private function nombreUsuario(): string
    {
        return "nullif(trim(concat_ws(' ', u.nombres, u.apellidos)), '')";
    }

    private static function celda(mixed $v): string
    {
        $v = $v === null ? '' : (string) $v;

        return preg_match('/^[=+\-@\t\r]/', $v) === 1 ? "'".$v : $v;
    }
}
