<?php

namespace App\Modulos\PLA\Services;

use App\Modulos\SEG\Services\AlmacenArchivos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Throwable;
use ZipArchive;

/**
 * EDUN-34 / PLA-H08 · Exporta todos los datos de un colegio para su baja o portabilidad.
 *
 * Un ZIP con, por cada módulo, un JSON (tabla → filas) y un Excel (una hoja por tabla), y la
 * carpeta archivos/ con los adjuntos del colegio tal como están en el almacenamiento. Se arma con
 * la conexión de plataforma (todas las tablas con institucion_id, también las de salud) y se
 * guarda en el almacenamiento privado; se descarga con un enlace firmado.
 */
class ExportacionColegio
{
    public const CONEXION = ConsolaInstituciones::CONEXION;

    /** Módulo de cada tabla por su nombre (el resto es ADM). */
    private const MODULOS = [
        'PLA' => ['suscripciones', 'suscripcion_modulos', 'facturas_saas', 'tickets_soporte', 'ticket_mensajes', 'ticket_adjuntos'],
        'SEG' => ['membresias', 'auditoria', 'consentimientos', 'consentimiento_textos', 'archivos', 'invitaciones'],
        'PAG' => ['conceptos_cobro', 'cronogramas', 'cronograma_cuotas', 'beneficios', 'alumno_beneficios', 'cargos', 'pagos', 'pago_aplicaciones', 'cajas',
            'caja_sesiones', 'proveedores_facturacion', 'comprobantes', 'comprobante_items', 'pasarela_cuentas', 'pasarela_transacciones', 'recaudacion_archivos',
            'recaudacion_lineas', 'compromisos_pago', 'convenios_fraccionamiento', 'convenio_cargos', 'convenio_cuotas', 'gestiones_cobranza'],
        'CAL' => ['actividades_evaluacion', 'notas_actividad', 'calificaciones', 'cierres_periodo', 'asistencias', 'justificaciones', 'incidencias_conducta',
            'entrevistas_tutoria', 'alertas_riesgo', 'libretas', 'evaluaciones_recuperacion'],
        'COM' => ['comunicados', 'comunicado_destinatarios', 'conversaciones', 'conversacion_participantes', 'mensajes', 'eventos_calendario', 'citas',
            'reclamaciones', 'reclamacion_respuestas', 'notificaciones'],
        'SIA' => ['siagie_procesos', 'siagie_errores', 'siagie_mapeos', 'siagie_pendientes'],
        'DOC' => ['tramite_tipos', 'tramite_requisitos', 'tramites', 'tramite_historial', 'tramite_adjuntos', 'plantillas_documento', 'documentos_emitidos',
            'expediente_documentos', 'documentos_institucionales', 'documento_lecturas', 'autorizaciones', 'autorizacion_firmas'],
    ];

    private const PREFIJOS = ['aula_' => 'AUL', 'moodle_' => 'MDL', 'bib_' => 'BIB', 'mov_' => 'MOV', 'com_' => 'COD', 'tienda_' => 'TIE', 'por_' => 'POR', 'lab_' => 'LAB'];

    /** Columnas que nunca salen (secretos cifrados). */
    private const OCULTAS = ['token_cifrado', 'sso_secreto_cifrado', 'token_hash', 'credenciales_cifradas', 'clave_cifrada'];

    public static function modulo(string $tabla): string
    {
        if (str_starts_with($tabla, 'salud.')) {
            return 'SAL';
        }
        foreach (self::MODULOS as $m => $tablas) {
            if (in_array($tabla, $tablas, true)) {
                return $m;
            }
        }
        foreach (self::PREFIJOS as $p => $m) {
            if (str_starts_with($tabla, $p)) {
                return $m;
            }
        }

        return 'ADM';
    }

    public function solicitar(int $institucionId, string $motivo, int $usuarioId): int
    {
        abort_if(! DB::connection(self::CONEXION)->table('instituciones')->where('id', $institucionId)->exists(), 404);

        return (int) DB::connection(self::CONEXION)->table('exportaciones')->insertGetId([
            'institucion_id' => $institucionId, 'motivo' => $motivo, 'solicitado_por' => $usuarioId,
        ]);
    }

    public function generar(int $exportacionId): void
    {
        $db = DB::connection(self::CONEXION);
        $exp = $db->table('exportaciones')->find($exportacionId);
        if (! $exp || $exp->estado === 'lista') {
            return;
        }
        $db->table('exportaciones')->where('id', $exportacionId)->update(['estado' => 'procesando']);
        $dir = sys_get_temp_dir().'/exportacion-'.$exportacionId.'-'.bin2hex(random_bytes(4));
        @mkdir($dir, 0700, true);
        $zipRuta = "{$dir}.zip";
        try {
            $id = (int) $exp->institucion_id;
            $institucion = (array) $db->table('instituciones')->find($id);
            $tablas = collect($db->select("
                select case when table_schema = 'public' then table_name else table_schema || '.' || table_name end as tabla
                from information_schema.columns
                where column_name = 'institucion_id' and table_schema in ('public', 'salud')
                order by 1"))->pluck('tabla')->reject(fn ($t) => in_array($t, ['exportaciones', 'personal_access_tokens'], true))->values();

            $zip = new ZipArchive;
            $zip->open($zipRuta, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $resumen = ['colegio' => $institucion['nombre'], 'modulos' => [], 'archivos' => 0];
            $zip->addFromString('colegio.json', json_encode(self::limpiar($institucion), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            foreach ($tablas->groupBy(fn ($t) => self::modulo($t)) as $modulo => $grupo) {
                $datos = [];
                foreach ($grupo as $tabla) {
                    $datos[$tabla] = $db->table($tabla)->where('institucion_id', $id)->orderBy('id')->get()->map(fn ($f) => self::limpiar((array) $f))->all();
                }
                if ($modulo === 'SEG') {
                    // Las personas con acceso al colegio (sin contraseñas ni secretos).
                    $datos['usuarios'] = $db->table('users')->whereIn('id', $db->table('membresias')->where('institucion_id', $id)->select('user_id'))
                        ->orderBy('id')->get(['id', 'nombres', 'apellidos', 'tipo_documento', 'numero_documento', 'email', 'celular', 'activo', 'created_at'])
                        ->map(fn ($f) => (array) $f)->all();
                }
                $resumen['modulos'][$modulo] = array_map('count', $datos);
                $zip->addFromString("{$modulo}/{$modulo}.json", json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $xlsx = "{$dir}/{$modulo}.xlsx";
                $this->excel($xlsx, $datos);
                $zip->addFile($xlsx, "{$modulo}/{$modulo}.xlsx");
            }

            // Adjuntos tal como están (carpeta del colegio en el almacenamiento de objetos).
            $disco = Storage::disk(AlmacenArchivos::DISCO);
            foreach ($db->table('archivos')->where('institucion_id', $id)->get(['id', 'ruta', 'nombre_original']) as $a) {
                $contenido = $disco->exists($a->ruta) ? $disco->get($a->ruta) : null;
                if ($contenido !== null) {
                    $zip->addFromString("archivos/{$a->id}-".preg_replace('/[^\w.\- ]/u', '_', $a->nombre_original), $contenido);
                    $resumen['archivos']++;
                }
            }
            $zip->addFromString('LEEME.txt', "Exportación de {$institucion['nombre']} generada por Edunova el ".now()->timezone('America/Lima')->format('d/m/Y H:i').".\n"
                ."Cada carpeta es un módulo: un JSON (tabla → filas) y un Excel con una hoja por tabla.\n"
                ."La carpeta archivos/ trae los adjuntos; el número inicial es el id de la tabla archivos.\n");
            $zip->close();

            $ruta = "plataforma/exportaciones/{$id}/exportacion-{$exportacionId}.zip";
            $disco->putFileAs(dirname($ruta), $zipRuta, basename($ruta));
            $db->table('exportaciones')->where('id', $exportacionId)->update([
                'estado' => 'lista', 'ruta' => $ruta, 'bytes' => filesize($zipRuta), 'resumen' => json_encode($resumen, JSON_UNESCAPED_UNICODE), 'completado_at' => now(),
            ]);
        } catch (Throwable $e) {
            $db->table('exportaciones')->where('id', $exportacionId)->update(['estado' => 'fallida', 'error' => mb_substr($e->getMessage(), 0, 2000)]);
            throw $e;
        } finally {
            foreach (glob("{$dir}/*") ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
            @unlink($zipRuta);
        }
    }

    public function enlace(int $exportacionId): array
    {
        $exp = DB::connection(self::CONEXION)->table('exportaciones')->find($exportacionId);
        abort_if(! $exp || $exp->estado !== 'lista', 404);
        $slug = DB::connection(self::CONEXION)->table('instituciones')->where('id', $exp->institucion_id)->value('slug');

        return ['url' => AlmacenArchivos::urlTemporalDeRuta(AlmacenArchivos::DISCO, $exp->ruta, "edunova-{$slug}-".substr((string) $exp->completado_at, 0, 10).'.zip', 5)];
    }

    /** @param array<string, list<array<string, mixed>>> $datos */
    private function excel(string $ruta, array $datos): void
    {
        $w = new Writer;
        $w->openToFile($ruta);
        $primera = true;
        foreach ($datos as $tabla => $filas) {
            $hoja = $primera ? $w->getCurrentSheet() : $w->addNewSheetAndMakeItCurrent();
            $primera = false;
            $hoja->setName(mb_substr(str_replace('.', '_', $tabla), 0, 31));
            if ($filas === []) {
                $w->addRow(Row::fromValues(['(sin datos)']));

                continue;
            }
            $w->addRow(Row::fromValues(array_keys($filas[0]), (new Style)->setFontBold()));
            foreach ($filas as $f) {
                $w->addRow(Row::fromValues(array_map(fn ($v) => is_array($v) || is_object($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (is_bool($v) ? ($v ? 'sí' : 'no') : $v), array_values($f))));
            }
        }
        $w->close();
    }

    /** @param array<string, mixed> $fila */
    private static function limpiar(array $fila): array
    {
        foreach (self::OCULTAS as $c) {
            unset($fila[$c]);
        }

        return $fila;
    }
}
