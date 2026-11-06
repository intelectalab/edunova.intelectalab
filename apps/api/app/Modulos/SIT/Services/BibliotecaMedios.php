<?php

namespace App\Modulos\SIT\Services;

use App\Models\User;
use enshrined\svgSanitize\Sanitizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-237 / SIT-045 · Biblioteca de medios del sitio (cms.medios).
 *
 * - Imágenes JPG, PNG, WebP y SVG, y PDF, hasta 5 MB.
 * - Los SVG se sanitizan antes de guardar (sin scripts, eventos ni enlaces javascript:).
 * - Se guardan en el disco «medios_sitio»: es público, porque son imágenes del sitio web.
 *   Los archivos de los colegios van en «objetos», que es privado (EDUN-207).
 * - Toda subida queda en cms.auditoria.
 */
class BibliotecaMedios
{
    public const DISCO = 'medios_sitio';

    public const MAX_BYTES = 5 * 1024 * 1024;

    /** mime => [tipo en cms.medios, extensión] */
    private const PERMITIDOS = [
        'image/jpeg' => ['imagen', 'jpg'],
        'image/png' => ['imagen', 'png'],
        'image/webp' => ['imagen', 'webp'],
        'image/svg+xml' => ['svg', 'svg'],
        'application/pdf' => ['pdf', 'pdf'],
    ];

    public function subir(UploadedFile $archivo, ?string $alt, User $usuario, ?string $ip): object
    {
        if ($archivo->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['archivo' => ['El archivo supera 5 MB.']]);
        }
        $mime = $this->mimeReal($archivo);
        if (! isset(self::PERMITIDOS[$mime])) {
            throw ValidationException::withMessages(['archivo' => ['Solo se aceptan imágenes JPG, PNG, WebP o SVG y documentos PDF.']]);
        }
        [$tipo, $extension] = self::PERMITIDOS[$mime];

        $contenido = file_get_contents($archivo->getRealPath());
        [$ancho, $alto] = [null, null];
        if ($tipo === 'svg') {
            $contenido = $this->sanitizarSvg($contenido);
            [$ancho, $alto] = $this->medidasSvg($contenido);
        } elseif ($tipo === 'imagen') {
            $medidas = @getimagesizefromstring($contenido);
            if (! $medidas) {
                throw ValidationException::withMessages(['archivo' => ['No pudimos leer la imagen. Revisa que el archivo no esté dañado.']]);
            }
            [$ancho, $alto] = [$medidas[0], $medidas[1]];
        }

        $ruta = 'medios/'.now()->format('Y/m').'/'.Str::uuid7().'.'.$extension;
        Storage::disk(self::DISCO)->put($ruta, $contenido, ['ContentType' => $mime]);

        $nombre = Str::limit($archivo->getClientOriginalName(), 200, '');
        $id = DB::table('cms.medios')->insertGetId([
            'tipo' => $tipo,
            'nombre' => $nombre,
            'url' => Storage::disk(self::DISCO)->url($ruta),
            'mime' => $mime,
            'bytes' => strlen($contenido),
            'ancho' => $ancho,
            'alto' => $alto,
            'alt' => $alt !== null && trim($alt) !== '' ? Str::limit(trim($alt), 250, '') : null,
            'subido_por' => $usuario->id,
        ]);

        DB::table('cms.auditoria')->insert([
            'usuario_id' => $usuario->id,
            'usuario_rol' => 'superadmin',
            'seccion' => 'medios',
            'accion' => 'editado',
            'entidad' => 'medios',
            'entidad_id' => (string) $id,
            'detalle' => Str::limit("Biblioteca de medios · subido {$nombre}", 250, ''),
            'valor_despues' => json_encode(['nombre' => $nombre, 'mime' => $mime, 'bytes' => strlen($contenido)]),
            'ip' => $ip,
        ]);

        return DB::table('cms.medios')->find($id);
    }

    public function cambiarAlt(int $id, string $alt, User $usuario, ?string $ip): object
    {
        $actual = DB::table('cms.medios')->find($id) ?? abort(404, 'No encontramos ese archivo.');
        DB::table('cms.medios')->where('id', $id)->update(['alt' => Str::limit(trim($alt), 250, '')]);
        DB::table('cms.auditoria')->insert([
            'usuario_id' => $usuario->id, 'usuario_rol' => 'superadmin', 'seccion' => 'medios', 'accion' => 'editado',
            'entidad' => 'medios', 'entidad_id' => (string) $id, 'campo' => 'alt',
            'detalle' => Str::limit("Biblioteca de medios · texto alternativo de {$actual->nombre}", 250, ''),
            'valor_antes' => json_encode($actual->alt), 'valor_despues' => json_encode($alt), 'ip' => $ip,
        ]);

        return DB::table('cms.medios')->find($id);
    }

    /**
     * Regla para las historias que publican una imagen (módulos, testimonios, SEO…):
     * una imagen sin texto alternativo no se publica.
     */
    public static function exigirTextoAlternativo(int $id, string $campo = 'imagen_id'): void
    {
        $medio = DB::table('cms.medios')->find($id);
        if ($medio && in_array($medio->tipo, ['imagen', 'svg'], true) && trim((string) $medio->alt) === '') {
            throw ValidationException::withMessages([$campo => ['La imagen necesita un texto alternativo antes de publicarse.']]);
        }
    }

    /** Tipo real por el contenido, no por la extensión ni lo que diga el navegador. */
    private function mimeReal(UploadedFile $archivo): string
    {
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($archivo->getRealPath());
        // finfo reconoce los SVG como texto si no traen declaración XML.
        if (in_array($mime, ['text/plain', 'text/xml', 'application/xml', 'image/svg'], true)
            && str_contains((string) file_get_contents($archivo->getRealPath(), false, null, 0, 4096), '<svg')) {
            return 'image/svg+xml';
        }

        return $mime;
    }

    private function sanitizarSvg(string $svg): string
    {
        $sanitizador = new Sanitizer;
        $sanitizador->removeRemoteReferences(true);
        $limpio = $sanitizador->sanitize($svg);
        if ($limpio === false || trim($limpio) === '') {
            throw ValidationException::withMessages(['archivo' => ['El SVG no es válido.']]);
        }

        return $limpio;
    }

    /** @return array{0: ?int, 1: ?int} */
    private function medidasSvg(string $svg): array
    {
        $xml = @simplexml_load_string($svg);
        if (! $xml) {
            return [null, null];
        }
        $a = (string) $xml['width'];
        $h = (string) $xml['height'];
        if (is_numeric(rtrim($a, 'px')) && is_numeric(rtrim($h, 'px'))) {
            return [(int) rtrim($a, 'px'), (int) rtrim($h, 'px')];
        }
        $caja = preg_split('/[\s,]+/', trim((string) $xml['viewBox']));

        return count($caja) === 4 ? [(int) $caja[2], (int) $caja[3]] : [null, null];
    }
}
