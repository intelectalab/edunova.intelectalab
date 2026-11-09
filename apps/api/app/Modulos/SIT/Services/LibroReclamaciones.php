<?php

namespace App\Modulos\SIT\Services;

use App\Modulos\Compartido\Correo\CorreoTransaccional;
use App\Modulos\SEG\Services\AlmacenArchivos;
use App\Modulos\SIT\Correo\AvisoReclamo;
use App\Modulos\SIT\Correo\ConfirmacionReclamo;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * EDUN-294 / SIT-010 · Libro de Reclamaciones virtual (Ley N.° 29571, D.S. N.° 011-2011-PCM).
 *
 * - Número correlativo REC-AAAA-NNNN y vencimiento a 15 días hábiles (sin sábados, domingos ni
 *   feriados nacionales: cms.sumar_dias_habiles).
 * - Adjuntos en el almacenamiento privado (pueden traer datos personales).
 * - Confirmación al reclamante y aviso a soporte por el correo transaccional (con bitácora).
 * El público solo inserta (RLS): el número sale de la secuencia antes de insertar.
 */
class LibroReclamaciones
{
    public const PLAZO_DIAS_HABILES = 15;

    private const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'setiembre', 'octubre', 'noviembre', 'diciembre'];

    public function __construct(private CorreoTransaccional $correo) {}

    /**
     * @param  array<string, mixed>  $d  datos validados
     * @param  list<UploadedFile>  $archivos
     * @return array{expediente: string, vence_en: string, email: string}
     */
    public function registrar(array $d, array $archivos, ?string $ip): array
    {
        $guardados = [];
        try {
            $registro = DB::transaction(function () use ($d, $archivos, $ip, &$guardados) {
                DB::select("select set_config('app.rol_cms', 'publico', true)");

                $hoy = CarbonImmutable::now('America/Lima');
                $n = (int) DB::selectOne("select nextval('cms.reclamaciones_correlativo') as n")->n;
                $expediente = sprintf('REC-%s-%04d', $hoy->format('Y'), $n);
                $vence = DB::selectOne('select cms.sumar_dias_habiles(?::date, ?) as d', [$hoy->toDateString(), self::PLAZO_DIAS_HABILES])->d;

                foreach ($archivos as $archivo) {
                    $guardados[] = $this->guardarAdjunto($expediente, $archivo);
                }

                DB::table('cms.reclamaciones')->insert([
                    'numero_expediente' => $expediente,
                    'tipo' => $d['tipo'],
                    'nombre_reclamante' => $d['nombre'],
                    'tipo_doc' => $d['tipo_doc'],
                    'numero_doc' => $d['numero_doc'],
                    'email' => $d['email'],
                    'telefono' => $d['telefono'] ?? null,
                    'direccion' => $d['direccion'],
                    'menor_edad' => (bool) ($d['menor_edad'] ?? false),
                    'apoderado_nombre' => $d['apoderado_nombre'] ?? null,
                    'apoderado_doc' => $d['apoderado_doc'] ?? null,
                    'producto_descripcion' => $d['producto'],
                    'monto_reclamado' => $d['monto'] ?? null,
                    'descripcion' => $d['descripcion'],
                    'pedido' => $d['pedido'],
                    'adjuntos' => json_encode($guardados),
                    'vence_en' => $vence,
                    'ip' => $ip,
                ]);

                return ['expediente' => $expediente, 'vence_en' => $vence, 'email' => $d['email']];
            });
        } catch (Throwable $e) {
            foreach ($guardados as $a) {
                Storage::disk(AlmacenArchivos::DISCO)->delete($a['ruta']);
            }
            throw $e;
        }

        $this->avisar($registro, $d, $guardados);

        return $registro;
    }

    /** «lunes 30 de noviembre de 2026». */
    public static function fechaLarga(string $fecha): string
    {
        $f = CarbonImmutable::parse($fecha);
        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

        return sprintf('%s %d de %s de %d', $dias[$f->dayOfWeek], $f->day, self::MESES[$f->month - 1], $f->year);
    }

    /** @return array{ruta: string, nombre: string, mime: string, bytes: int} */
    private function guardarAdjunto(string $expediente, UploadedFile $archivo): array
    {
        $mime = (string) $archivo->getMimeType();
        $extension = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? 'bin';
        $ruta = sprintf('sitio/reclamaciones/%s/%s.%s', $expediente, Str::uuid7(), $extension);
        Storage::disk(AlmacenArchivos::DISCO)->putFileAs(dirname($ruta), $archivo, basename($ruta));

        return [
            'ruta' => $ruta,
            'nombre' => Str::limit($archivo->getClientOriginalName(), 120, ''),
            'mime' => $mime,
            'bytes' => (int) $archivo->getSize(),
        ];
    }

    /** Si el correo falla, el reclamo ya quedó registrado: se deja en el log. */
    private function avisar(array $registro, array $d, array $adjuntos): void
    {
        $datos = [
            'expediente' => $registro['expediente'],
            'tipo' => $d['tipo'],
            'nombre' => $d['nombre'],
            'email' => $d['email'],
            'telefono' => $d['telefono'] ?? '',
            'producto' => $d['producto'],
            'descripcion' => $d['descripcion'],
            'pedido' => $d['pedido'],
            'vence_texto' => self::fechaLarga($registro['vence_en']),
            'url_seguimiento' => rtrim((string) config('services.sitio.url'), '/').'/libro-de-reclamaciones/seguimiento',
            'adjuntos' => count($adjuntos) > 0 ? implode(', ', array_column($adjuntos, 'nombre')) : 'ninguno',
        ];

        try {
            $this->correo->enviar(new ConfirmacionReclamo($datos), $d['email'], 'reclamo_confirmacion');

            $soporte = $this->correoSoporte();
            if ($soporte) {
                $this->correo->enviar(new AvisoReclamo($datos), $soporte, 'reclamo_aviso_interno');
            } else {
                Log::warning('Libro de Reclamaciones: falta el correo de soporte en cms.config (email_reclamaciones o email_contacto).', ['expediente' => $registro['expediente']]);
            }
        } catch (Throwable $e) {
            Log::error('Libro de Reclamaciones: no se pudo encolar el correo.', ['expediente' => $registro['expediente'], 'error' => $e->getMessage()]);
        }
    }

    private function correoSoporte(): ?string
    {
        $config = DB::transaction(function () {
            DB::select("select set_config('app.rol_cms', 'publico', true)");

            return DB::table('cms.config')->whereIn('clave', ['email_reclamaciones', 'email_contacto'])->pluck('valor', 'clave');
        });
        foreach (['email_reclamaciones', 'email_contacto'] as $clave) {
            $valor = json_decode((string) $config->get($clave), true);
            if (is_string($valor) && filter_var($valor, FILTER_VALIDATE_EMAIL)) {
                return $valor;
            }
        }

        return null;
    }
}
