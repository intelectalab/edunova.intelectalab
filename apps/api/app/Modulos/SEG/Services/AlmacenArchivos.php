<?php

namespace App\Modulos\SEG\Services;

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Models\Archivo;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-207 / SEG-H08 · Archivos en el almacenamiento de objetos compatible con S3.
 *
 * - El bucket es privado: no hay URL pública; se descarga solo con URL firmada que vence.
 * - Cada archivo de un colegio va bajo el prefijo «{institucion_id}/».
 * - El tamaño se valida antes de subir nada.
 * Los archivos confidenciales exigen permiso: lo resuelve la policy de cada módulo (EDUN-39).
 */
class AlmacenArchivos
{
    public const DISCO = 'objetos';

    public static function maximoBytes(): int
    {
        return (int) config('edunova.archivos.max_mb') * 1024 * 1024;
    }

    /** Lanza un error de validación con mensaje claro si el archivo supera el límite. */
    public static function validarTamano(int $bytes, string $campo = 'archivo'): void
    {
        $maxMb = (int) config('edunova.archivos.max_mb');
        if ($bytes > self::maximoBytes()) {
            $pesa = number_format($bytes / 1024 / 1024, 1, '.', ',');
            throw ValidationException::withMessages([
                $campo => ["El archivo pesa {$pesa} MB y el máximo permitido es {$maxMb} MB."],
            ]);
        }
    }

    public function guardar(UploadedFile $subido, ?int $usuarioId = null, bool $confidencial = false): Archivo
    {
        self::validarTamano((int) $subido->getSize());

        $institucionId = ContextoInstitucion::requerido();
        $extension = strtolower($subido->getClientOriginalExtension() ?: ($subido->guessExtension() ?? 'bin'));
        $ruta = sprintf('%d/%s/%s.%s', $institucionId, now()->format('Y/m'), Str::uuid7(), $extension);

        Storage::disk(self::DISCO)->putFileAs(dirname($ruta), $subido, basename($ruta));

        return Archivo::create([
            'institucion_id' => $institucionId,
            'disco' => self::DISCO,
            'ruta' => $ruta,
            'nombre_original' => Str::limit($subido->getClientOriginalName(), 250, ''),
            'mime' => $subido->getMimeType() ?? 'application/octet-stream',
            'bytes' => (int) $subido->getSize(),
            'sha256' => hash_file('sha256', $subido->getRealPath()),
            'subido_por' => $usuarioId,
            'confidencial' => $confidencial,
        ]);
    }

    /** Guarda un contenido generado por el sistema (XML, PDF) como archivo del colegio activo. */
    public function guardarContenido(string $contenido, string $nombre, string $mime, ?int $usuarioId = null, bool $confidencial = false): Archivo
    {
        $institucionId = ContextoInstitucion::requerido();
        $extension = strtolower(pathinfo($nombre, PATHINFO_EXTENSION) ?: 'bin');
        $ruta = sprintf('%d/%s/%s.%s', $institucionId, now()->format('Y/m'), Str::uuid7(), $extension);
        Storage::disk(self::DISCO)->put($ruta, $contenido);

        return Archivo::create([
            'institucion_id' => $institucionId, 'disco' => self::DISCO, 'ruta' => $ruta, 'nombre_original' => Str::limit($nombre, 250, ''),
            'mime' => $mime, 'bytes' => strlen($contenido), 'sha256' => hash('sha256', $contenido), 'subido_por' => $usuarioId, 'confidencial' => $confidencial,
        ]);
    }

    /** URL firmada de descarga, válida por config('edunova.archivos.url_minutos') (5 por defecto). */
    public function urlTemporal(Archivo $archivo): string
    {
        return self::discoFirmas($archivo->disco)->temporaryUrl(
            $archivo->ruta,
            now()->addMinutes((int) config('edunova.archivos.url_minutos')),
            ['ResponseContentDisposition' => 'attachment; filename="'.addcslashes($archivo->nombre_original, '"\\').'"'],
        );
    }

    /** URL firmada para cualquier ruta del almacenamiento (p. ej. exportaciones de la plataforma). */
    public static function urlTemporalDeRuta(string $disco, string $ruta, string $nombre, int $minutos): string
    {
        return self::discoFirmas($disco)->temporaryUrl($ruta, now()->addMinutes($minutos),
            ['ResponseContentDisposition' => 'attachment; filename="'.addcslashes($nombre, '"\\').'"']);
    }

    /**
     * Disco con el que se firman las URL. La firma de S3 (SigV4) incluye el host, así que no
     * sirve reemplazarlo después de firmar: si hay un endpoint público distinto del interno
     * (AWS_URL_FIRMAS), se firma directamente contra ese endpoint.
     */
    private static function discoFirmas(string $disco): Filesystem
    {
        $config = config("filesystems.disks.{$disco}");
        if (empty($config['temporary_url'])) {
            return Storage::disk($disco);
        }

        return Storage::build(['endpoint' => $config['temporary_url'], 'temporary_url' => null] + $config);
    }
}
