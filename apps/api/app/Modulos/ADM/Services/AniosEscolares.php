<?php

namespace App\Modulos\ADM\Services;

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\Auditoria;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-44 / ADM-H02 · Año escolar con bimestres o trimestres.
 *
 * - Al crear, reparte el año en periodos de duración parecida (fechas editables después) y
 *   propone la fecha límite de notas una semana después de cada cierre.
 * - Carga los feriados nacionales del año (cms.feriados_nacionales); el colegio agrega los suyos.
 * - Un periodo cerrado ya no cambia de fechas; un año cerrado no se edita.
 */
class AniosEscolares
{
    public const CANTIDAD = ['bimestre' => 4, 'trimestre' => 3];

    public const ROMANOS = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV'];

    public const DIAS_LIMITE_NOTAS = 7;

    public function __construct(private Auditoria $auditoria) {}

    public function listar(): array
    {
        return DB::table('anios_escolares as a')
            ->leftJoin('periodos as p', 'p.anio_escolar_id', '=', 'a.id')
            ->groupBy('a.id')->orderByDesc('a.anio')
            ->get(['a.id', 'a.anio', 'a.tipo_periodo', 'a.fecha_inicio', 'a.fecha_fin', 'a.estado', DB::raw('count(p.id) as periodos')])
            ->map(fn ($a) => (array) $a + ['periodos' => (int) $a->periodos])->all();
    }

    public function detalle(int $id): array
    {
        $anio = DB::table('anios_escolares')->find($id);
        abort_if(! $anio, 404);

        return [
            'id' => (int) $anio->id, 'anio' => (int) $anio->anio, 'tipo_periodo' => $anio->tipo_periodo,
            'fecha_inicio' => $anio->fecha_inicio, 'fecha_fin' => $anio->fecha_fin, 'estado' => $anio->estado,
            'periodos' => DB::table('periodos')->where('anio_escolar_id', $id)->orderBy('numero')
                ->get(['id', 'numero', 'nombre', 'fecha_inicio', 'fecha_fin', 'fecha_limite_notas', 'estado'])->map(fn ($p) => (array) $p)->all(),
            'feriados' => DB::table('feriados')->where('anio_escolar_id', $id)->orderBy('fecha')->get(['fecha', 'motivo'])->map(fn ($f) => (array) $f)->all(),
        ];
    }

    /** @param  array{anio: int, tipo_periodo: string, fecha_inicio: string, fecha_fin: string, importar_feriados?: bool}  $datos */
    public function crear(array $datos): array
    {
        $institucion = ContextoInstitucion::requerido();
        if (DB::table('anios_escolares')->where('anio', $datos['anio'])->exists()) {
            throw ValidationException::withMessages(['anio' => ["El año escolar {$datos['anio']} ya existe."]]);
        }
        self::validarRango($datos['anio'], $datos['fecha_inicio'], $datos['fecha_fin']);

        $id = (int) DB::table('anios_escolares')->insertGetId([
            'institucion_id' => $institucion, 'anio' => $datos['anio'], 'tipo_periodo' => $datos['tipo_periodo'],
            'fecha_inicio' => $datos['fecha_inicio'], 'fecha_fin' => $datos['fecha_fin'],
        ]);
        $this->insertarPeriodos($institucion, $id, self::repartir($datos['tipo_periodo'], $datos['fecha_inicio'], $datos['fecha_fin']));

        $sinFeriados = false;
        if ($datos['importar_feriados'] ?? true) {
            $nacionales = DB::table('cms.feriados_nacionales')->whereBetween('fecha', [$datos['fecha_inicio'], $datos['fecha_fin']])->orderBy('fecha')->get();
            foreach ($nacionales as $f) {
                DB::table('feriados')->insert(['institucion_id' => $institucion, 'anio_escolar_id' => $id, 'fecha' => $f->fecha, 'motivo' => $f->motivo]);
            }
            $sinFeriados = $nacionales->isEmpty();
        }

        $detalle = $this->detalle($id);
        $this->auditoria->registrar('anio_creado', 'anios_escolares', $id, null, self::resumen($detalle));

        return $detalle + ['aviso' => $sinFeriados ? "Aún no tenemos los feriados nacionales de {$datos['anio']}: agrégalos a mano." : null];
    }

    /**
     * @param  array{fecha_inicio: string, fecha_fin: string, tipo_periodo?: string|null,
     *     periodos?: list<array{numero: int, nombre: string, fecha_inicio: string, fecha_fin: string, fecha_limite_notas?: string|null}>,
     *     feriados?: list<array{fecha: string, motivo: string}>}  $datos
     */
    public function actualizar(int $id, array $datos): array
    {
        $antes = $this->detalle($id);
        if ($antes['estado'] === 'cerrado') {
            throw ValidationException::withMessages(['anio' => ['El año escolar está cerrado: ya no se puede editar.']]);
        }
        self::validarRango($antes['anio'], $datos['fecha_inicio'], $datos['fecha_fin']);
        $institucion = ContextoInstitucion::requerido();
        $tipo = $datos['tipo_periodo'] ?? $antes['tipo_periodo'];

        if ($tipo !== $antes['tipo_periodo']) {
            // Cambiar de bimestre a trimestre (o al revés) solo mientras nada se haya abierto.
            if ($antes['estado'] !== 'planificado' || collect($antes['periodos'])->contains(fn ($p) => $p['estado'] !== 'programado')) {
                throw ValidationException::withMessages(['tipo_periodo' => ['Ya hay periodos abiertos: no se puede cambiar entre bimestres y trimestres.']]);
            }
            $periodos = self::repartir($tipo, $datos['fecha_inicio'], $datos['fecha_fin']);
        } else {
            $periodos = $datos['periodos'] ?? $antes['periodos'];
            self::validarPeriodos($tipo, $periodos, $datos['fecha_inicio'], $datos['fecha_fin']);
            foreach ($antes['periodos'] as $p) {
                $nuevo = collect($periodos)->firstWhere('numero', $p['numero']);
                if ($p['estado'] === 'cerrado' && ($nuevo['fecha_inicio'] !== $p['fecha_inicio'] || $nuevo['fecha_fin'] !== $p['fecha_fin'])) {
                    throw ValidationException::withMessages(['periodos' => ["El {$p['nombre']} está cerrado: sus fechas ya no cambian."]]);
                }
            }
        }

        $feriados = $datos['feriados'] ?? $antes['feriados'];
        $fechas = array_column($feriados, 'fecha');
        if (count($fechas) !== count(array_unique($fechas))) {
            throw ValidationException::withMessages(['feriados' => ['Hay un feriado repetido.']]);
        }
        foreach ($feriados as $f) {
            if ($f['fecha'] < $datos['fecha_inicio'] || $f['fecha'] > $datos['fecha_fin']) {
                throw ValidationException::withMessages(['feriados' => ["El feriado del {$f['fecha']} está fuera del año escolar."]]);
            }
        }

        DB::table('anios_escolares')->where('id', $id)->update([
            'tipo_periodo' => $tipo, 'fecha_inicio' => $datos['fecha_inicio'], 'fecha_fin' => $datos['fecha_fin'],
        ]);
        if ($tipo !== $antes['tipo_periodo']) {
            DB::table('periodos')->where('anio_escolar_id', $id)->delete();
            $this->insertarPeriodos($institucion, $id, $periodos);
        } else {
            foreach ($periodos as $p) {
                DB::table('periodos')->where('anio_escolar_id', $id)->where('numero', $p['numero'])->update([
                    'nombre' => $p['nombre'], 'fecha_inicio' => $p['fecha_inicio'], 'fecha_fin' => $p['fecha_fin'],
                    'fecha_limite_notas' => $p['fecha_limite_notas'] ?? null,
                ]);
            }
        }
        DB::table('feriados')->where('anio_escolar_id', $id)->delete();
        foreach ($feriados as $f) {
            DB::table('feriados')->insert(['institucion_id' => $institucion, 'anio_escolar_id' => $id, 'fecha' => $f['fecha'], 'motivo' => trim($f['motivo'])]);
        }

        $despues = $this->detalle($id);
        if (self::resumen($antes) !== self::resumen($despues)) {
            $this->auditoria->registrar('anio_editado', 'anios_escolares', $id, self::resumen($antes), self::resumen($despues));
        }

        return $despues;
    }

    /**
     * Reparte el año en periodos consecutivos de duración parecida.
     *
     * @return list<array{numero: int, nombre: string, fecha_inicio: string, fecha_fin: string, fecha_limite_notas: string}>
     */
    public static function repartir(string $tipo, string $inicio, string $fin): array
    {
        $n = self::CANTIDAD[$tipo];
        $desde = Carbon::parse($inicio);
        $dias = $desde->diffInDays(Carbon::parse($fin)) + 1;
        $periodos = [];
        for ($i = 1; $i <= $n; $i++) {
            $ini = $desde->copy()->addDays((int) floor(($i - 1) * $dias / $n));
            $finP = $i === $n ? Carbon::parse($fin) : $desde->copy()->addDays((int) floor($i * $dias / $n) - 1);
            $periodos[] = [
                'numero' => $i,
                'nombre' => self::ROMANOS[$i].' '.ucfirst($tipo),
                'fecha_inicio' => $ini->toDateString(),
                'fecha_fin' => $finP->toDateString(),
                'fecha_limite_notas' => $finP->copy()->addDays(self::DIAS_LIMITE_NOTAS)->toDateString(),
            ];
        }

        return $periodos;
    }

    private function insertarPeriodos(int $institucion, int $anioId, array $periodos): void
    {
        foreach ($periodos as $p) {
            DB::table('periodos')->insert([
                'institucion_id' => $institucion, 'anio_escolar_id' => $anioId, 'numero' => $p['numero'], 'nombre' => $p['nombre'],
                'fecha_inicio' => $p['fecha_inicio'], 'fecha_fin' => $p['fecha_fin'], 'fecha_limite_notas' => $p['fecha_limite_notas'] ?? null,
            ]);
        }
    }

    private static function validarRango(int $anio, string $inicio, string $fin): void
    {
        if ($fin <= $inicio) {
            throw ValidationException::withMessages(['fecha_fin' => ['La fecha de fin debe ser posterior a la de inicio.']]);
        }
        if ((int) substr($inicio, 0, 4) !== $anio || (int) substr($fin, 0, 4) !== $anio) {
            throw ValidationException::withMessages(['fecha_inicio' => ["Las fechas deben estar dentro de {$anio}."]]);
        }
    }

    private static function validarPeriodos(string $tipo, array $periodos, string $inicio, string $fin): void
    {
        $n = self::CANTIDAD[$tipo];
        $periodos = collect($periodos)->sortBy('numero')->values()->all();
        if (count($periodos) !== $n || array_column($periodos, 'numero') !== range(1, $n)) {
            throw ValidationException::withMessages(['periodos' => ["El año por {$tipo}s tiene {$n} periodos."]]);
        }
        $anterior = null;
        foreach ($periodos as $p) {
            if ($p['fecha_fin'] < $p['fecha_inicio']) {
                throw ValidationException::withMessages(['periodos' => ["El {$p['nombre']} termina antes de empezar."]]);
            }
            if ($p['fecha_inicio'] < $inicio || $p['fecha_fin'] > $fin) {
                throw ValidationException::withMessages(['periodos' => ["El {$p['nombre']} está fuera de las fechas del año."]]);
            }
            if ($anterior && $p['fecha_inicio'] <= $anterior['fecha_fin']) {
                throw ValidationException::withMessages(['periodos' => ["El {$p['nombre']} se cruza con el {$anterior['nombre']}."]]);
            }
            if (($p['fecha_limite_notas'] ?? null) && $p['fecha_limite_notas'] < $p['fecha_fin']) {
                throw ValidationException::withMessages(['periodos' => ["La fecha límite de notas del {$p['nombre']} no puede ser antes de su cierre."]]);
            }
            $anterior = $p;
        }
    }

    private static function resumen(array $detalle): array
    {
        return [
            'tipo_periodo' => $detalle['tipo_periodo'], 'fecha_inicio' => $detalle['fecha_inicio'], 'fecha_fin' => $detalle['fecha_fin'],
            'periodos' => array_map(fn ($p) => [$p['nombre'], $p['fecha_inicio'], $p['fecha_fin'], $p['fecha_limite_notas']], $detalle['periodos']),
            'feriados' => count($detalle['feriados']),
        ];
    }
}
