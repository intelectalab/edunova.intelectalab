<?php

namespace App\Modulos\ADM\Services;

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\Auditoria;
use App\Modulos\SEG\Services\Consentimientos;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * EDUN-59 / ADM-H17 · Carné con QR.
 *
 * - Código único por alumno en alumnos.codigo_carne; el QR solo lleva ese código (sin datos
 *   personales). El mismo QR sirve para asistencia, portería y comedor (buscar()).
 * - Regenerar crea un código nuevo: el anterior deja de servir.
 * - Carné en PDF (8 por hoja A4) y en la app de la familia o del alumno.
 * - La foto solo se imprime con el consentimiento de imagen vigente (EDUN-41).
 */
class Carnes
{
    public function __construct(private Auditoria $auditoria, private Consentimientos $consentimientos) {}

    public function codigo(int $alumnoId): string
    {
        $actual = DB::table('alumnos')->where('id', $alumnoId)->value('codigo_carne');

        return $actual ?: $this->regenerar($alumnoId, auditar: false);
    }

    public function regenerar(int $alumnoId, bool $auditar = true): string
    {
        $antes = DB::table('alumnos')->where('id', $alumnoId)->value('codigo_carne');
        do {
            $codigo = 'EN'.strtoupper(Str::random(10));
        } while (DB::table('alumnos')->where('codigo_carne', $codigo)->exists());
        DB::table('alumnos')->where('id', $alumnoId)->update(['codigo_carne' => $codigo]);
        if ($auditar) {
            $this->auditoria->registrar('carne_regenerado', 'alumnos', $alumnoId, ['codigo_carne' => $antes ? substr($antes, 0, 4).'…' : null], ['codigo_carne' => substr($codigo, 0, 4).'…']);
        }

        return $codigo;
    }

    /** Alumno por el código de su carné (asistencia, portería, comedor). */
    public function buscar(string $codigo): ?array
    {
        $a = DB::table('alumnos as a')
            ->leftJoin('matriculas as m', fn ($j) => $j->on('m.alumno_id', '=', 'a.id')->where('m.estado', '=', 'matriculado'))
            ->leftJoin('secciones as s', 's.id', '=', 'm.seccion_id')->leftJoin('grados as g', 'g.id', '=', 's.grado_id')
            ->where('a.codigo_carne', strtoupper(trim($codigo)))->whereNull('a.eliminado_at')
            ->orderByDesc('m.anio_escolar_id')
            ->first(['a.id', 'a.apellido_paterno', 'a.apellido_materno', 'a.nombres', 'a.estado', DB::raw("g.nombre || ' ' || s.nombre as seccion")]);

        return $a ? (array) $a : null;
    }

    /** Datos del carné para la app: código, QR y foto (si hay consentimiento). */
    public function datos(int $alumnoId): array
    {
        $a = DB::table('alumnos')->find($alumnoId);
        abort_if(! $a, 404);
        $codigo = $this->codigo($alumnoId);
        $seccion = DB::table('matriculas as m')->join('secciones as s', 's.id', '=', 'm.seccion_id')->join('grados as g', 'g.id', '=', 's.grado_id')
            ->join('anios_escolares as y', 'y.id', '=', 'm.anio_escolar_id')->where('m.alumno_id', $alumnoId)->where('m.estado', 'matriculado')
            ->orderByDesc('y.anio')->first([DB::raw("g.nombre || ' ' || s.nombre as nombre"), 'y.anio']);

        return [
            'codigo' => $codigo, 'qr' => 'data:image/svg+xml;base64,'.base64_encode(self::qr($codigo)),
            'alumno' => trim("{$a->nombres} {$a->apellido_paterno} {$a->apellido_materno}"), 'documento' => $a->numero_documento,
            'seccion' => $seccion?->nombre, 'anio' => $seccion?->anio,
            'colegio' => DB::table('instituciones')->where('id', ContextoInstitucion::requerido())->value('nombre'),
            'foto' => $this->foto($a),
        ];
    }

    /**
     * PDF con los carnés de un alumno o de toda una sección (8 por hoja).
     *
     * @param  list<int>  $alumnos
     */
    public function pdf(array $alumnos): string
    {
        $i = DB::table('instituciones')->where('id', ContextoInstitucion::requerido())->first(['nombre', 'color_primario', 'logo_archivo_id']);
        $color = $i->color_primario ?: '#27496f';
        $logo = $i->logo_archivo_id ? $this->imagen((int) $i->logo_archivo_id) : null;
        $e = fn ($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');

        $tarjetas = '';
        foreach ($alumnos as $id) {
            $d = $this->datos($id);
            $foto = $d['foto'] ? '<img class="foto" src="'.$d['foto'].'">' : '<div class="foto sinfoto">Sin foto</div>';
            $tarjetas .= '<div class="carne"><div class="cab" style="background:'.$e($color).'">'
                .($logo ? '<img class="logo" src="'.$logo.'">' : '').'<span>'.$e($d['colegio']).'</span></div>'
                .'<table class="cuerpo"><tr><td class="izq">'.$foto.'</td><td><b>'.$e($d['alumno']).'</b><br>'
                .($d['documento'] ? 'DNI '.$e($d['documento']).'<br>' : '').$e($d['seccion'] ?? '').($d['anio'] ? ' · '.$e($d['anio']) : '')
                .'<br><span class="cod">'.$e($d['codigo']).'</span></td><td class="der"><img class="qr" src="'.$d['qr'].'"></td></tr></table></div>';
        }

        $html = '<html><head><meta charset="utf-8"><style>
            @page { margin: 12mm; } body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #1f3d5e; }
            .carne { display: inline-block; width: 85.6mm; height: 54mm; border: 0.3mm solid #c8d2dc; border-radius: 3mm; margin: 0 3mm 4mm 0; overflow: hidden; vertical-align: top; }
            .cab { color: #fff; font-weight: bold; font-size: 9px; padding: 2mm 3mm; height: 7mm; } .cab span { vertical-align: middle; }
            .logo { height: 6mm; vertical-align: middle; margin-right: 2mm; background: #fff; border-radius: 1mm; }
            .cuerpo { width: 100%; margin-top: 2mm; } .izq { width: 20mm; padding-left: 3mm; } .der { width: 26mm; text-align: right; padding-right: 3mm; }
            .foto { width: 18mm; height: 22mm; border-radius: 1mm; } .sinfoto { background: #eef4fa; color: #64748b; text-align: center; height: 13mm; padding-top: 9mm; }
            .qr { width: 24mm; height: 24mm; } .cod { font-family: DejaVu Sans Mono, monospace; color: #64748b; font-size: 7px; }
            </style></head><body>'.$tarjetas.'</body></html>';

        $pdf = new Dompdf(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false]);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }

    /** Foto del alumno como data URI, solo con consentimiento de imagen vigente. */
    private function foto(object $alumno): ?string
    {
        if (! $alumno->foto_archivo_id || ! $this->consentimientos->imagenAutorizada((int) $alumno->id)) {
            return null;
        }

        return $this->imagen((int) $alumno->foto_archivo_id);
    }

    private function imagen(int $archivoId): ?string
    {
        $a = DB::table('archivos')->find($archivoId, ['disco', 'ruta', 'mime']);
        if (! $a || ! str_starts_with($a->mime, 'image/')) {
            return null;
        }
        $contenido = Storage::disk($a->disco)->get($a->ruta);

        return $contenido === null ? null : 'data:'.$a->mime.';base64,'.base64_encode($contenido);
    }

    private static function qr(string $texto): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd)))->writeString($texto);
    }
}
