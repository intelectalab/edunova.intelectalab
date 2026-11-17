<?php

namespace App\Modulos\DOC\Services;

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\Auditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-117 / DOC-H05 · Plantillas de documentos con variables.
 *
 * - Variables documentadas (VARIABLES): {alumno.nombre}, {grado}, {fecha_larga}…; una variable
 *   desconocida no se acepta al guardar.
 * - Vista previa con datos reales de un alumno.
 * - Versionado: guardar crea una versión nueva (fila nueva, misma plantilla_origen_id) y desactiva
 *   la anterior; los documentos ya emitidos siguen apuntando a la versión con la que se emitieron.
 * - El HTML se limpia: solo etiquetas de formato, sin scripts, estilos externos ni imágenes remotas.
 */
class Plantillas
{
    public const TIPOS = ['constancia', 'certificado', 'carta', 'otro'];

    /** Variables disponibles y su descripción (se muestran en el editor). */
    public const VARIABLES = [
        'alumno.nombre' => 'Nombre completo del alumno',
        'alumno.nombres' => 'Nombres del alumno',
        'alumno.apellidos' => 'Apellidos del alumno',
        'alumno.documento' => 'Tipo y número de documento del alumno',
        'alumno.codigo_siagie' => 'Código de estudiante del SIAGIE',
        'alumno.fecha_nacimiento' => 'Fecha de nacimiento',
        'grado' => 'Grado del año en curso',
        'seccion' => 'Grado y sección (por ejemplo, «3.° B»)',
        'nivel' => 'Nivel (Inicial, Primaria o Secundaria)',
        'anio' => 'Año escolar',
        'apoderado.nombre' => 'Quien pidió el trámite',
        'colegio.nombre' => 'Nombre del colegio',
        'colegio.codigo_modular' => 'Código modular',
        'colegio.ugel' => 'UGEL',
        'colegio.direccion' => 'Dirección del colegio',
        'tramite.numero' => 'Número del trámite',
        'tramite.tipo' => 'Nombre del trámite',
        'firmante.nombre' => 'Quien firma',
        'firmante.cargo' => 'Cargo de quien firma',
        'fecha' => 'Fecha de emisión (dd/mm/aaaa)',
        'fecha_larga' => 'Fecha de emisión en letras («16 de noviembre de 2026»)',
    ];

    private const ETIQUETAS = '<p><br><b><strong><i><em><u><h1><h2><h3><h4><ul><ol><li><table><thead><tbody><tr><td><th><div><span><hr><small>';

    public function __construct(private Auditoria $auditoria) {}

    /** Versiones vigentes (una por plantilla) con su historial de versiones. */
    public function lista(): array
    {
        $todas = DB::table('plantillas_documento')->orderBy('nombre')->orderByDesc('version')
            ->get(['id', 'nombre', 'tipo', 'contenido_html', 'con_qr', 'version', 'activa', 'plantilla_origen_id', 'updated_at']);

        return $todas->where('activa', true)->values()->map(fn ($p) => array_merge((array) $p, [
            'versiones' => $todas->where('plantilla_origen_id', $p->plantilla_origen_id)->map(fn ($v) => ['id' => (int) $v->id, 'version' => (int) $v->version, 'updated_at' => $v->updated_at])->values()->all(),
            'en_uso' => DB::table('tramite_tipos')->where('plantilla_documento_id', $p->id)->where('activo', true)->count(),
        ]))->all();
    }

    /**
     * Crea una plantilla o guarda una versión nueva de la que se edita.
     *
     * @param  array{nombre: string, tipo: string, contenido_html: string, con_qr?: bool}  $d
     */
    public function guardar(?int $id, array $d, int $usuarioId): array
    {
        $html = $this->limpiar($d['contenido_html']);
        $desconocidas = array_diff($this->variablesUsadas($html), array_keys(self::VARIABLES));
        if ($desconocidas) {
            throw ValidationException::withMessages(['contenido_html' => ['Estas variables no existen: {'.implode('}, {', $desconocidas).'}.']]);
        }
        $inst = ContextoInstitucion::requerido();

        return DB::transaction(function () use ($id, $d, $html, $usuarioId, $inst) {
            $anterior = $id ? DB::table('plantillas_documento')->where('id', $id)->lockForUpdate()->first() : null;
            abort_if($id && ! $anterior, 404);
            if ($anterior && ! $anterior->activa) {
                throw ValidationException::withMessages(['plantilla' => ['Edita la versión vigente de la plantilla.']]);
            }
            $nuevo = (int) DB::table('plantillas_documento')->insertGetId([
                'institucion_id' => $inst, 'nombre' => trim($d['nombre']), 'tipo' => $d['tipo'], 'contenido_html' => $html,
                'con_qr' => (bool) ($d['con_qr'] ?? true), 'version' => $anterior ? (int) $anterior->version + 1 : 1, 'activa' => true, 'editado_por' => $usuarioId,
            ]);
            $origen = $anterior ? (int) $anterior->plantilla_origen_id : $nuevo;
            DB::table('plantillas_documento')->where('id', $nuevo)->update(['plantilla_origen_id' => $origen]);
            if ($anterior) {
                DB::table('plantillas_documento')->where('id', $anterior->id)->update(['activa' => false]);
                // Los trámites que usaban la versión anterior pasan a la nueva.
                DB::table('tramite_tipos')->where('plantilla_documento_id', $anterior->id)->update(['plantilla_documento_id' => $nuevo]);
            }
            $this->auditoria->registrar($anterior ? 'plantilla_version' : 'plantilla_creada', 'plantillas_documento', $nuevo,
                $anterior ? ['version' => (int) $anterior->version] : null, ['nombre' => trim($d['nombre']), 'version' => $anterior ? (int) $anterior->version + 1 : 1]);

            return (array) DB::table('plantillas_documento')->find($nuevo);
        });
    }

    /** Restaura una versión anterior como versión nueva. */
    public function restaurar(int $versionId, int $usuarioId): array
    {
        $v = DB::table('plantillas_documento')->find($versionId);
        abort_if(! $v, 404);
        $vigente = DB::table('plantillas_documento')->where('plantilla_origen_id', $v->plantilla_origen_id)->where('activa', true)->value('id');
        abort_if(! $vigente, 404);

        return $this->guardar((int) $vigente, ['nombre' => $v->nombre, 'tipo' => $v->tipo, 'contenido_html' => $v->contenido_html, 'con_qr' => (bool) $v->con_qr], $usuarioId);
    }

    public function desactivar(int $id): void
    {
        abort_if(! DB::table('plantillas_documento')->where('id', $id)->exists(), 404);
        if (DB::table('tramite_tipos')->where('plantilla_documento_id', $id)->where('activo', true)->exists()) {
            throw ValidationException::withMessages(['plantilla' => ['Un trámite activo usa esta plantilla: cámbiala en el catálogo antes de quitarla.']]);
        }
        DB::table('plantillas_documento')->where('id', $id)->update(['activa' => false]);
        $this->auditoria->registrar('plantilla_desactivada', 'plantillas_documento', $id, ['activa' => true], ['activa' => false]);
    }

    /**
     * Valores de las variables para un alumno (y, si hay, el trámite y quien firma).
     *
     * @return array<string, string>
     */
    public function valores(int $alumnoId, ?object $tramite = null, ?int $firmanteUserId = null): array
    {
        $a = DB::table('alumnos')->find($alumnoId);
        abort_if(! $a, 404, 'El alumno no existe.');
        $m = DB::table('matriculas as m')->join('secciones as s', 's.id', '=', 'm.seccion_id')->join('grados as g', 'g.id', '=', 's.grado_id')
            ->join('anios_escolares as y', 'y.id', '=', 'm.anio_escolar_id')->where('m.alumno_id', $alumnoId)->whereIn('m.estado', ['matriculado', 'reservado', 'retirado', 'trasladado'])
            ->orderByDesc('y.anio')->first(['g.nombre as grado', 's.nombre as seccion', 'g.nivel', 'y.anio']);
        $i = DB::table('instituciones')->where('id', ContextoInstitucion::requerido())->first(['nombre', 'codigo_modular', 'ugel', 'direccion']);
        $firmante = $firmanteUserId ? DB::table('personal')->where('user_id', $firmanteUserId)->first(['nombres', 'apellidos', 'cargo']) : null;
        $firmanteUsuario = $firmanteUserId && ! $firmante ? DB::table('users')->where('id', $firmanteUserId)->first(['nombres', 'apellidos']) : null;
        $pidio = $tramite ? DB::table('users')->where('id', $tramite->solicitado_por)->first(['nombres', 'apellidos']) : null;
        $hoy = now();
        $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        return [
            'alumno.nombre' => trim("{$a->nombres} {$a->apellido_paterno} {$a->apellido_materno}"),
            'alumno.nombres' => (string) $a->nombres,
            'alumno.apellidos' => trim("{$a->apellido_paterno} {$a->apellido_materno}"),
            'alumno.documento' => trim(($a->tipo_documento ?? 'DNI').' '.($a->numero_documento ?? '')),
            'alumno.codigo_siagie' => (string) ($a->codigo_estudiante_siagie ?? ''),
            'alumno.fecha_nacimiento' => $a->fecha_nacimiento ? date('d/m/Y', strtotime((string) $a->fecha_nacimiento)) : '',
            'grado' => (string) ($m->grado ?? ''),
            'seccion' => $m ? trim("{$m->grado} {$m->seccion}") : '',
            'nivel' => $m ? ucfirst((string) $m->nivel) : '',
            'anio' => (string) ($m->anio ?? $hoy->year),
            'apoderado.nombre' => $pidio ? trim("{$pidio->nombres} {$pidio->apellidos}") : '',
            'colegio.nombre' => (string) $i->nombre,
            'colegio.codigo_modular' => (string) ($i->codigo_modular ?? ''),
            'colegio.ugel' => (string) ($i->ugel ?? ''),
            'colegio.direccion' => (string) ($i->direccion ?? ''),
            'tramite.numero' => (string) ($tramite->numero ?? ''),
            'tramite.tipo' => $tramite ? (string) DB::table('tramite_tipos')->where('id', $tramite->tramite_tipo_id)->value('nombre') : '',
            'firmante.nombre' => $firmante ? trim("{$firmante->nombres} {$firmante->apellidos}") : ($firmanteUsuario ? trim("{$firmanteUsuario->nombres} {$firmanteUsuario->apellidos}") : ''),
            'firmante.cargo' => (string) ($firmante->cargo ?? 'Director(a)'),
            'fecha' => $hoy->format('d/m/Y'),
            'fecha_larga' => $hoy->day.' de '.$meses[$hoy->month - 1].' de '.$hoy->year,
        ];
    }

    /** Reemplaza las variables (con los valores escapados) en el HTML de la plantilla. */
    public function rellenar(string $html, array $valores): string
    {
        return preg_replace_callback('/\{\s*([a-z_]+(?:\.[a-z_]+)?)\s*\}/', fn ($m) => array_key_exists($m[1], $valores)
            ? htmlspecialchars($valores[$m[1]], ENT_QUOTES, 'UTF-8') : $m[0], $html);
    }

    /** @return list<string> */
    public function variablesUsadas(string $html): array
    {
        preg_match_all('/\{\s*([a-z_]+(?:\.[a-z_]+)?)\s*\}/', $html, $m);

        return array_values(array_unique($m[1]));
    }

    /** Deja solo etiquetas de formato y quita atributos peligrosos (on*, javascript:, estilos con url()). */
    public function limpiar(string $html): string
    {
        $html = preg_replace('#<(script|style|iframe|object|embed)[^>]*>.*?</\1>#is', '', $html) ?? '';
        $html = strip_tags($html, self::ETIQUETAS);
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/(href|src)\s*=\s*("|\')?\s*javascript:[^"\'>\s]*("|\')?/i', '', $html) ?? '';
        $html = preg_replace('/url\s*\([^)]*\)/i', '', $html) ?? '';

        return trim($html);
    }
}
