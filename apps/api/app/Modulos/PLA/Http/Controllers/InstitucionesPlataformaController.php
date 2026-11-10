<?php

namespace App\Modulos\PLA\Http\Controllers;

use App\Modulos\PLA\Services\ConsolaInstituciones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** EDUN-24 / EDUN-25 / EDUN-26 · PLA-01 a PLA-03: instituciones, alta, módulos y aula. */
class InstitucionesPlataformaController
{
    public function __construct(private ConsolaInstituciones $consola) {}

    public function catalogos(): JsonResponse
    {
        return response()->json($this->consola->catalogos());
    }

    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::in(['implementacion', 'activo', 'suspendido', 'baja'])],
        ]);

        return response()->json(['data' => $this->consola->listar($filtros)]);
    }

    public function show(int $institucion): JsonResponse
    {
        return response()->json($this->consola->detalle($institucion));
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:200'],
            'razon_social' => ['nullable', 'string', 'max:200'],
            'ruc' => ['nullable', 'digits:11'],
            'codigo_local' => ['nullable', 'digits:6'],
            'ugel' => ['required', 'string', 'max:120'],
            'dre' => ['nullable', 'string', 'max:120'],
            'ubigeo' => ['nullable', 'digits:6'],
            'direccion' => ['nullable', 'string', 'max:250'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'correo' => ['nullable', 'email:rfc', 'max:150'],
            'slug' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/'],
            'servicios' => ['required', 'array', 'min:1', 'max:3'],
            'servicios.*.nivel' => ['required', Rule::in(['inicial', 'primaria', 'secundaria'])],
            'servicios.*.codigo_modular' => ['required', 'digits:7'],
            'servicios.*.anexo' => ['nullable', 'digits:1'],
            'plan' => ['required', 'string', 'max:30'],
            'aula_opcion' => ['required', Rule::in(['medida', 'moodle', 'ambas', 'ninguna'])],
            'modulos_extra' => ['nullable', 'array'],
            'modulos_extra.*' => ['string', 'max:10'],
            'fecha_inicio' => ['nullable', 'date_format:Y-m-d'],
            'meses_gratis' => ['nullable', 'integer', 'between:0,12'],
            'precio_congelado_hasta' => ['nullable', 'date_format:Y-m-d'],
            'promotor' => ['required', 'array'],
            'promotor.nombres' => ['required', 'string', 'max:120'],
            'promotor.apellidos' => ['required', 'string', 'max:160'],
            'promotor.numero_documento' => ['nullable', 'digits:8'],
            'promotor.email' => ['required', 'email:rfc', 'max:150'],
            'promotor.celular' => ['nullable', 'regex:/^\+?[0-9 ]{9,15}$/'],
        ], [
            'nombre.required' => 'Escribe el nombre del colegio.',
            'ugel.required' => 'Escribe la UGEL.',
            'ruc.digits' => 'El RUC debe tener 11 dígitos.',
            'slug.required' => 'Escribe el subdominio.',
            'slug.regex' => 'El subdominio solo lleva minúsculas, números y guiones (sin empezar ni terminar en guion).',
            'slug.min' => 'El subdominio debe tener al menos 3 caracteres.',
            'servicios.required' => 'Agrega al menos un nivel con su código modular.',
            'servicios.*.codigo_modular.required' => 'Escribe el código modular de cada nivel.',
            'servicios.*.codigo_modular.digits' => 'El código modular tiene 7 dígitos.',
            'plan.required' => 'Elige el plan.',
            'aula_opcion.required' => 'Elige la opción de aula virtual.',
            'promotor.nombres.required' => 'Escribe los nombres del promotor.',
            'promotor.apellidos.required' => 'Escribe los apellidos del promotor.',
            'promotor.numero_documento.digits' => 'El DNI del promotor debe tener 8 dígitos.',
            'promotor.email.required' => 'Escribe el correo del promotor: ahí le llega la invitación.',
            'promotor.email.email' => 'El correo del promotor no es válido.',
        ]);

        return response()->json($this->consola->crear($datos, $request->user()), 201);
    }

    public function modulo(Request $request, int $institucion, string $modulo): JsonResponse
    {
        $datos = $request->validate(['activo' => ['required', 'boolean']]);

        return response()->json($this->consola->cambiarModulo($institucion, $modulo, (bool) $datos['activo']));
    }

    public function aula(Request $request, int $institucion): JsonResponse
    {
        $datos = $request->validate(['aula_opcion' => ['required', Rule::in(['medida', 'moodle', 'ambas', 'ninguna'])]]);

        return response()->json($this->consola->cambiarAula($institucion, $datos['aula_opcion']));
    }

    public function reinvitar(Request $request, int $institucion, int $usuario): JsonResponse
    {
        return response()->json($this->consola->reinvitarPromotor($institucion, $usuario, $request->user()));
    }
}
