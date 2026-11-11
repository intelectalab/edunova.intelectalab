<?php

namespace App\Modulos\PAG\Services;

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\Auditoria;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PAG-02 · Conceptos y cronogramas.
 *
 * - EDUN-69 · PAG-H01 Conceptos: obligatorios solo cuota de ingreso, matrícula y pensión
 *   (Indecopi, también en la BD); afectación de IGV y tipo de comprobante; la matrícula no
 *   supera la pensión.
 * - EDUN-70 · PAG-H02 Cronograma por año y nivel: cuotas por mes con emisión y vencimiento,
 *   ninguna vence antes de su mes (sin cobro adelantado) y se copia del año anterior.
 */
class ConfiguracionCobros
{
    public const TIPOS = ['cuota_ingreso' => 'Cuota de ingreso', 'matricula' => 'Matrícula', 'pension' => 'Pensión', 'servicio' => 'Servicio', 'tramite' => 'Trámite', 'otro' => 'Otro'];

    public const OBLIGATORIOS = ['cuota_ingreso', 'matricula', 'pension'];

    public const COMPROBANTES = ['boleta' => 'Boleta', 'factura' => 'Factura', 'ninguno' => 'Sin comprobante'];

    public function __construct(private Auditoria $auditoria) {}

    public function conceptos(): array
    {
        return DB::table('conceptos_cobro')->orderByRaw("array_position(array['cuota_ingreso','matricula','pension','servicio','tramite','otro']::varchar[], tipo)")
            ->orderBy('nombre')->get()->map(fn ($c) => array_merge((array) $c, ['monto' => (float) $c->monto]))->all();
    }

    public function guardarConcepto(array $d, ?int $id = null): int
    {
        if (! empty($d['obligatorio']) && ! in_array($d['tipo'], self::OBLIGATORIOS, true)) {
            throw ValidationException::withMessages(['obligatorio' => ['Solo la cuota de ingreso, la matrícula y la pensión pueden ser obligatorias (Indecopi). Este concepto debe ser opcional.']]);
        }
        $repetido = DB::table('conceptos_cobro')->where('codigo', $d['codigo'])->when($id, fn ($q) => $q->where('id', '<>', $id))->exists();
        if ($repetido) {
            throw ValidationException::withMessages(['codigo' => ['Ya existe un concepto con ese código.']]);
        }
        $this->validarMatriculaVsPension($d['tipo'], (float) $d['monto'], $id);

        $fila = [
            'codigo' => mb_strtoupper(trim($d['codigo'])), 'nombre' => trim($d['nombre']), 'tipo' => $d['tipo'],
            'obligatorio' => (bool) ($d['obligatorio'] ?? false), 'monto' => round((float) $d['monto'], 2),
            'afecto_igv' => (bool) ($d['afecto_igv'] ?? false), 'tipo_comprobante' => $d['tipo_comprobante'] ?? 'boleta',
            'activo' => (bool) ($d['activo'] ?? true),
        ];

        return DB::transaction(function () use ($fila, $id) {
            if ($id) {
                $antes = DB::table('conceptos_cobro')->find($id);
                abort_if(! $antes, 404);
                DB::table('conceptos_cobro')->where('id', $id)->update($fila);
                $this->auditoria->registrar('concepto_actualizado', 'conceptos_cobro', $id, (array) $antes, $fila);

                return $id;
            }
            $nuevo = (int) DB::table('conceptos_cobro')->insertGetId($fila + ['institucion_id' => ContextoInstitucion::requerido()]);
            $this->auditoria->registrar('concepto_creado', 'conceptos_cobro', $nuevo, null, $fila);

            return $nuevo;
        });
    }

    /** Regla 2 · La matrícula no puede superar el monto de una pensión. */
    private function validarMatriculaVsPension(string $tipo, float $monto, ?int $id): void
    {
        $activos = DB::table('conceptos_cobro')->where('activo', true)->when($id, fn ($q) => $q->where('id', '<>', $id));
        if ($tipo === 'matricula') {
            $pension = (clone $activos)->where('tipo', 'pension')->min('monto');
            if ($pension !== null && $monto > (float) $pension) {
                throw ValidationException::withMessages(['monto' => ['La matrícula no puede superar la pensión (S/ '.number_format((float) $pension, 2).').']]);
            }
        }
        if ($tipo === 'pension') {
            $matricula = (clone $activos)->where('tipo', 'matricula')->max('monto');
            if ($matricula !== null && (float) $matricula > $monto) {
                throw ValidationException::withMessages(['monto' => ['La pensión no puede ser menor que la matrícula (S/ '.number_format((float) $matricula, 2).').']]);
            }
        }
    }

    public function cronogramas(int $anioId): array
    {
        $cuotas = DB::table('cronograma_cuotas as cc')->join('cronogramas as c', 'c.id', '=', 'cc.cronograma_id')
            ->join('conceptos_cobro as cp', 'cp.id', '=', 'cc.concepto_cobro_id')->where('c.anio_escolar_id', $anioId)
            ->orderBy('cc.numero')->get(['cc.*', 'cp.nombre as concepto', 'cp.tipo'])->groupBy('cronograma_id');
        $conCargos = DB::table('cargos')->whereIn('cronograma_cuota_id', $cuotas->flatten()->pluck('id'))->distinct()->pluck('cronograma_cuota_id')->flip();

        return DB::table('cronogramas')->where('anio_escolar_id', $anioId)->orderByRaw('nivel nulls first')->get()
            ->map(fn ($c) => (array) $c + [
                'cuotas' => ($cuotas[$c->id] ?? collect())->map(fn ($q) => [
                    'id' => $q->id, 'numero' => $q->numero, 'concepto_cobro_id' => $q->concepto_cobro_id, 'concepto' => $q->concepto, 'tipo' => $q->tipo,
                    'mes' => $q->mes, 'emision' => $q->emision, 'vencimiento' => $q->vencimiento, 'monto' => (float) $q->monto,
                    'con_cargos' => isset($conCargos[$q->id]),
                ])->values()->all(),
            ])->all();
    }

    /**
     * Crea o reemplaza un cronograma con sus cuotas. Las cuotas que ya generaron cargos no se
     * pueden quitar ni cambiar de mes o monto (los cargos de las familias ya existen).
     *
     * @param  list<array{concepto_cobro_id: int, mes: string, emision: string, vencimiento: string, monto: float|int|string}>  $cuotas
     */
    public function guardarCronograma(int $anioId, ?string $nivel, string $nombre, array $cuotas, ?int $id = null): int
    {
        $repetido = DB::table('cronogramas')->where('anio_escolar_id', $anioId)
            ->where(fn ($q) => $nivel ? $q->where('nivel', $nivel) : $q->whereNull('nivel'))->when($id, fn ($q) => $q->where('id', '<>', $id))->exists();
        if ($repetido) {
            throw ValidationException::withMessages(['nivel' => [$nivel ? 'Ese nivel ya tiene cronograma en el año.' : 'El año ya tiene un cronograma general.']]);
        }
        $conceptos = DB::table('conceptos_cobro')->whereIn('id', array_column($cuotas, 'concepto_cobro_id'))->pluck('tipo', 'id');
        $errores = [];
        foreach ($cuotas as $i => $q) {
            $n = $i + 1;
            if (! isset($conceptos[$q['concepto_cobro_id']])) {
                $errores["cuotas.$i.concepto_cobro_id"] = ["Cuota $n: elige un concepto del colegio."];

                continue;
            }
            $inicioMes = Carbon::parse($q['mes'].'-01')->toDateString();
            if ($q['vencimiento'] < $inicioMes) {
                $errores["cuotas.$i.vencimiento"] = ["Cuota $n: no puede vencer antes de su mes ({$q['mes']}). No se permite cobro adelantado."];
            }
            if ($q['vencimiento'] < $q['emision']) {
                $errores["cuotas.$i.emision"] = ["Cuota $n: la emisión debe ser antes del vencimiento."];
            }
        }
        $montos = fn (string $tipo) => collect($cuotas)->filter(fn ($q) => ($conceptos[$q['concepto_cobro_id']] ?? null) === $tipo)->pluck('monto')->map(fn ($m) => (float) $m);
        if ($montos('pension')->isNotEmpty() && $montos('matricula')->isNotEmpty() && $montos('matricula')->max() > $montos('pension')->min()) {
            $errores['cuotas'] = ['La matrícula no puede superar el monto de una pensión del mismo año y nivel.'];
        }
        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        return DB::transaction(function () use ($anioId, $nivel, $nombre, $cuotas, $id) {
            $inst = ContextoInstitucion::requerido();
            if ($id) {
                abort_if(! DB::table('cronogramas')->where('id', $id)->exists(), 404);
                DB::table('cronogramas')->where('id', $id)->update(['nivel' => $nivel, 'nombre' => $nombre]);
            } else {
                $id = (int) DB::table('cronogramas')->insertGetId(['institucion_id' => $inst, 'anio_escolar_id' => $anioId, 'nivel' => $nivel, 'nombre' => $nombre]);
            }
            $existentes = DB::table('cronograma_cuotas')->where('cronograma_id', $id)->get()->keyBy('id');
            $conCargos = DB::table('cargos')->whereIn('cronograma_cuota_id', $existentes->keys())->distinct()->pluck('cronograma_cuota_id')->flip();
            $conservar = [];
            foreach ($cuotas as $q) {
                $previa = isset($q['id']) ? $existentes->get($q['id']) : null;
                if ($previa && isset($conCargos[$previa->id]) && ($previa->mes !== $q['mes'] || abs((float) $previa->monto - (float) $q['monto']) > 0.001 || (int) $previa->concepto_cobro_id !== (int) $q['concepto_cobro_id'])) {
                    throw ValidationException::withMessages(['cuotas' => ["La cuota de {$previa->mes} ya generó cargos: no se puede cambiar su mes, concepto ni monto."]]);
                }
                if ($previa) {
                    $conservar[] = $previa->id;
                }
            }
            foreach ($existentes as $e) {
                if (! in_array($e->id, $conservar, true) && isset($conCargos[$e->id])) {
                    throw ValidationException::withMessages(['cuotas' => ["La cuota de {$e->mes} ya generó cargos: no se puede quitar."]]);
                }
            }
            DB::table('cronograma_cuotas')->where('cronograma_id', $id)->whereNotIn('id', $conservar)->delete();
            // Renumerar sin chocar con UNIQUE (cronograma_id, numero).
            DB::table('cronograma_cuotas')->where('cronograma_id', $id)->update(['numero' => DB::raw('numero + 1000')]);
            usort($cuotas, fn ($a, $b) => [$a['mes'], $a['vencimiento']] <=> [$b['mes'], $b['vencimiento']]);
            foreach ($cuotas as $i => $q) {
                $fila = [
                    'concepto_cobro_id' => (int) $q['concepto_cobro_id'], 'numero' => $i + 1, 'mes' => $q['mes'],
                    'emision' => $q['emision'], 'vencimiento' => $q['vencimiento'], 'monto' => round((float) $q['monto'], 2),
                ];
                if (isset($q['id']) && in_array((int) $q['id'], $conservar, true)) {
                    DB::table('cronograma_cuotas')->where('id', $q['id'])->update($fila);
                } else {
                    DB::table('cronograma_cuotas')->insert($fila + ['institucion_id' => $inst, 'cronograma_id' => $id]);
                }
            }
            $this->auditoria->registrar('cronograma_guardado', 'cronogramas', $id, null, ['anio_escolar_id' => $anioId, 'nivel' => $nivel, 'cuotas' => count($cuotas)]);

            return $id;
        });
    }

    public function eliminarCronograma(int $id): void
    {
        $cuotas = DB::table('cronograma_cuotas')->where('cronograma_id', $id)->pluck('id');
        if (DB::table('cargos')->whereIn('cronograma_cuota_id', $cuotas)->exists()) {
            throw ValidationException::withMessages(['cronograma' => ['El cronograma ya generó cargos: no se puede eliminar.']]);
        }
        DB::transaction(function () use ($id) {
            DB::table('cronograma_cuotas')->where('cronograma_id', $id)->delete();
            DB::table('cronogramas')->where('id', $id)->delete();
            $this->auditoria->registrar('cronograma_eliminado', 'cronogramas', $id, null, null);
        });
    }

    /** Copia los cronogramas de un año a otro corriendo las fechas los años que haya entre ambos. */
    public function copiar(int $desdeAnioId, int $haciaAnioId): int
    {
        $anios = DB::table('anios_escolares')->whereIn('id', [$desdeAnioId, $haciaAnioId])->pluck('anio', 'id');
        if (count($anios) !== 2) {
            throw ValidationException::withMessages(['desde_anio_id' => ['Elige dos años escolares distintos del colegio.']]);
        }
        if (DB::table('cronogramas')->where('anio_escolar_id', $haciaAnioId)->exists()) {
            throw ValidationException::withMessages(['hacia_anio_id' => ['El año de destino ya tiene cronogramas.']]);
        }
        $saltos = (int) $anios[$haciaAnioId] - (int) $anios[$desdeAnioId];
        $mover = fn (string $fecha) => Carbon::parse($fecha)->addYearsNoOverflow($saltos)->toDateString();
        $copiados = 0;
        DB::transaction(function () use ($desdeAnioId, $haciaAnioId, $saltos, $mover, $anios, &$copiados) {
            $inst = ContextoInstitucion::requerido();
            foreach (DB::table('cronogramas')->where('anio_escolar_id', $desdeAnioId)->get() as $c) {
                $nuevo = DB::table('cronogramas')->insertGetId([
                    'institucion_id' => $inst, 'anio_escolar_id' => $haciaAnioId, 'nivel' => $c->nivel,
                    'nombre' => str_replace((string) $anios[$desdeAnioId], (string) $anios[$haciaAnioId], $c->nombre),
                ]);
                foreach (DB::table('cronograma_cuotas')->where('cronograma_id', $c->id)->orderBy('numero')->get() as $q) {
                    DB::table('cronograma_cuotas')->insert([
                        'institucion_id' => $inst, 'cronograma_id' => $nuevo, 'concepto_cobro_id' => $q->concepto_cobro_id, 'numero' => $q->numero,
                        'mes' => Carbon::parse($q->mes.'-01')->addYears($saltos)->format('Y-m'), 'emision' => $mover($q->emision),
                        'vencimiento' => $mover($q->vencimiento), 'monto' => $q->monto,
                    ]);
                }
                $copiados++;
            }
            $this->auditoria->registrar('cronogramas_copiados', 'cronogramas', null, null, ['desde' => $desdeAnioId, 'hacia' => $haciaAnioId, 'cronogramas' => $copiados]);
        });

        return $copiados;
    }
}
