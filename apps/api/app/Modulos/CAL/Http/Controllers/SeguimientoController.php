<?php

namespace App\Modulos\CAL\Http\Controllers;

use App\Modulos\CAL\Services\Asistencia;
use App\Modulos\CAL\Services\Libretas;
use App\Modulos\CAL\Services\Tutoria;
use App\Modulos\SEG\Http\Controllers\FamiliaController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Sprint 19 · CAL: asistencia, conducta, tutoría, alertas, libretas, actas y recuperación. */
class SeguimientoController
{
    public function __construct(private Asistencia $asistencia, private Tutoria $tutoria, private Libretas $libretas) {}

    // ── EDUN-94 / EDUN-95 · Asistencia

    public function secciones(Request $request): JsonResponse
    {
        // Sin año (la app): el abierto o, si no hay, el más reciente.
        $anio = (int) ($request->validate(['anio_escolar_id' => ['nullable', 'integer']])['anio_escolar_id']
            ?? DB::table('anios_escolares')->orderByRaw("estado = 'abierto' desc")->orderByDesc('anio')->value('id'));

        return response()->json(['data' => $this->asistencia->secciones($request->user(), $anio), 'estados' => Asistencia::ESTADOS]);
    }

    public function lista(Request $request, int $seccion): JsonResponse
    {
        $fecha = $request->validate(['fecha' => ['nullable', 'date_format:Y-m-d']])['fecha'] ?? now()->toDateString();

        return response()->json($this->asistencia->lista($seccion, $fecha));
    }

    public function guardar(Request $request, int $seccion): JsonResponse
    {
        $d = $request->validate([
            'fecha' => ['required', 'date_format:Y-m-d'], 'origen' => ['nullable', Rule::in(['web', 'movil', 'offline'])],
            'registros' => ['required', 'array', 'max:80'], 'registros.*.alumno_id' => ['required', 'integer'], 'registros.*.estado' => ['required', Rule::in(array_keys(Asistencia::ESTADOS))],
            'registros.*.registrado_at' => ['nullable', 'date'], 'registros.*.hora' => ['nullable', 'date_format:H:i:s,H:i'],
        ]);

        return response()->json($this->asistencia->guardarLote($seccion, $d['fecha'], $d['registros'], $d['origen'] ?? 'web', (int) $request->user()->id));
    }

    public function qr(Request $request): JsonResponse
    {
        $d = $request->validate(['codigo' => ['required', 'string', 'max:40']], ['codigo.required' => 'Escanea el carné.']);

        return response()->json($this->asistencia->porQr($d['codigo'], (int) $request->user()->id));
    }

    // ── EDUN-101 / EDUN-102 / EDUN-103 · Conducta, tutoría y alertas

    public function historial(Request $request, int $alumno): JsonResponse
    {
        return response()->json($this->tutoria->historial($alumno, $request->user()));
    }

    public function conducta(Request $request, int $alumno): JsonResponse
    {
        $d = $request->validate([
            'tipo' => ['required', Rule::in(['incidencia', 'merito'])], 'gravedad' => ['nullable', Rule::in(array_keys(Tutoria::GRAVEDAD))],
            'detalle' => ['required', 'string', 'min:5', 'max:2000'], 'fecha' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'avisar_padre' => ['boolean'],
        ], ['detalle.required' => 'Describe lo ocurrido.']);
        $id = $this->tutoria->registrarConducta($alumno, $d['tipo'], $d['gravedad'] ?? null, $d['detalle'], $d['fecha'] ?? now()->toDateString(), (bool) ($d['avisar_padre'] ?? false), (int) $request->user()->id);

        return response()->json(['id' => $id] + $this->tutoria->historial($alumno, $request->user()), 201);
    }

    public function entrevista(Request $request, int $alumno): JsonResponse
    {
        $d = $request->validate([
            'fecha' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'participantes' => ['required', 'string', 'max:200'],
            'acuerdos' => ['required', 'string', 'min:5', 'max:5000'], 'confidencial' => ['boolean'],
        ], ['participantes.required' => 'Indica quiénes participaron.', 'acuerdos.required' => 'Registra los acuerdos.']);
        $id = $this->tutoria->registrarEntrevista($alumno, $d['fecha'] ?? now()->toDateString(), $d['participantes'], $d['acuerdos'], (bool) ($d['confidencial'] ?? false), (int) $request->user()->id);

        return response()->json(['id' => $id] + $this->tutoria->historial($alumno, $request->user()), 201);
    }

    public function alertas(Request $request): JsonResponse
    {
        $estado = $request->validate(['estado' => ['nullable', Rule::in(['activas', 'abierta', 'en_seguimiento', 'cerrada'])]])['estado'] ?? 'activas';

        return response()->json(['data' => $this->tutoria->alertas($request->user(), $estado), 'motivos' => Tutoria::MOTIVOS]);
    }

    public function evaluarAlertas(): JsonResponse
    {
        return response()->json(['creadas' => $this->tutoria->evaluarAlertas()]);
    }

    public function seguirAlerta(Request $request, int $alerta): JsonResponse
    {
        $d = $request->validate(['estado' => ['required', Rule::in(['en_seguimiento', 'cerrada'])], 'accion' => ['required', 'string', 'min:5', 'max:1000']],
            ['accion.required' => 'Registra la acción realizada.']);
        $this->tutoria->seguir($alerta, $d['estado'], $d['accion'], (int) $request->user()->id);

        return response()->json(['message' => 'Seguimiento registrado.']);
    }

    // ── EDUN-99 / EDUN-100 · Libretas

    public function libretas(Request $request): JsonResponse
    {
        $d = $request->validate(['seccion_id' => ['required', 'integer'], 'periodo_id' => ['required', 'integer']]);

        return response()->json(['data' => $this->libretas->estado((int) $d['seccion_id'], (int) $d['periodo_id'])]);
    }

    public function generarLibretas(Request $request): JsonResponse
    {
        $d = $request->validate(['seccion_id' => ['required', 'integer'], 'periodo_id' => ['required', 'integer']]);

        return response()->json(['generadas' => $this->libretas->generar((int) $d['seccion_id'], (int) $d['periodo_id']), 'data' => $this->libretas->estado((int) $d['seccion_id'], (int) $d['periodo_id'])]);
    }

    public function publicarLibretas(Request $request): JsonResponse
    {
        $d = $request->validate(['seccion_id' => ['required', 'integer'], 'periodo_id' => ['required', 'integer']]);

        return response()->json(['publicadas' => $this->libretas->publicar((int) $d['seccion_id'], (int) $d['periodo_id']), 'data' => $this->libretas->estado((int) $d['seccion_id'], (int) $d['periodo_id'])]);
    }

    public function notasFamilia(Request $request): JsonResponse
    {
        return response()->json(['hijos' => $this->libretas->paraFamilia(FamiliaController::alumnosDe((int) $request->user()->id))]);
    }

    public function libretaFamilia(Request $request, int $libreta): JsonResponse
    {
        return response()->json(['url' => $this->libretas->descargaFamilia($libreta, FamiliaController::alumnosDe((int) $request->user()->id))]);
    }

    // ── EDUN-104 / EDUN-105 · Acta, méritos y recuperación

    public function acta(int $seccion): JsonResponse
    {
        return response()->json($this->libretas->acta($seccion));
    }

    public function actaArchivo(int $seccion, string $formato): Response
    {
        return $formato === 'xlsx'
            ? response($this->libretas->actaExcel($seccion), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => "attachment; filename=\"acta-{$seccion}.xlsx\""])
            : response($this->libretas->actaPdf($seccion), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename=\"acta-{$seccion}.pdf\""]);
    }

    public function recuperacion(Request $request): JsonResponse
    {
        $d = $request->validate([
            'alumno_id' => ['required', 'integer'], 'anio_escolar_id' => ['required', 'integer'], 'competencia_id' => ['required', 'integer'],
            'valor' => ['nullable', 'string', 'max:3'], 'fecha' => ['nullable', 'date_format:Y-m-d'], 'seccion_id' => ['required', 'integer'],
        ]);
        $this->libretas->registrarRecuperacion((int) $d['alumno_id'], (int) $d['anio_escolar_id'], (int) $d['competencia_id'], $d['valor'] ?? null, $d['fecha'] ?? null, (int) $request->user()->id);

        return response()->json($this->libretas->acta((int) $d['seccion_id']));
    }
}
