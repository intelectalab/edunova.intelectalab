<?php

namespace App\Modulos\ADM\Services;

use App\Models\User;
use App\Modulos\SEG\Services\Auditoria;
use App\Modulos\SEG\Services\Autorizacion;
use App\Modulos\SEG\Services\Consentimientos;
use Illuminate\Support\Facades\DB;

/**
 * EDUN-52 / ADM-H10 · Ficha completa del alumno, por pestañas: datos, historial por año, deuda,
 * conducta, documentos y salud. Cada pestaña llega solo si el usuario tiene permiso; los datos
 * de salud además abren el esquema salud (app.acceso_salud) y la consulta queda en auditoría.
 */
class FichaAlumno
{
    public const PERMISOS = [
        'deuda' => ['pag.cargos.ver', 'adm.matricula.ver'],
        'conducta' => ['cal.conducta.ver'],
        'documentos' => ['doc.expedientes.ver', 'adm.alumnos.editar'],
        'salud' => ['sal.salud.ver'],
    ];

    public function __construct(private RegistroAlumnos $registro, private Autorizacion $autorizacion, private Consentimientos $consentimientos, private Auditoria $auditoria) {}

    public function completa(int $id, User $usuario): array
    {
        $puede = fn (string $seccion) => $this->autorizacion->tieneAlguno($usuario, self::PERMISOS[$seccion]);
        $ficha = $this->registro->ficha($id);

        $ficha['historial'] = DB::table('matriculas as m')
            ->join('anios_escolares as y', 'y.id', '=', 'm.anio_escolar_id')->join('secciones as s', 's.id', '=', 'm.seccion_id')->join('grados as g', 'g.id', '=', 's.grado_id')
            ->where('m.alumno_id', $id)->orderByDesc('y.anio')
            ->get(['m.id', 'y.anio', DB::raw("g.nombre || ' ' || s.nombre as seccion"), 'm.estado', 'm.tipo', 'm.fecha_matricula'])
            ->map(fn ($m) => (array) $m + ['movimientos' => DB::table('movimientos_matricula as mv')->leftJoin('secciones as d', 'd.id', '=', 'mv.seccion_destino_id')
                ->where('mv.matricula_id', $m->id)->orderBy('mv.fecha')->get(['mv.tipo', 'mv.fecha', 'mv.motivo', 'mv.ie_destino', 'd.nombre as seccion_destino'])
                ->map(fn ($x) => (array) $x)->all()])->all();

        $ficha['consentimientos'] = $this->consentimientos->estado([$id])[$id];
        $ficha['tiene_carne'] = DB::table('alumnos')->where('id', $id)->whereNotNull('codigo_carne')->exists();
        $ficha['secciones'] = array_values(array_filter(array_keys(self::PERMISOS), $puede));

        if ($puede('deuda')) {
            $cargos = DB::table('cargos')->where('alumno_id', $id)->whereNotIn('estado', ['anulado'])->orderBy('vencimiento')
                ->get(['id', 'descripcion', 'emision', 'vencimiento', 'monto', 'descuento', 'mora', 'pagado', 'estado']);
            $hoy = now()->toDateString();
            $saldo = fn ($c) => round($c->monto - $c->descuento + $c->mora - $c->pagado, 2);
            $ficha['deuda'] = [
                'vencida' => round($cargos->filter(fn ($c) => in_array($c->estado, ['pendiente', 'parcial'], true) && $c->vencimiento < $hoy)->sum($saldo), 2),
                'por_vencer' => round($cargos->filter(fn ($c) => in_array($c->estado, ['pendiente', 'parcial'], true) && $c->vencimiento >= $hoy)->sum($saldo), 2),
                'cargos' => $cargos->map(fn ($c) => (array) $c + ['saldo' => $saldo($c), 'vencido' => in_array($c->estado, ['pendiente', 'parcial'], true) && $c->vencimiento < $hoy])->all(),
            ];
        }
        if ($puede('conducta')) {
            $ficha['conducta'] = DB::table('incidencias_conducta')->where('alumno_id', $id)->orderByDesc('fecha')
                ->get(['fecha', 'tipo', 'gravedad', 'detalle', 'notificado_padre_at'])->map(fn ($c) => (array) $c)->all();
        }
        if ($puede('documentos')) {
            $ficha['documentos'] = [
                'expediente' => DB::table('expediente_documentos')->where('titular_tipo', 'alumno')->where('titular_id', $id)->orderBy('categoria')
                    ->get(['id', 'categoria', 'nombre', 'archivo_id', 'vence', 'verificado_por'])->map(fn ($d) => (array) $d)->all(),
                'emitidos' => DB::table('documentos_emitidos as d')->leftJoin('plantillas_documento as p', 'p.id', '=', 'd.plantilla_documento_id')
                    ->where('d.alumno_id', $id)->orderByDesc('d.created_at')
                    ->get(['d.id', DB::raw("coalesce(p.nombre, 'Documento') as nombre"), 'd.codigo_verificacion', 'd.archivo_id', 'd.firmado_at', 'd.anulado_at'])
                    ->map(fn ($d) => (array) $d)->all(),
            ];
        }
        if ($puede('salud')) {
            $ficha['salud'] = DB::transaction(function () use ($id) {
                // Solo dentro de esta transacción se abre el esquema de salud.
                DB::select("select set_config('app.acceso_salud', 'on', true)");
                $f = DB::table('salud.fichas')->where('alumno_id', $id)->first(['grupo_sanguineo', 'seguro', 'alergias', 'condiciones', 'medicacion', 'contacto_emergencia', 'telefono_emergencia', 'updated_at']);
                DB::select("select set_config('app.acceso_salud', 'off', true)");

                return $f ? (array) $f : null;
            });
            $this->auditoria->registrar('salud_consultada', 'salud.fichas', $id, null, ['alumno_id' => $id]);
        }

        return $ficha;
    }
}
