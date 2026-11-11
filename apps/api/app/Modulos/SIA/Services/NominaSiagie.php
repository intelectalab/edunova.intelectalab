<?php

namespace App\Modulos\SIA\Services;

use App\Modulos\ADM\Services\RegistroAlumnos;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Models\Archivo;
use App\Modulos\SEG\Services\AlmacenArchivos;
use App\Modulos\SEG\Services\Auditoria;
use App\Modulos\SIA\Jobs\ImportarNominaSiagie;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * EDUN-106 · SIA-H01 Importar la nómina del SIAGIE (.xlsx o .xls, hasta 10 MB) en cola y por
 * bloques: crea o actualiza alumno y matrícula, con errores por fila.
 * EDUN-107 · SIA-H02 Al terminar deja la lista de matriculados en Edunova que no figuran en la
 * nómina, para revisar retiro o traslado.
 * Las columnas se reconocen por su encabezado con los alias de config/siagie.php.
 */
class NominaSiagie
{
    public function __construct(private AlmacenArchivos $almacen, private Auditoria $auditoria) {}

    public function subir(UploadedFile $archivo, int $seccionId, int $usuarioId): int
    {
        $ext = strtolower($archivo->getClientOriginalExtension());
        if (! in_array($ext, ['xlsx', 'xls'], true)) {
            throw ValidationException::withMessages(['archivo' => ['Sube la nómina en Excel (.xlsx o .xls) tal como la descargas del SIAGIE.']]);
        }
        if ($archivo->getSize() > (int) config('siagie.max_mb') * 1024 * 1024) {
            throw ValidationException::withMessages(['archivo' => ['El archivo supera los '.config('siagie.max_mb').' MB.']]);
        }
        abort_if(! DB::table('secciones')->where('id', $seccionId)->exists(), 404);
        $guardado = $this->almacen->guardar($archivo, $usuarioId, true);
        $id = (int) DB::table('siagie_procesos')->insertGetId([
            'institucion_id' => ContextoInstitucion::requerido(), 'tipo' => 'nomina', 'seccion_id' => $seccionId,
            'archivo_original_id' => $guardado->id, 'estado' => 'pendiente', 'usuario_id' => $usuarioId,
        ]);
        $this->auditoria->registrar('nomina_siagie_subida', 'siagie_procesos', $id, null, ['seccion_id' => $seccionId, 'archivo' => $archivo->getClientOriginalName()]);
        $inst = ContextoInstitucion::requerido();
        DB::afterCommit(fn () => ImportarNominaSiagie::dispatch($inst, $id));

        return $id;
    }

    /** Lo ejecuta la cola dentro del contexto del colegio. */
    public function procesar(int $procesoId): void
    {
        $p = DB::table('siagie_procesos')->find($procesoId);
        if (! $p || $p->estado !== 'pendiente') {
            return;
        }
        DB::table('siagie_procesos')->where('id', $procesoId)->update(['estado' => 'procesando', 'iniciado_at' => now()]);
        $archivo = Archivo::query()->findOrFail($p->archivo_original_id);
        $temporal = tempnam(sys_get_temp_dir(), 'nomina').'.'.strtolower(pathinfo($archivo->nombre_original, PATHINFO_EXTENSION) ?: 'xlsx');
        file_put_contents($temporal, Storage::disk($archivo->disco)->get($archivo->ruta));
        try {
            $this->leer($temporal, $p);
        } catch (Throwable $e) {
            DB::table('siagie_procesos')->where('id', $procesoId)->update(['estado' => 'fallido', 'finalizado_at' => now(), 'advertencias' => json_encode([$e->getMessage()])]);
        } finally {
            @unlink($temporal);
        }
    }

    private function leer(string $ruta, object $p): void
    {
        $alias = config('siagie.nomina.columnas');
        [$hojaIndice, $filaEncabezado, $columnas] = $this->detectarEncabezado($ruta, $alias);
        if (! array_intersect(config('siagie.nomina.identificadores'), array_keys($columnas))) {
            throw ValidationException::withMessages(['archivo' => ['El archivo no tiene columna de código del estudiante ni de documento de identidad.']]);
        }
        $seccion = DB::table('secciones')->find($p->seccion_id);
        $registro = app(RegistroAlumnos::class);
        $chunk = (int) config('siagie.nomina.chunk', 200);
        $contadores = ['total' => 0, 'creados' => 0, 'actualizados' => 0, 'error' => 0];
        $vistos = [];
        $desde = $filaEncabezado + 1;
        $vacias = 0;
        while ($vacias < 3) {
            $hoja = $this->bloque($ruta, $hojaIndice, $desde, $desde + $chunk - 1);
            $finBloque = false;
            for ($fila = $desde; $fila < $desde + $chunk; $fila++) {
                $valores = array_map(fn ($letra) => $this->valor($hoja, $letra, $fila), $columnas);
                if (! array_filter($valores, fn ($v) => trim((string) $v) !== '')) {
                    if (++$vacias >= 3) {
                        $finBloque = true;
                        break;
                    }

                    continue;
                }
                $vacias = 0;
                $contadores['total']++;
                try {
                    DB::transaction(function () use ($valores, $seccion, $registro, &$contadores, &$vistos) {
                        $r = $this->registrarFila($valores, $seccion, $registro);
                        $vistos[] = $r['alumno_id'];
                        $contadores[$r['nuevo'] ? 'creados' : 'actualizados']++;
                    });
                } catch (ValidationException $e) {
                    $contadores['error']++;
                    DB::table('siagie_errores')->insert(['institucion_id' => $p->institucion_id, 'siagie_proceso_id' => $p->id, 'fila' => $fila, 'mensaje' => mb_substr(collect($e->errors())->flatten()->implode(' '), 0, 500)]);
                }
            }
            $hoja->getParent()?->disconnectWorksheets();
            if ($finBloque) {
                break;
            }
            $desde += $chunk;
        }

        // EDUN-107 · Matriculados en la sección que no figuran en la nómina.
        $noFiguran = DB::table('matriculas as m')->join('alumnos as a', 'a.id', '=', 'm.alumno_id')->where('m.seccion_id', $p->seccion_id)->where('m.estado', 'matriculado')
            ->whereNotIn('a.id', $vistos ?: [0])->orderBy('a.apellido_paterno')
            ->get(['a.id', 'm.id as matricula_id', DB::raw("a.apellido_paterno || ' ' || coalesce(a.apellido_materno, '') || ', ' || a.nombres as nombre"), 'a.codigo_estudiante_siagie'])
            ->map(fn ($a) => (array) $a)->all();
        DB::table('siagie_procesos')->where('id', $p->id)->update([
            'estado' => $contadores['error'] > 0 ? 'con_errores' : 'completado', 'total_filas' => $contadores['total'], 'creados' => $contadores['creados'],
            'actualizados' => $contadores['actualizados'], 'con_error' => $contadores['error'], 'finalizado_at' => now(),
            'mapeo' => json_encode(['hoja' => $hojaIndice, 'fila_encabezado' => $filaEncabezado, 'columnas' => $columnas]),
            'advertencias' => json_encode(['no_figuran' => $noFiguran]),
        ]);
    }

    /** Lee solo un rango de filas (memoria constante con archivos grandes). */
    private function bloque(string $ruta, int $hoja, int $desde, int $hasta): Worksheet
    {
        $lector = IOFactory::createReaderForFile($ruta);
        $lector->setReadDataOnly(true);
        $lector->setReadFilter(new class($desde, $hasta) implements IReadFilter
        {
            public function __construct(private int $desde, private int $hasta) {}

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row >= $this->desde && $row <= $this->hasta;
            }
        });

        return $lector->load($ruta)->getSheet($hoja);
    }

    /** @return array{0: int, 1: int, 2: array<string, string>} hoja, fila del encabezado y columnas */
    private function detectarEncabezado(string $ruta, array $alias): array
    {
        $lector = IOFactory::createReaderForFile($ruta);
        $lector->setReadDataOnly(true);
        $libro = $lector->load($ruta);
        $mejor = null;
        foreach ($libro->getAllSheets() as $i => $hoja) {
            $ultimaCol = Coordinate::columnIndexFromString($hoja->getHighestColumn());
            for ($f = 1; $f <= min((int) config('siagie.nomina.filas_busqueda_encabezado', 30), $hoja->getHighestRow()); $f++) {
                $cols = [];
                for ($c = 1; $c <= $ultimaCol; $c++) {
                    $letra = Coordinate::stringFromColumnIndex($c);
                    $texto = $hoja->getCell($letra.$f)->getValue();
                    foreach ($alias as $campo => $lista) {
                        if (! isset($cols[$campo]) && TextoSiagie::coincide($texto, $lista)) {
                            $cols[$campo] = $letra;
                            break;
                        }
                    }
                }
                if (count($cols) >= 2 && (! $mejor || count($cols) > count($mejor[2]))) {
                    $mejor = [$i, $f, $cols];
                }
            }
        }
        $libro->disconnectWorksheets();
        if (! $mejor) {
            throw ValidationException::withMessages(['archivo' => ['No se encontró la fila de encabezados. Verifica que sea la nómina descargada del SIAGIE.']]);
        }

        return $mejor;
    }

    private function valor(Worksheet $hoja, string $letra, int $fila): mixed
    {
        return $hoja->getCell($letra.$fila)->getValue();
    }

    /** @return array{alumno_id: int, nuevo: bool} */
    private function registrarFila(array $v, object $seccion, RegistroAlumnos $registro): array
    {
        $codigo = TextoSiagie::codigoEstudiante($v['codigo_estudiante'] ?? null);
        $dni = TextoSiagie::dni($v['numero_documento'] ?? null);
        if (! $codigo && ! $dni) {
            throw ValidationException::withMessages(['fila' => ['Sin código del estudiante ni documento.']]);
        }
        [$paterno, $materno, $nombres] = $this->nombres($v);
        if (! $paterno || ! $nombres) {
            throw ValidationException::withMessages(['fila' => ['Faltan apellidos o nombres.']]);
        }
        $alumno = DB::table('alumnos')->whereNull('eliminado_at')
            ->where(fn ($q) => $q->when($codigo, fn ($w) => $w->orWhere('codigo_estudiante_siagie', $codigo))->when($dni, fn ($w) => $w->orWhere('numero_documento', $dni)))->first();
        // El SIAGIE usa «Hombre» / «Mujer»; «M» sola es ambigua y se deja vacía.
        $sexoTexto = TextoSiagie::normalizar($v['sexo'] ?? '');
        $sexo = in_array($sexoTexto, ['HOMBRE', 'MASCULINO', 'H', 'VARON'], true) ? 'H' : (in_array($sexoTexto, ['MUJER', 'FEMENINO', 'F'], true) ? 'M' : null);
        $datos = array_filter([
            'codigo_estudiante_siagie' => $codigo, 'numero_documento' => $dni, 'apellido_paterno' => $paterno, 'apellido_materno' => $materno, 'nombres' => $nombres,
            'sexo' => $sexo, 'fecha_nacimiento' => $this->fecha($v['fecha_nacimiento'] ?? null),
        ], fn ($x) => $x !== null && $x !== '');
        if ($alumno) {
            if ($codigo && $alumno->codigo_estudiante_siagie && $alumno->codigo_estudiante_siagie !== $codigo) {
                throw ValidationException::withMessages(['fila' => ["El DNI {$dni} ya está registrado con otro código SIAGIE."]]);
            }
            DB::table('alumnos')->where('id', $alumno->id)->update($datos);
            $id = (int) $alumno->id;
        } else {
            $id = (int) DB::table('alumnos')->insertGetId($datos + ['institucion_id' => ContextoInstitucion::requerido()]);
        }
        $actual = DB::table('matriculas')->where('alumno_id', $id)->where('anio_escolar_id', $seccion->anio_escolar_id)->first();
        if ($actual && (int) $actual->seccion_id !== (int) $seccion->id) {
            throw ValidationException::withMessages(['fila' => ['El alumno está matriculado en otra sección este año (el cambio de sección es otro trámite).']]);
        }
        if (! $actual) {
            $registro->matricular($id, (int) $seccion->id, $alumno ? 'ratificacion' : 'nuevo', 'siagie');
        }

        return ['alumno_id' => $id, 'nuevo' => ! $alumno];
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} */
    private function nombres(array $v): array
    {
        $t = fn ($x) => ($x = trim((string) $x)) === '' ? null : mb_convert_case(mb_strtolower($x), MB_CASE_TITLE);
        if (! empty($v['apellido_paterno']) || ! empty($v['nombres'])) {
            return [$t($v['apellido_paterno'] ?? null), $t($v['apellido_materno'] ?? null), $t($v['nombres'] ?? null)];
        }
        // «APELLIDO PATERNO APELLIDO MATERNO, NOMBRES» en una sola columna.
        $completo = trim((string) ($v['apellidos_nombres'] ?? ''));
        if (str_contains($completo, ',')) {
            [$ap, $no] = array_map('trim', explode(',', $completo, 2));
            $partes = preg_split('/\s+/', $ap);

            return [$t($partes[0] ?? null), $t(implode(' ', array_slice($partes, 1)) ?: null), $t($no)];
        }

        return [null, null, null];
    }

    private function fecha(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        try {
            return is_numeric($v) ? Carbon::instance(ExcelDate::excelToDateTimeObject((float) $v))->toDateString() : Carbon::createFromFormat('d/m/Y', trim((string) $v))->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
