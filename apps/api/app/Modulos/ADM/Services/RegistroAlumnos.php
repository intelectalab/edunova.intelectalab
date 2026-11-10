<?php

namespace App\Modulos\ADM\Services;

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\ActivacionFamilias;
use App\Modulos\SEG\Services\Auditoria;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-50 / ADM-H08 · Alumno con sus apoderados (parentesco, responsable de pago) y los
 * autorizados a recoger. Opcionalmente queda matriculado en una sección del año.
 *
 * - DNI de 8 dígitos y código SIAGIE de 14 (también lo exige la base).
 * - Un apoderado es una persona del colegio: si ya existe por su documento se reutiliza
 *   (hermanos comparten apoderado).
 * - Con apoderados, al menos uno es responsable de pago.
 */
class RegistroAlumnos
{
    public const PARENTESCOS = ['padre', 'madre', 'abuelo', 'abuela', 'tio', 'tia', 'hermano', 'hermana', 'tutor_legal', 'otro'];

    public const CAMPOS_ALUMNO = ['codigo_estudiante_siagie', 'tipo_documento', 'numero_documento', 'apellido_paterno', 'apellido_materno', 'nombres', 'sexo', 'fecha_nacimiento'];

    /** @var list<array<string, mixed>> invitaciones enviadas a los apoderados en el último guardado (EDUN-37) */
    public array $invitaciones = [];

    public function __construct(private Auditoria $auditoria, private ActivacionFamilias $familias) {}

    /** @param array{buscar?: string|null, anio_escolar_id?: int|null, seccion_id?: int|null, estado?: string|null} $filtros */
    /** @param  Builder|list<int>|null  $soloIds  alumnos que el usuario puede ver (null: todos) */
    public function listar(array $filtros, mixed $soloIds = null): array
    {
        $anio = $filtros['anio_escolar_id'] ?? DB::table('anios_escolares')->orderByRaw("estado = 'abierto' desc")->orderByDesc('anio')->value('id');

        return DB::table('alumnos as a')
            ->leftJoin('matriculas as m', fn ($j) => $j->on('m.alumno_id', '=', 'a.id')->where('m.anio_escolar_id', '=', $anio))
            ->leftJoin('secciones as s', 's.id', '=', 'm.seccion_id')
            ->leftJoin('grados as g', 'g.id', '=', 's.grado_id')
            ->whereNull('a.eliminado_at')
            ->when($soloIds !== null, fn ($q) => $q->whereIn('a.id', $soloIds))
            ->when($filtros['buscar'] ?? null, function ($q, $v) {
                $t = '%'.mb_strtolower(trim($v)).'%';
                $q->where(fn ($w) => $w->whereRaw("lower(a.apellido_paterno || ' ' || coalesce(a.apellido_materno, '') || ' ' || a.nombres) like ?", [$t])
                    ->orWhere('a.numero_documento', 'like', $t)->orWhere('a.codigo_estudiante_siagie', 'like', $t));
            })
            ->when($filtros['seccion_id'] ?? null, fn ($q, $v) => $q->where('m.seccion_id', $v))
            ->when($filtros['estado'] ?? null, fn ($q, $v) => $q->where('a.estado', $v))
            ->orderBy('a.apellido_paterno')->orderBy('a.apellido_materno')->orderBy('a.nombres')
            ->limit(500)
            ->get(['a.id', 'a.apellido_paterno', 'a.apellido_materno', 'a.nombres', 'a.numero_documento', 'a.codigo_estudiante_siagie', 'a.estado',
                'm.estado as matricula', DB::raw("g.nombre || ' ' || s.nombre as seccion"), 's.id as seccion_id'])
            ->map(fn ($a) => (array) $a)->all();
    }

    public function ficha(int $id): array
    {
        $a = DB::table('alumnos')->whereNull('eliminado_at')->find($id);
        abort_if(! $a, 404);

        return [
            ...collect(self::CAMPOS_ALUMNO)->mapWithKeys(fn ($c) => [$c => $a->{$c} === null ? null : trim((string) $a->{$c})])->all(),
            'id' => (int) $a->id, 'estado' => $a->estado,
            'apoderados' => DB::table('alumno_apoderado as aa')->join('apoderados as p', 'p.id', '=', 'aa.apoderado_id')->where('aa.alumno_id', $id)
                ->orderByDesc('aa.es_responsable_pago')->orderBy('p.apellidos')
                ->get(['p.id', 'p.tipo_documento', 'p.numero_documento', 'p.apellidos', 'p.nombres', 'p.celular', 'p.email', 'p.direccion', 'p.ocupacion',
                    'aa.parentesco', 'aa.es_responsable_pago', 'aa.puede_recoger', 'aa.recibe_notificaciones'])->map(fn ($p) => (array) $p)->all(),
            'autorizados' => DB::table('por_autorizados')->where('alumno_id', $id)->where('activo', true)->orderBy('nombres')
                ->get(['id', 'nombres', 'numero_documento', 'parentesco', 'vigente_hasta'])->map(fn ($p) => (array) $p)->all(),
            'matriculas' => DB::table('matriculas as m')->join('anios_escolares as y', 'y.id', '=', 'm.anio_escolar_id')
                ->join('secciones as s', 's.id', '=', 'm.seccion_id')->join('grados as g', 'g.id', '=', 's.grado_id')
                ->where('m.alumno_id', $id)->orderByDesc('y.anio')
                ->get(['m.id', 'y.anio', 'm.anio_escolar_id', 'm.seccion_id', DB::raw("g.nombre || ' ' || s.nombre as seccion"), 'm.estado', 'm.tipo', 'm.fecha_matricula'])
                ->map(fn ($m) => (array) $m)->all(),
        ];
    }

    /**
     * Crea o actualiza el alumno con sus apoderados y autorizados (y la matrícula, si viene).
     *
     * @param  array<string, mixed>  $d
     */
    public function guardar(array $d, ?int $id = null): int
    {
        $apoderados = $d['apoderados'] ?? [];
        if ($apoderados !== [] && ! collect($apoderados)->contains(fn ($p) => ! empty($p['es_responsable_pago']))) {
            throw ValidationException::withMessages(['apoderados' => ['Marca al menos un apoderado como responsable de pago.']]);
        }
        foreach ($apoderados as $p) {
            if (($p['tipo_documento'] ?? 'DNI') === 'DNI' && ! preg_match('/^\d{8}$/', (string) $p['numero_documento'])) {
                throw ValidationException::withMessages(['apoderados' => ["El DNI de {$p['nombres']} debe tener 8 dígitos."]]);
            }
        }
        $docs = array_map(fn ($p) => ($p['tipo_documento'] ?? 'DNI').'-'.$p['numero_documento'], $apoderados);
        if (count($docs) !== count(array_unique($docs))) {
            throw ValidationException::withMessages(['apoderados' => ['Hay un apoderado repetido.']]);
        }
        $institucion = ContextoInstitucion::requerido();
        $fila = collect(self::CAMPOS_ALUMNO)->mapWithKeys(fn ($c) => [$c => $d[$c] ?? null])->all();
        $fila['tipo_documento'] ??= 'DNI';
        foreach (['apellido_paterno', 'apellido_materno', 'nombres'] as $c) {
            $fila[$c] = $fila[$c] === null ? null : trim((string) $fila[$c]);
        }

        try {
            return DB::transaction(function () use ($id, $fila, $institucion, $apoderados, $d) {
                $antes = $id ? $this->ficha($id) : null;
                if ($id) {
                    DB::table('alumnos')->where('id', $id)->update($fila);
                } else {
                    $id = (int) DB::table('alumnos')->insertGetId($fila + ['institucion_id' => $institucion]);
                }

                // Apoderados: se reutilizan por documento dentro del colegio.
                $vinculados = array_map(fn ($p) => $this->vincularApoderado($id, $p), $apoderados);
                if (array_key_exists('apoderados', $d)) {
                    DB::table('alumno_apoderado')->where('alumno_id', $id)->whereNotIn('apoderado_id', $vinculados)->delete();
                }

                // Autorizados a recoger (además de los apoderados que pueden recoger).
                if (array_key_exists('autorizados', $d)) {
                    $docsAut = array_column($d['autorizados'] ?? [], 'numero_documento');
                    DB::table('por_autorizados')->where('alumno_id', $id)->whereNotIn('numero_documento', $docsAut)->update(['activo' => false]);
                    foreach ($d['autorizados'] ?? [] as $a) {
                        DB::table('por_autorizados')->upsert(
                            [['institucion_id' => $institucion, 'alumno_id' => $id, 'nombres' => trim($a['nombres']), 'numero_documento' => $a['numero_documento'],
                                'parentesco' => $a['parentesco'], 'vigente_hasta' => $a['vigente_hasta'] ?? null, 'activo' => true]],
                            ['alumno_id', 'numero_documento'],
                            ['nombres', 'parentesco', 'vigente_hasta', 'activo'],
                        );
                    }
                }

                if (! empty($d['seccion_id'])) {
                    $this->matricular($id, (int) $d['seccion_id'], $d['tipo_matricula'] ?? 'nuevo');
                }

                $despues = $this->ficha($id);
                $this->auditoria->registrar($antes ? 'alumno_editado' : 'alumno_registrado', 'alumnos', $id, $antes ? self::resumen($antes) : null, self::resumen($despues));
                // EDUN-37 · Al final (si algo falla antes, no sale ninguna invitación): los apoderados
                // con correo o celular reciben su acceso a Edunova.
                $this->invitaciones = $this->activarApoderados($vinculados);

                return $id;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                $msg = str_contains($e->getMessage(), 'codigo_estudiante_siagie_key') ? ['codigo_estudiante_siagie' => ['Ya hay un alumno con ese código SIAGIE.']]
                    : ['numero_documento' => ['Ya hay un alumno con ese documento.']];
                throw ValidationException::withMessages($msg);
            }
            throw $e;
        }
    }

    /**
     * Crea o actualiza el apoderado (por su documento, dentro del colegio) y lo vincula al alumno.
     *
     * @param  array<string, mixed>  $p
     */
    public function vincularApoderado(int $alumnoId, array $p): int
    {
        $institucion = ContextoInstitucion::requerido();
        $datos = [
            'apellidos' => trim($p['apellidos']), 'nombres' => trim($p['nombres']), 'celular' => $p['celular'] ?? null,
            'email' => isset($p['email']) ? mb_strtolower(trim($p['email'])) : null, 'direccion' => $p['direccion'] ?? null, 'ocupacion' => $p['ocupacion'] ?? null,
        ];
        $clave = ['tipo_documento' => $p['tipo_documento'] ?? 'DNI', 'numero_documento' => $p['numero_documento']];
        $apoderadoId = DB::table('apoderados')->where($clave)->value('id');
        if ($apoderadoId) {
            DB::table('apoderados')->where('id', $apoderadoId)->update(array_filter($datos, fn ($v) => $v !== null));
        } else {
            $apoderadoId = DB::table('apoderados')->insertGetId($clave + $datos + ['institucion_id' => $institucion]);
        }
        DB::table('alumno_apoderado')->upsert(
            [['institucion_id' => $institucion, 'alumno_id' => $alumnoId, 'apoderado_id' => $apoderadoId, 'parentesco' => $p['parentesco'],
                'es_responsable_pago' => (bool) ($p['es_responsable_pago'] ?? false), 'puede_recoger' => (bool) ($p['puede_recoger'] ?? true),
                'recibe_notificaciones' => (bool) ($p['recibe_notificaciones'] ?? true)]],
            ['alumno_id', 'apoderado_id'],
            ['parentesco', 'es_responsable_pago', 'puede_recoger', 'recibe_notificaciones'],
        );

        return (int) $apoderadoId;
    }

    /**
     * Activa a los apoderados que aún no tienen usuario.
     *
     * @param  list<int>  $apoderados
     * @return list<array<string, mixed>>
     */
    public function activarApoderados(array $apoderados): array
    {
        $sinUsuario = DB::table('apoderados')->whereIn('id', $apoderados)->whereNull('user_id')->pluck('id');

        return $sinUsuario->map(fn ($a) => $this->familias->activar((int) $a, auth()->user()))->filter()->values()->all();
    }

    /** Matrícula en una sección del año (una por año), respetando las vacantes. */
    public function matricular(int $alumnoId, int $seccionId, string $tipo = 'nuevo', string $origen = 'manual'): void
    {
        $seccion = DB::table('secciones as s')->join('grados as g', 'g.id', '=', 's.grado_id')->where('s.id', $seccionId)
            ->first(['s.id', 's.anio_escolar_id', 's.vacantes', DB::raw("g.nombre || ' ' || s.nombre as nombre")]);
        if (! $seccion) {
            throw ValidationException::withMessages(['seccion_id' => ['Elige una sección del colegio.']]);
        }
        $actual = DB::table('matriculas')->where('alumno_id', $alumnoId)->where('anio_escolar_id', $seccion->anio_escolar_id)->first();
        if ($actual && (int) $actual->seccion_id === $seccionId) {
            return;
        }
        if ($actual) {
            throw ValidationException::withMessages(['seccion_id' => ['El alumno ya está matriculado ese año en otra sección (el cambio de sección es otro trámite).']]);
        }
        if ($seccion->vacantes !== null) {
            $ocupadas = DB::table('matriculas')->where('seccion_id', $seccionId)->whereIn('estado', ['reservado', 'matriculado'])->count();
            if ($ocupadas >= $seccion->vacantes) {
                throw ValidationException::withMessages(['seccion_id' => ["{$seccion->nombre} ya no tiene vacantes."]]);
            }
        }
        DB::table('matriculas')->insert([
            'institucion_id' => ContextoInstitucion::requerido(), 'alumno_id' => $alumnoId, 'anio_escolar_id' => $seccion->anio_escolar_id,
            'seccion_id' => $seccionId, 'tipo' => $tipo, 'estado' => 'matriculado', 'origen' => $origen,
        ]);
        // EDUN-53 · Si vino de admisión, el postulante pasa a «matriculado».
        Admision::alMatricular($alumnoId);
    }

    private static function resumen(array $f): array
    {
        return [
            'alumno' => trim("{$f['apellido_paterno']} {$f['apellido_materno']}, {$f['nombres']}"), 'documento' => $f['numero_documento'], 'siagie' => $f['codigo_estudiante_siagie'],
            'apoderados' => array_map(fn ($p) => "{$p['nombres']} {$p['apellidos']} ({$p['parentesco']}".($p['es_responsable_pago'] ? ', pago' : '').')', $f['apoderados']),
            'autorizados' => array_column($f['autorizados'], 'nombres'),
            'matriculas' => array_map(fn ($m) => "{$m['anio']} {$m['seccion']}", $f['matriculas']),
        ];
    }
}
