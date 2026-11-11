<?php

namespace App\Modulos\PAG\Http\Controllers;

use App\Modulos\PAG\Services\Beneficios;
use App\Modulos\PAG\Services\ConfiguracionCobros;
use App\Modulos\PAG\Services\ProveedoresFacturacion;
use App\Modulos\SEG\Services\Autorizacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Sprint 16 · PAG-02/03 y facturación: conceptos, cronogramas, descuentos y becas, proveedores. */
class ConfiguracionPagosController
{
    public function __construct(private ConfiguracionCobros $cobros, private Beneficios $beneficios, private ProveedoresFacturacion $proveedores) {}

    public function conceptos(): JsonResponse
    {
        return response()->json([
            'data' => $this->cobros->conceptos(), 'tipos' => ConfiguracionCobros::TIPOS,
            'obligatorios' => ConfiguracionCobros::OBLIGATORIOS, 'comprobantes' => ConfiguracionCobros::COMPROBANTES,
        ]);
    }

    public function guardarConcepto(Request $request, ?int $concepto = null): JsonResponse
    {
        $d = $request->validate([
            'codigo' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/'],
            'nombre' => ['required', 'string', 'max:120'],
            'tipo' => ['required', Rule::in(array_keys(ConfiguracionCobros::TIPOS))],
            'obligatorio' => ['boolean'],
            'monto' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'afecto_igv' => ['boolean'],
            'tipo_comprobante' => ['required', Rule::in(array_keys(ConfiguracionCobros::COMPROBANTES))],
            'activo' => ['boolean'],
        ], [
            'codigo.required' => 'Escribe un código corto (p. ej. PEN).', 'codigo.regex' => 'El código lleva solo letras, números, guion o guion bajo.',
            'nombre.required' => 'Escribe el nombre del concepto.', 'monto.required' => 'Indica el monto.', 'tipo.in' => 'Elige el tipo de concepto.',
        ]);
        $id = $this->cobros->guardarConcepto($d, $concepto);

        return response()->json(['id' => $id, 'data' => $this->cobros->conceptos()], $concepto ? 200 : 201);
    }

    public function cronogramas(Request $request): JsonResponse
    {
        $anio = (int) $request->validate(['anio_escolar_id' => ['required', 'integer']])['anio_escolar_id'];

        return response()->json(['data' => $this->cobros->cronogramas($anio)]);
    }

    public function guardarCronograma(Request $request, ?int $cronograma = null): JsonResponse
    {
        $d = $request->validate([
            'anio_escolar_id' => ['required', 'integer', 'exists:anios_escolares,id'],
            'nivel' => ['nullable', Rule::in(['inicial', 'primaria', 'secundaria'])],
            'nombre' => ['required', 'string', 'max:80'],
            'cuotas' => ['required', 'array', 'min:1', 'max:24'],
            'cuotas.*.id' => ['nullable', 'integer'],
            'cuotas.*.concepto_cobro_id' => ['required', 'integer'],
            'cuotas.*.mes' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'cuotas.*.emision' => ['required', 'date_format:Y-m-d'],
            'cuotas.*.vencimiento' => ['required', 'date_format:Y-m-d'],
            'cuotas.*.monto' => ['required', 'numeric', 'min:0', 'max:99999999'],
        ], [
            'nombre.required' => 'Ponle un nombre al cronograma.', 'cuotas.required' => 'Agrega al menos una cuota.',
            'cuotas.*.mes.regex' => 'El mes va como AAAA-MM.', 'cuotas.*.monto.required' => 'Cada cuota necesita su monto.',
        ]);
        $id = $this->cobros->guardarCronograma((int) $d['anio_escolar_id'], $d['nivel'] ?? null, $d['nombre'], $d['cuotas'], $cronograma);

        return response()->json(['id' => $id, 'data' => $this->cobros->cronogramas((int) $d['anio_escolar_id'])], $cronograma ? 200 : 201);
    }

    public function eliminarCronograma(int $cronograma): JsonResponse
    {
        $this->cobros->eliminarCronograma($cronograma);

        return response()->json(['message' => 'Cronograma eliminado.']);
    }

    public function copiarCronogramas(Request $request): JsonResponse
    {
        $d = $request->validate(['desde_anio_id' => ['required', 'integer', 'different:hacia_anio_id'], 'hacia_anio_id' => ['required', 'integer']],
            ['desde_anio_id.different' => 'Elige un año de origen distinto al de destino.']);

        return response()->json(['copiados' => $this->cobros->copiar((int) $d['desde_anio_id'], (int) $d['hacia_anio_id']), 'data' => $this->cobros->cronogramas((int) $d['hacia_anio_id'])]);
    }

    public function beneficios(Request $request, Autorizacion $autorizacion): JsonResponse
    {
        $d = $request->validate(['anio_escolar_id' => ['required', 'integer'], 'estado' => ['nullable', Rule::in(['pendiente', 'aprobado', 'rechazado'])]]);

        return response()->json([
            'tipos' => $this->beneficios->tipos(), 'asignaciones' => $this->beneficios->asignaciones((int) $d['anio_escolar_id'], $d['estado'] ?? null),
            'motivos' => Beneficios::MOTIVOS, 'aplica' => Beneficios::APLICA,
            'puede_aprobar' => $autorizacion->tiene($request->user(), 'pag.beneficios.aprobar'),
        ]);
    }

    public function guardarTipoBeneficio(Request $request, ?int $beneficio = null): JsonResponse
    {
        $d = $request->validate([
            'nombre' => ['required', 'string', 'max:80'],
            'tipo' => ['required', Rule::in(['porcentaje', 'monto'])],
            'valor' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'motivo' => ['required', Rule::in(array_keys(Beneficios::MOTIVOS))],
            'aplica_a' => ['required', Rule::in(array_keys(Beneficios::APLICA))],
            'activo' => ['boolean'],
        ], ['nombre.required' => 'Escribe el nombre del beneficio.', 'valor.gt' => 'El valor debe ser mayor que cero.']);

        return response()->json(['id' => $this->beneficios->guardarTipo($d, $beneficio), 'tipos' => $this->beneficios->tipos()], $beneficio ? 200 : 201);
    }

    public function asignarBeneficio(Request $request, Autorizacion $autorizacion): JsonResponse
    {
        $d = $request->validate([
            'alumno_id' => ['required', 'integer'], 'beneficio_id' => ['required', 'integer'], 'anio_escolar_id' => ['required', 'integer'],
            'sustento_archivo_id' => ['required', 'integer'], 'observacion' => ['nullable', 'string', 'max:300'],
        ], ['sustento_archivo_id.required' => 'Adjunta el sustento del beneficio.', 'alumno_id.required' => 'Elige al alumno.', 'beneficio_id.required' => 'Elige el beneficio.']);
        $r = $this->beneficios->asignar((int) $d['alumno_id'], (int) $d['beneficio_id'], (int) $d['anio_escolar_id'], (int) $d['sustento_archivo_id'],
            $d['observacion'] ?? null, (int) $request->user()->id, $autorizacion->tiene($request->user(), 'pag.beneficios.aprobar'));

        return response()->json($r, 201);
    }

    public function resolverBeneficio(Request $request, int $asignacion): JsonResponse
    {
        $d = $request->validate(['aprobar' => ['required', 'boolean'], 'observacion' => ['nullable', 'string', 'max:300']]);

        return response()->json($this->beneficios->resolver($asignacion, (bool) $d['aprobar'], $d['observacion'] ?? null, (int) $request->user()->id));
    }

    public function quitarBeneficio(int $asignacion): JsonResponse
    {
        return response()->json(['recalculados' => $this->beneficios->quitar($asignacion)]);
    }

    public function proveedores(): JsonResponse
    {
        return response()->json(['data' => $this->proveedores->listar(), 'catalogo' => ProveedoresFacturacion::catalogo()]);
    }

    public function guardarProveedor(Request $request, ?int $proveedor = null): JsonResponse
    {
        $d = $request->validate([
            'proveedor' => ['required', 'string', 'max:30'],
            'ambiente' => ['required', Rule::in(['beta', 'produccion'])],
            'credenciales' => ['nullable', 'string', 'max:4000'],
            'serie_boleta' => ['required', 'regex:/^[Bb][A-Za-z0-9]{3}$/'],
            'serie_factura' => ['required', 'regex:/^[Ff][A-Za-z0-9]{3}$/'],
            'serie_nota_credito' => ['nullable', 'regex:/^[BbFf][A-Za-z0-9]{3}$/'],
            'predeterminado' => ['boolean'],
            'activo' => ['boolean'],
        ], [
            'serie_boleta.regex' => 'La serie de boletas son 4 caracteres y empieza con B (p. ej. B001).',
            'serie_factura.regex' => 'La serie de facturas son 4 caracteres y empieza con F (p. ej. F001).',
            'serie_nota_credito.regex' => 'La serie de notas de crédito empieza con B o F (p. ej. BC01).',
        ]);
        $id = $this->proveedores->guardar($d, $proveedor);

        return response()->json(['id' => $id, 'data' => $this->proveedores->listar()], $proveedor ? 200 : 201);
    }
}
