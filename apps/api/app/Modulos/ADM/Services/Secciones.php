<?php

namespace App\Modulos\ADM\Services;

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\Auditoria;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-46 / ADM-H04 · Secciones del año: nombre, turno, aula (ambiente), tutor y vacantes.
 * También los ambientes (aulas) del local principal y la copia de secciones del año anterior.
 */
class Secciones
{
    public function __construct(private Auditoria $auditoria) {}

    public function listar(int $anioId): array
    {
        PlanEstudios::anio($anioId);
        $matriculados = DB::table('matriculas')->where('anio_escolar_id', $anioId)->where('estado', 'matriculado')
            ->groupBy('seccion_id')->select('seccion_id', DB::raw('count(*) as n'))->pluck('n', 'seccion_id');

        return DB::table('secciones as s')
            ->join('grados as g', 'g.id', '=', 's.grado_id')
            ->leftJoin('ambientes as a', 'a.id', '=', 's.ambiente_id')
            ->leftJoin('personal as p', 'p.id', '=', 's.tutor_personal_id')
            ->where('s.anio_escolar_id', $anioId)
            ->orderByRaw("case g.nivel when 'inicial' then 1 when 'primaria' then 2 else 3 end")->orderBy('g.numero')->orderBy('s.nombre')
            ->get(['s.id', 's.grado_id', 'g.nombre as grado', 'g.nivel', 's.nombre', 's.turno', 's.ambiente_id', 'a.nombre as ambiente',
                's.tutor_personal_id', DB::raw("p.nombres || ' ' || p.apellidos as tutor"), 's.vacantes'])
            ->map(fn ($s) => (array) $s + ['matriculados' => (int) ($matriculados[$s->id] ?? 0)])->all();
    }

    /** Opciones para el formulario: grados, ambientes y personal docente activo. */
    public function opciones(): array
    {
        return [
            'grados' => DB::table('grados')->orderByRaw("case nivel when 'inicial' then 1 when 'primaria' then 2 else 3 end")->orderBy('numero')->get(['id', 'nombre', 'nivel']),
            'ambientes' => DB::table('ambientes')->orderBy('nombre')->get(['id', 'nombre', 'tipo', 'capacidad']),
            'personal' => DB::table('personal')->where('estado', 'activo')->orderBy('apellidos')->get(['id', DB::raw("nombres || ' ' || apellidos as nombre"), 'cargo']),
        ];
    }

    /** @param array{anio_escolar_id: int, grado_id: int, nombre: string, turno: string, ambiente_id?: int|null, tutor_personal_id?: int|null, vacantes?: int|null} $d */
    public function guardar(array $d, ?int $id = null): int
    {
        PlanEstudios::anio((int) $d['anio_escolar_id']);
        abort_if(! DB::table('grados')->where('id', $d['grado_id'])->exists(), 422, 'Elige un grado del colegio.');
        $fila = [
            'anio_escolar_id' => $d['anio_escolar_id'], 'grado_id' => $d['grado_id'], 'nombre' => mb_strtoupper(trim($d['nombre'])),
            'turno' => $d['turno'], 'ambiente_id' => $d['ambiente_id'] ?? null, 'tutor_personal_id' => $d['tutor_personal_id'] ?? null,
            'vacantes' => $d['vacantes'] ?? null,
        ];
        if ($fila['vacantes'] !== null) {
            $matriculados = $id ? DB::table('matriculas')->where('seccion_id', $id)->where('estado', 'matriculado')->count() : 0;
            if ($fila['vacantes'] < $matriculados) {
                throw ValidationException::withMessages(['vacantes' => ["La sección ya tiene {$matriculados} matriculados."]]);
            }
        }
        try {
            if ($id) {
                $antes = (array) DB::table('secciones')->find($id);
                abort_if(! $antes, 404);
                DB::table('secciones')->where('id', $id)->update($fila);
                $this->auditoria->registrar('seccion_editada', 'secciones', $id, array_intersect_key($antes, $fila), $fila);

                return $id;
            }
            $nuevo = (int) DB::table('secciones')->insertGetId($fila + ['institucion_id' => ContextoInstitucion::requerido()]);
            $this->auditoria->registrar('seccion_creada', 'secciones', $nuevo, null, $fila);

            return $nuevo;
        } catch (QueryException $e) {
            throw match ($e->getCode()) {
                '23505' => ValidationException::withMessages(['nombre' => ["Ya existe la sección {$fila['nombre']} en ese grado y año."]]),
                '23503' => ValidationException::withMessages(['ambiente_id' => ['El aula o el tutor no son de este colegio.']]),
                default => $e,
            };
        }
    }

    public function eliminar(int $id): void
    {
        $seccion = DB::table('secciones')->find($id);
        abort_if(! $seccion, 404);
        if (DB::table('matriculas')->where('seccion_id', $id)->exists()) {
            throw ValidationException::withMessages(['seccion' => ['La sección tiene alumnos matriculados: no se puede borrar.']]);
        }
        DB::table('secciones')->where('id', $id)->delete();
        $this->auditoria->registrar('seccion_eliminada', 'secciones', $id, (array) $seccion, null);
    }

    /** Copia las secciones (grado, nombre, turno, aula, tutor y vacantes) que no existan en el año destino. */
    public function copiar(int $desde, int $hacia): array
    {
        PlanEstudios::anio($desde);
        PlanEstudios::anio($hacia);
        if ($desde === $hacia) {
            throw ValidationException::withMessages(['desde_anio_id' => ['Elige un año distinto.']]);
        }
        $institucion = ContextoInstitucion::requerido();
        $creadas = 0;
        foreach (DB::table('secciones')->where('anio_escolar_id', $desde)->get() as $s) {
            $creadas += DB::table('secciones')->insertOrIgnore([
                'institucion_id' => $institucion, 'anio_escolar_id' => $hacia, 'grado_id' => $s->grado_id, 'nombre' => $s->nombre,
                'turno' => $s->turno, 'ambiente_id' => $s->ambiente_id, 'tutor_personal_id' => $s->tutor_personal_id, 'vacantes' => $s->vacantes,
            ]);
        }
        $this->auditoria->registrar('secciones_copiadas', 'secciones', null, ['anio_escolar_id' => $desde], ['anio_escolar_id' => $hacia, 'secciones' => $creadas]);

        return ['creadas' => $creadas];
    }

    /** Aula nueva en el local principal (lo crea si el colegio aún no tiene locales). */
    public function crearAmbiente(string $nombre, string $tipo, ?int $capacidad): array
    {
        $institucion = ContextoInstitucion::requerido();
        $sede = DB::table('sedes')->orderByDesc('principal')->orderBy('id')->value('id')
            ?? DB::table('sedes')->insertGetId(['institucion_id' => $institucion, 'nombre' => 'Local principal', 'principal' => true]);
        if (DB::table('ambientes')->whereRaw('lower(nombre) = ?', [mb_strtolower(trim($nombre))])->exists()) {
            throw ValidationException::withMessages(['nombre' => ['Ya existe un ambiente con ese nombre.']]);
        }
        $id = DB::table('ambientes')->insertGetId(['institucion_id' => $institucion, 'sede_id' => $sede, 'nombre' => trim($nombre), 'tipo' => $tipo, 'capacidad' => $capacidad]);

        return (array) DB::table('ambientes')->find($id, ['id', 'nombre', 'tipo', 'capacidad']);
    }
}
