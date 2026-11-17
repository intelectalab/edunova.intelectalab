<?php

namespace App\Modulos\DOC\Services;

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Models\Archivo;
use App\Modulos\SEG\Services\AlmacenArchivos;
use App\Modulos\SEG\Services\Auditoria;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-116 / EDUN-118 · Documento emitido con firma y código de verificación.
 *
 * - El PDF lleva el contenido de la plantilla, el bloque de firma (imagen de la firma si el
 *   firmante la cargó en su ficha), el código de verificación y un QR al enlace público.
 * - Se guarda el SHA-256 del PDF; anular no borra: marca anulado_at y la verificación lo dice.
 * - Firma simple (imagen + hash + QR). La firma digital con certificado queda como conector
 *   posterior (decisión abierta en la especificación del módulo).
 * - La verificación pública usa fn_verificar_documento: solo colegio, tipo, fecha y vigencia.
 */
class Emision
{
    public function __construct(private Plantillas $plantillas, private AlmacenArchivos $almacen, private Auditoria $auditoria) {}

    /** Enlace público de verificación del código. */
    public static function enlace(string $codigo): string
    {
        return rtrim((string) config('edunova.web_url'), '/').'/#/verificar/'.$codigo;
    }

    /** HTML final (variables rellenas) de una plantilla para un alumno. */
    public function html(int $plantillaId, int $alumnoId, ?object $tramite, ?int $firmanteUserId): array
    {
        $p = DB::table('plantillas_documento')->find($plantillaId);
        abort_if(! $p, 404, 'La plantilla no existe.');

        return ['plantilla' => $p, 'html' => $this->plantillas->rellenar($p->contenido_html, $this->plantillas->valores($alumnoId, $tramite, $firmanteUserId))];
    }

    /** Vista previa en PDF (marca de agua «Vista previa», sin código real). */
    public function vistaPrevia(int $plantillaId, int $alumnoId, int $usuarioId): string
    {
        ['plantilla' => $p, 'html' => $html] = $this->html($plantillaId, $alumnoId, null, $usuarioId);

        return $this->pdf($html, $p->con_qr ? 'VISTA-PREVIA' : null, $usuarioId, true);
    }

    /**
     * Emite (firma) el documento de una plantilla para un alumno y lo guarda.
     *
     * @return array{id: int, codigo: string}
     */
    public function emitir(int $plantillaId, int $alumnoId, ?object $tramite, int $firmanteUserId): array
    {
        ['plantilla' => $p, 'html' => $html] = $this->html($plantillaId, $alumnoId, $tramite, $firmanteUserId);
        $codigo = $this->codigoNuevo();
        $pdf = $this->pdf($html, $p->con_qr ? $codigo : null, $firmanteUserId, false, $codigo);
        $archivo = $this->almacen->guardarContenido($pdf, Str::slug($p->nombre).'-'.$codigo.'.pdf', 'application/pdf', $firmanteUserId, true);
        $id = (int) DB::table('documentos_emitidos')->insertGetId([
            'institucion_id' => ContextoInstitucion::requerido(), 'plantilla_documento_id' => $p->id, 'alumno_id' => $alumnoId,
            'codigo_verificacion' => $codigo, 'sha256' => hash('sha256', $pdf), 'archivo_id' => $archivo->id,
            'firmado_por' => $firmanteUserId, 'firmado_at' => now(), 'tramite_id' => $tramite?->id,
        ]);
        $this->auditoria->registrar('documento_emitido', 'documentos_emitidos', $id, null, ['codigo' => substr($codigo, 0, 6).'…', 'plantilla' => $p->nombre, 'alumno_id' => $alumnoId]);

        return ['id' => $id, 'codigo' => $codigo];
    }

    /** Registra como emitido un archivo ya firmado que se sube (trámites sin plantilla). */
    public function registrarArchivo(Archivo $archivo, int $alumnoId, ?object $tramite, int $usuarioId): array
    {
        $codigo = $this->codigoNuevo();
        $id = (int) DB::table('documentos_emitidos')->insertGetId([
            'institucion_id' => ContextoInstitucion::requerido(), 'alumno_id' => $alumnoId, 'codigo_verificacion' => $codigo,
            'sha256' => $archivo->sha256, 'archivo_id' => $archivo->id, 'firmado_por' => $usuarioId, 'firmado_at' => now(), 'tramite_id' => $tramite?->id,
        ]);
        $this->auditoria->registrar('documento_emitido', 'documentos_emitidos', $id, null, ['codigo' => substr($codigo, 0, 6).'…', 'archivo' => $archivo->nombre_original, 'alumno_id' => $alumnoId]);

        return ['id' => $id, 'codigo' => $codigo];
    }

    public function anular(int $id, string $motivo, int $usuarioId): void
    {
        $d = DB::table('documentos_emitidos')->where('id', $id)->lockForUpdate()->first();
        abort_if(! $d, 404);
        if ($d->anulado_at) {
            throw ValidationException::withMessages(['documento' => ['Ese documento ya estaba anulado.']]);
        }
        DB::table('documentos_emitidos')->where('id', $id)->update(['anulado_at' => now(), 'anulado_motivo' => mb_substr(trim($motivo), 0, 300), 'anulado_por' => $usuarioId]);
        $this->auditoria->registrar('documento_anulado', 'documentos_emitidos', $id, ['vigente' => true], ['vigente' => false, 'motivo' => trim($motivo)]);
    }

    /** Verificación pública (sin sesión): solo datos mínimos. */
    public static function verificar(string $codigo): ?array
    {
        $codigo = strtoupper(trim($codigo));
        if (! preg_match('/^[A-Z0-9-]{6,24}$/', $codigo)) {
            return null;
        }
        $f = DB::selectOne('select * from fn_verificar_documento(?)', [$codigo]);

        return $f ? ['colegio' => $f->institucion, 'tipo' => $f->tipo, 'emitido' => $f->emitido, 'vigente' => (bool) $f->vigente] : null;
    }

    public function url(int $documentoId): string
    {
        $d = DB::table('documentos_emitidos')->find($documentoId, ['archivo_id']);
        abort_if(! $d, 404);

        return $this->almacen->urlTemporal(Archivo::query()->findOrFail($d->archivo_id));
    }

    private function codigoNuevo(): string
    {
        do {
            $codigo = 'D'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4));
            $codigo = strtr($codigo, ['O' => '7', '0' => '8', 'I' => '9', 'L' => '6']);
        } while (DB::table('documentos_emitidos')->where('codigo_verificacion', $codigo)->exists());

        return $codigo;
    }

    private function pdf(string $cuerpo, ?string $codigoQr, int $firmanteUserId, bool $vistaPrevia, ?string $codigo = null): string
    {
        $i = DB::table('instituciones')->where('id', ContextoInstitucion::requerido())->first(['nombre', 'color_primario', 'logo_archivo_id']);
        $color = $i->color_primario ?: '#27496f';
        $logo = $i->logo_archivo_id ? $this->imagen((int) $i->logo_archivo_id) : null;
        $p = DB::table('personal')->where('user_id', $firmanteUserId)->first(['nombres', 'apellidos', 'cargo', 'firma_archivo_id']);
        $u = $p ? null : DB::table('users')->where('id', $firmanteUserId)->first(['nombres', 'apellidos']);
        $nombre = $p ? "{$p->nombres} {$p->apellidos}" : trim(($u->nombres ?? '').' '.($u->apellidos ?? ''));
        $firma = $p?->firma_archivo_id ? $this->imagen((int) $p->firma_archivo_id) : null;
        $e = fn ($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
        $qr = $codigoQr ? 'data:image/svg+xml;base64,'.base64_encode((new Writer(new ImageRenderer(new RendererStyle(160, 1), new SvgImageBackEnd)))->writeString(self::enlace($codigoQr))) : null;

        $html = '<html><head><meta charset="utf-8"><style>
            @page { margin: 22mm 20mm 26mm; } body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; line-height: 1.5; }
            .cab { border-bottom: 1mm solid '.$e($color).'; padding-bottom: 3mm; margin-bottom: 8mm; } .cab img { height: 14mm; vertical-align: middle; margin-right: 4mm; }
            .cab span { font-size: 14px; font-weight: bold; color: '.$e($color).'; vertical-align: middle; }
            h1 { font-size: 16px; text-align: center; } table { border-collapse: collapse; } td, th { border: 0.2mm solid #cbd5e1; padding: 1.5mm; }
            .firma { margin-top: 18mm; text-align: center; } .firma img { height: 18mm; } .linea { border-top: 0.3mm solid #1f2937; width: 70mm; margin: 2mm auto 1mm; }
            .pie { position: fixed; bottom: -16mm; left: 0; right: 0; font-size: 8px; color: #64748b; } .pie img { width: 20mm; height: 20mm; float: right; }
            .marca { position: fixed; top: 90mm; left: 10mm; font-size: 60px; color: #e2e8f0; transform: rotate(-30deg); }
            </style></head><body>'
            .($vistaPrevia ? '<div class="marca">VISTA PREVIA</div>' : '')
            .'<div class="cab">'.($logo ? '<img src="'.$logo.'">' : '').'<span>'.$e($i->nombre).'</span></div>'
            .$cuerpo
            .'<div class="firma">'.($firma ? '<img src="'.$firma.'"><br>' : '').'<div class="linea"></div><b>'.$e($nombre).'</b><br>'.$e($p->cargo ?? 'Director(a)').'</div>'
            .'<div class="pie">'.($qr ? '<img src="'.$qr.'">' : '')
            .($vistaPrevia ? 'Vista previa: este documento no tiene validez.' : 'Firmado electrónicamente el '.now()->format('d/m/Y H:i').'. Código de verificación: <b>'.$e($codigo).'</b><br>Verifícalo en '.$e(self::enlace((string) $codigo)))
            .'</div></body></html>';

        $pdf = new Dompdf(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false]);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }

    private function imagen(int $archivoId): ?string
    {
        $a = DB::table('archivos')->find($archivoId, ['disco', 'ruta', 'mime']);
        if (! $a || ! str_starts_with($a->mime, 'image/')) {
            return null;
        }
        try {
            $contenido = Storage::disk($a->disco)->get($a->ruta);
        } catch (\Throwable) {
            // Si la imagen no está en el almacén, el documento sale igual (sin logo o sin firma).
            return null;
        }

        return $contenido === null ? null : 'data:'.$a->mime.';base64,'.base64_encode($contenido);
    }
}
