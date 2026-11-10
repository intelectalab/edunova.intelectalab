<?php

namespace App\Modulos\ADM\Services;

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\Auditoria;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\CSV\Options as OpcionesCsv;
use OpenSpout\Reader\CSV\Reader as LectorCsv;
use OpenSpout\Reader\XLSX\Reader as LectorXlsx;
use OpenSpout\Writer\XLSX\Writer as EscritorXlsx;
use Throwable;

/**
 * EDUN-51 / ADM-H09 · Importar alumnos desde Excel (o CSV).
 *
 * 1. Plantilla descargable con las columnas y una hoja de instrucciones (grados y secciones del año).
 * 2. Vista previa: se valida fila por fila y se muestran los errores; nada se guarda todavía.
 * 3. Confirmar: se crean los alumnos, apoderados y matrículas de las filas sin errores.
 *    Si el alumno ya existe (mismo DNI o código SIAGIE) se reutiliza y solo se matricula.
 */
class ImportacionAlumnos
{
    public const COLUMNAS = [
        'dni' => 'DNI del alumno', 'siagie' => 'Código SIAGIE', 'apellido_paterno' => 'Apellido paterno', 'apellido_materno' => 'Apellido materno',
        'nombres' => 'Nombres', 'sexo' => 'Sexo (H/M)', 'fecha_nacimiento' => 'Fecha de nacimiento (dd/mm/aaaa)', 'grado' => 'Grado',
        'seccion' => 'Sección', 'apoderado_dni' => 'DNI del apoderado', 'apoderado_apellidos' => 'Apellidos del apoderado',
        'apoderado_nombres' => 'Nombres del apoderado', 'parentesco' => 'Parentesco', 'apoderado_celular' => 'Celular del apoderado',
        'apoderado_email' => 'Correo del apoderado', 'responsable_pago' => 'Responsable de pago (Sí/No)',
    ];

    public const MAX_FILAS = 2000;

    private const PARENTESCOS = [
        'padre' => 'padre', 'papa' => 'padre', 'madre' => 'madre', 'mama' => 'madre', 'abuelo' => 'abuelo', 'abuela' => 'abuela',
        'tio' => 'tio', 'tia' => 'tia', 'hermano' => 'hermano', 'hermana' => 'hermana', 'tutor' => 'tutor_legal', 'tutorlegal' => 'tutor_legal',
        'apoderado' => 'otro', 'otro' => 'otro', 'otra' => 'otro',
    ];

    public function __construct(private RegistroAlumnos $registro, private Auditoria $auditoria) {}

    /** @return string ruta del archivo .xlsx generado */
    public function plantilla(int $anioId): string
    {
        $anio = PlanEstudios::anio($anioId);
        $ruta = tempnam(sys_get_temp_dir(), 'plantilla').'.xlsx';
        $escritor = new EscritorXlsx;
        $escritor->openToFile($ruta);
        $escritor->getCurrentSheet()->setName('Alumnos');
        $escritor->addRow(Row::fromValues(array_values(self::COLUMNAS), (new Style)->setFontBold()));

        $escritor->addNewSheetAndMakeItCurrent()->setName('Instrucciones');
        $negrita = (new Style)->setFontBold();
        foreach ([
            ["Plantilla de importación de alumnos · año escolar {$anio->anio}"],
            ['Una fila por alumno. Obligatorios: apellido paterno, nombres, grado y sección.'],
            ['DNI de 8 dígitos y código SIAGIE de 14 (si los tienes). Escribe los DNI como texto para no perder los ceros iniciales.'],
            ['Si indicas el DNI del apoderado, completa sus apellidos, nombres y parentesco.'],
            ['Parentescos: padre, madre, abuelo, abuela, tío, tía, hermano, hermana, tutor legal, otro.'],
            [''],
        ] as $linea) {
            $escritor->addRow(Row::fromValues($linea));
        }
        $escritor->addRow(Row::fromValues(['Grado', 'Sección', 'Vacantes'], $negrita));
        foreach (app(Secciones::class)->listar($anioId) as $s) {
            $escritor->addRow(Row::fromValues([$s['grado'], $s['nombre'], $s['vacantes'] === null ? 'Sin límite' : $s['vacantes'] - $s['matriculados']]));
        }
        $escritor->close();

        return $ruta;
    }

    /**
     * Lee el archivo, valida cada fila y guarda el resultado 30 minutos para confirmarlo.
     *
     * @return array{token: string, total: int, validas: int, con_errores: int, filas: list<array<string, mixed>>}
     */
    public function vistaPrevia(UploadedFile $archivo, int $anioId, int $usuarioId): array
    {
        PlanEstudios::anio($anioId);
        $crudas = $this->leer($archivo);
        if ($crudas === []) {
            throw ValidationException::withMessages(['archivo' => ['El archivo no tiene filas de alumnos.']]);
        }
        if (count($crudas) > self::MAX_FILAS) {
            throw ValidationException::withMessages(['archivo' => ['El archivo tiene más de '.self::MAX_FILAS.' filas: divídelo en partes.']]);
        }

        $secciones = collect(app(Secciones::class)->listar($anioId))->keyBy(fn ($s) => self::claveGrado($s['grado']).'|'.mb_strtoupper($s['nombre']));
        $usoVacantes = [];
        $vistos = [];
        $filas = [];
        foreach ($crudas as $n => $c) {
            [$datos, $errores] = $this->validarFila($c, $secciones, $anioId);
            foreach (['dni' => 'DNI', 'siagie' => 'código SIAGIE'] as $k => $nombre) {
                if ($datos[$k]) {
                    if (isset($vistos[$k][$datos[$k]])) {
                        $errores[] = "El {$nombre} se repite en la fila {$vistos[$k][$datos[$k]]}.";
                    }
                    $vistos[$k][$datos[$k]] ??= $n;
                }
            }
            if ($errores === [] && $datos['seccion_id'] && ! $datos['ya_matriculado']) {
                $s = $secciones->firstWhere('id', $datos['seccion_id']);
                $usoVacantes[$s['id']] = ($usoVacantes[$s['id']] ?? 0) + 1;
                if ($s['vacantes'] !== null && $s['vacantes'] < $s['matriculados'] + $usoVacantes[$s['id']]) {
                    $errores[] = "{$s['grado']} {$s['nombre']} ya no tiene vacantes.";
                }
            }
            $filas[] = ['fila' => $n, 'datos' => $datos, 'errores' => $errores];
        }

        $token = Str::random(32);
        Cache::put(self::clave($token), ['usuario' => $usuarioId, 'anio' => $anioId, 'filas' => $filas], now()->addMinutes(30));
        $validas = count(array_filter($filas, fn ($f) => $f['errores'] === []));

        return ['token' => $token, 'total' => count($filas), 'validas' => $validas, 'con_errores' => count($filas) - $validas, 'filas' => $filas];
    }

    /** @return array{alumnos_nuevos: int, alumnos_existentes: int, matriculas: int, apoderados: int, invitados: int, omitidas: int} */
    public function confirmar(string $token, int $usuarioId): array
    {
        $guardado = Cache::get(self::clave($token));
        if (! $guardado || $guardado['usuario'] !== $usuarioId) {
            throw ValidationException::withMessages(['token' => ['La vista previa venció. Vuelve a subir el archivo.']]);
        }
        $r = ['alumnos_nuevos' => 0, 'alumnos_existentes' => 0, 'matriculas' => 0, 'apoderados' => 0, 'invitados' => 0, 'omitidas' => 0];
        DB::transaction(function () use ($guardado, &$r) {
            $institucion = ContextoInstitucion::requerido();
            foreach ($guardado['filas'] as $f) {
                if ($f['errores'] !== []) {
                    $r['omitidas']++;

                    continue;
                }
                $d = $f['datos'];
                $alumnoId = $d['alumno_id'];
                if ($alumnoId) {
                    $r['alumnos_existentes']++;
                } else {
                    $alumnoId = (int) DB::table('alumnos')->insertGetId([
                        'institucion_id' => $institucion, 'tipo_documento' => 'DNI', 'numero_documento' => $d['dni'], 'codigo_estudiante_siagie' => $d['siagie'],
                        'apellido_paterno' => $d['apellido_paterno'], 'apellido_materno' => $d['apellido_materno'], 'nombres' => $d['nombres'],
                        'sexo' => $d['sexo'], 'fecha_nacimiento' => $d['fecha_nacimiento'],
                    ]);
                    $r['alumnos_nuevos']++;
                }
                if ($d['apoderado']) {
                    $apoderado = $this->registro->vincularApoderado($alumnoId, $d['apoderado']);
                    $r['apoderados']++;
                    // EDUN-37 · Los que tienen correo reciben la invitación; con solo celular,
                    // queda el acceso creado y la secretaría envía el enlace desde la ficha.
                    $r['invitados'] += count(array_filter($this->registro->activarApoderados([$apoderado]), fn ($i) => $i['canal'] === 'correo'));
                }
                if (! $d['ya_matriculado']) {
                    $this->registro->matricular($alumnoId, $d['seccion_id'], $d['alumno_id'] ? 'ratificacion' : 'nuevo');
                    $r['matriculas']++;
                }
            }
        });
        Cache::forget(self::clave($token));
        $this->auditoria->registrar('alumnos_importados', 'alumnos', null, null, $r + ['anio_escolar_id' => $guardado['anio']]);

        return $r;
    }

    /** @return array<int, array<string, mixed>> filas del archivo (número de fila => columnas) */
    private function leer(UploadedFile $archivo): array
    {
        $extension = strtolower($archivo->getClientOriginalExtension());
        try {
            if ($extension === 'csv' || $extension === 'txt') {
                $opciones = new OpcionesCsv;
                $primera = (string) fgets(fopen($archivo->getRealPath(), 'r'));
                $opciones->FIELD_DELIMITER = substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',';
                $lector = new LectorCsv($opciones);
            } else {
                $lector = new LectorXlsx;
            }
            $lector->open($archivo->getRealPath());
            $filas = [];
            foreach ($lector->getSheetIterator() as $hoja) {
                foreach ($hoja->getRowIterator() as $i => $fila) {
                    if ($i === 1) {
                        continue; // encabezados
                    }
                    $valores = array_pad($fila->toArray(), count(self::COLUMNAS), null);
                    if (count(array_filter($valores, fn ($v) => $v !== null && trim((string) ($v instanceof DateTimeInterface ? 'x' : $v)) !== '')) === 0) {
                        continue;
                    }
                    $filas[$i] = array_combine(array_keys(self::COLUMNAS), array_slice($valores, 0, count(self::COLUMNAS)));
                }
                break; // solo la primera hoja
            }
            $lector->close();

            return $filas;
        } catch (Throwable) {
            throw ValidationException::withMessages(['archivo' => ['No pudimos leer el archivo. Usa la plantilla en Excel (.xlsx) o un CSV.']]);
        }
    }

    /** @return array{0: array<string, mixed>, 1: list<string>} */
    private function validarFila(array $c, $secciones, int $anioId): array
    {
        $txt = fn ($v) => $v === null ? null : (trim((string) $v) === '' ? null : trim((string) $v));
        // Excel guarda los DNI escritos como número sin sus ceros iniciales: solo en ese caso se rellenan.
        $doc = function ($v, int $largo) use ($txt) {
            if (is_float($v) || is_int($v)) {
                $n = number_format((float) $v, 0, '', '');

                return strlen($n) < $largo && strlen($n) >= $largo - 2 ? str_pad($n, $largo, '0', STR_PAD_LEFT) : $n;
            }

            return $txt($v);
        };
        $errores = [];
        $d = [
            'dni' => $doc($c['dni'], 8), 'siagie' => $doc($c['siagie'], 14),
            'apellido_paterno' => $txt($c['apellido_paterno']), 'apellido_materno' => $txt($c['apellido_materno']), 'nombres' => $txt($c['nombres']),
            'sexo' => $txt($c['sexo']) ? mb_strtoupper(mb_substr($txt($c['sexo']), 0, 1)) : null, 'fecha_nacimiento' => null,
            'grado' => $txt($c['grado']), 'seccion' => $txt($c['seccion']) ? mb_strtoupper($txt($c['seccion'])) : null,
            'seccion_id' => null, 'alumno_id' => null, 'ya_matriculado' => false, 'apoderado' => null,
        ];
        if (! $d['apellido_paterno']) {
            $errores[] = 'Falta el apellido paterno.';
        }
        if (! $d['nombres']) {
            $errores[] = 'Faltan los nombres.';
        }
        if ($d['dni'] && ! preg_match('/^\d{8}$/', $d['dni'])) {
            $errores[] = 'El DNI del alumno debe tener 8 dígitos.';
        }
        if ($d['siagie'] && ! preg_match('/^\d{14}$/', $d['siagie'])) {
            $errores[] = 'El código SIAGIE debe tener 14 dígitos.';
        }
        if ($d['sexo'] && ! in_array($d['sexo'], ['H', 'M'], true)) {
            $errores[] = 'El sexo debe ser H o M.';
        }
        if ($c['fecha_nacimiento'] instanceof DateTimeInterface) {
            $d['fecha_nacimiento'] = $c['fecha_nacimiento']->format('Y-m-d');
        } elseif ($f = $txt($c['fecha_nacimiento'])) {
            // Se acepta dd/mm/aaaa o aaaa-mm-dd, y la fecha debe existir (nada de 31/02).
            $partes = preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $f, $m) ? [(int) $m[3], (int) $m[2], (int) $m[1]]
                : (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $f, $m) ? [(int) $m[1], (int) $m[2], (int) $m[3]] : null);
            if ($partes && checkdate($partes[1], $partes[2], $partes[0])) {
                $d['fecha_nacimiento'] = Carbon::create(...$partes)->toDateString();
            } else {
                $errores[] = 'La fecha de nacimiento no es válida (usa dd/mm/aaaa).';
            }
        }

        if (! $d['grado'] || ! $d['seccion']) {
            $errores[] = 'Faltan el grado y la sección.';
        } else {
            $s = $secciones[self::claveGrado($d['grado']).'|'.$d['seccion']] ?? null;
            $s ? $d['seccion_id'] = $s['id'] : $errores[] = "No existe la sección {$d['grado']} {$d['seccion']} en el año elegido.";
        }

        // ¿Ya existe el alumno?
        if ($d['dni'] || $d['siagie']) {
            $existente = DB::table('alumnos')->whereNull('eliminado_at')
                ->where(fn ($q) => $q->when($d['dni'], fn ($w) => $w->orWhere(fn ($x) => $x->where('tipo_documento', 'DNI')->where('numero_documento', $d['dni'])))
                    ->when($d['siagie'], fn ($w) => $w->orWhere('codigo_estudiante_siagie', $d['siagie'])))
                ->value('id');
            if ($existente) {
                $d['alumno_id'] = (int) $existente;
                $matricula = DB::table('matriculas')->where('alumno_id', $existente)->where('anio_escolar_id', $anioId)->first();
                if ($matricula && (int) $matricula->seccion_id !== $d['seccion_id']) {
                    $errores[] = 'El alumno ya está matriculado este año en otra sección.';
                }
                $d['ya_matriculado'] = (bool) $matricula;
            }
        }

        $apDni = $doc($c['apoderado_dni'], 8);
        if ($apDni || $txt($c['apoderado_apellidos']) || $txt($c['apoderado_nombres'])) {
            $parentesco = self::PARENTESCOS[preg_replace('/[^a-z]/', '', Str::ascii(mb_strtolower((string) $txt($c['parentesco']))))] ?? null;
            if (! $apDni || ! preg_match('/^\d{8}$/', $apDni)) {
                $errores[] = 'El DNI del apoderado debe tener 8 dígitos.';
            }
            if (! $txt($c['apoderado_apellidos']) || ! $txt($c['apoderado_nombres'])) {
                $errores[] = 'Faltan los apellidos o nombres del apoderado.';
            }
            if (! $parentesco) {
                $errores[] = 'El parentesco no es válido (padre, madre, abuelo, tío, tutor legal…).';
            }
            $email = $txt($c['apoderado_email']);
            if ($email && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errores[] = 'El correo del apoderado no es válido.';
            }
            $pago = mb_strtolower((string) $txt($c['responsable_pago']));
            $d['apoderado'] = [
                'tipo_documento' => 'DNI', 'numero_documento' => $apDni, 'apellidos' => $txt($c['apoderado_apellidos']), 'nombres' => $txt($c['apoderado_nombres']),
                'parentesco' => $parentesco, 'celular' => $txt($c['apoderado_celular']), 'email' => $email,
                'es_responsable_pago' => $pago === '' || in_array($pago, ['si', 'sí', 's', 'x', '1', 'true'], true),
            ];
        }

        return [$d, $errores];
    }

    /** «3.° de Secundaria», «3° secundaria» y «3 secundaria» son el mismo grado. */
    public static function claveGrado(string $grado): string
    {
        $t = Str::ascii(str_replace(['°', 'º', 'ª'], '', mb_strtolower($grado)));

        return (string) preg_replace('/[^a-z0-9]/', '', (string) preg_replace('/\bde\b/', ' ', $t));
    }

    private static function clave(string $token): string
    {
        return 'importacion-alumnos:'.ContextoInstitucion::requerido().':'.$token;
    }
}
