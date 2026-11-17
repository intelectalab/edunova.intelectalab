<?php

namespace App\Modulos\BIB\Http\Controllers;

use App\Modulos\BIB\Services\Biblioteca;
use App\Modulos\SEG\Http\Controllers\FamiliaController;
use App\Modulos\SEG\Services\Autorizacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** S33 · Biblioteca (EDUN-151 a 160). */
class BibliotecaController
{
    public function __construct(private Biblioteca $bib) {}

    public function catalogo(Request $request): JsonResponse
    {
        $f = $request->validate(['buscar' => ['nullable', 'string', 'max:100'], 'tipo' => ['nullable', Rule::in(Biblioteca::TIPOS)], 'nivel' => ['nullable', Rule::in(['inicial', 'primaria', 'secundaria'])],
            'area_curricular_id' => ['nullable', 'integer'], 'categoria' => ['nullable', 'string', 'max:60'], 'grado' => ['nullable', 'string', 'max:10']]);

        return response()->json(['data' => $this->bib->catalogo($f),
            'categorias' => DB::table('bib_libros')->whereNotNull('categoria')->distinct()->orderBy('categoria')->pluck('categoria'),
            'areas' => DB::table('areas_curriculares')->where('vigente', true)->orderBy('nivel')->orderBy('orden')->get(['id', 'nombre', 'nivel'])]);
    }

    public function ficha(Request $request, int $libro, Autorizacion $permisos): JsonResponse
    {
        return response()->json($this->bib->ficha($libro, $permisos->tiene($request->user(), 'bib.biblioteca.gestionar')));
    }

    private function datosLibro(Request $request): array
    {
        return $request->validate([
            'titulo' => ['required', 'string', 'min:2', 'max:250'], 'autor' => ['nullable', 'string', 'max:200'], 'isbn' => ['nullable', 'string', 'max:20'], 'editorial' => ['nullable', 'string', 'max:120'],
            'anio_publicacion' => ['nullable', 'integer', 'between:1500,2100'], 'categoria' => ['nullable', 'string', 'max:60'], 'ubicacion' => ['nullable', 'string', 'max:40'],
            'tipo' => ['required', Rule::in(Biblioteca::TIPOS)], 'area_curricular_id' => ['nullable', 'integer'], 'nivel' => ['nullable', Rule::in(['inicial', 'primaria', 'secundaria', 'todos'])],
            'grados' => ['nullable', 'string', 'max:40'], 'descripcion' => ['nullable', 'string', 'max:3000'], 'licencias' => ['nullable', 'integer', 'between:1,1000'],
            'ejemplares' => ['nullable', 'integer', 'between:0,200'], 'estante' => ['nullable', 'string', 'max:40'], 'origen' => ['nullable', 'string', 'max:20'],
            'archivo' => ['nullable', 'file', 'mimes:pdf,epub', 'max:51200'],
        ]);
    }

    public function guardarLibro(Request $request, ?int $libro = null): JsonResponse
    {
        return response()->json($this->bib->guardarLibro($libro, $this->datosLibro($request), $request->file('archivo'), (int) $request->user()->id), $libro ? 200 : 201);
    }

    public function ejemplares(Request $request, int $libro): JsonResponse
    {
        $d = $request->validate(['cantidad' => ['required', 'integer', 'between:1,200'], 'estante' => ['nullable', 'string', 'max:40'], 'origen' => ['nullable', 'string', 'max:20']]);

        return response()->json(['codigos' => $this->bib->agregarEjemplares($libro, (int) $d['cantidad'], $d['estante'] ?? null, $d['origen'] ?? null)], 201);
    }

    public function isbn(string $isbn): JsonResponse
    {
        $r = $this->bib->porIsbn($isbn);

        return $r ? response()->json($r) : response()->json(['message' => 'No encontramos ese ISBN. Escribe los datos a mano.'], 404);
    }

    public function importar(Request $request): JsonResponse
    {
        $request->validate(['archivo' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120']]);

        return response()->json($this->bib->importar($request->file('archivo'), (int) $request->user()->id));
    }

    public function reglas(Request $request): JsonResponse
    {
        if ($request->isMethod('put')) {
            $d = $request->validate(['dias_alumno' => ['integer', 'between:1,60'], 'dias_personal' => ['integer', 'between:1,120'], 'max_alumno' => ['integer', 'between:1,10'],
                'renovaciones_max' => ['integer', 'between:0,5'], 'suspension_por_atraso' => ['boolean'], 'reservas_app' => ['boolean']]);

            return response()->json($this->bib->guardarReglas($d));
        }

        return response()->json($this->bib->reglas());
    }

    public function prestar(Request $request): JsonResponse
    {
        $d = $request->validate(['ejemplar' => ['required', 'string', 'max:30'], 'lector' => ['nullable', 'string', 'max:30', 'required_without:personal_id'], 'personal_id' => ['nullable', 'integer']]);

        return response()->json($this->bib->prestar($d['ejemplar'], $this->bib->lector($d['lector'] ?? null, isset($d['personal_id']) ? (int) $d['personal_id'] : null), (int) $request->user()->id), 201);
    }

    public function devolver(Request $request): JsonResponse
    {
        return response()->json($this->bib->devolver($request->validate(['ejemplar' => ['required', 'string', 'max:30']])['ejemplar']));
    }

    public function renovar(int $prestamo): JsonResponse
    {
        return response()->json($this->bib->renovar($prestamo));
    }

    public function prestamos(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->bib->prestamosActivos($request->boolean('vencidos'))]);
    }

    public function plan(Request $request): JsonResponse
    {
        $d = $request->validate(['grado_id' => ['nullable', 'integer'], 'periodo_id' => ['nullable', 'integer']]);

        return response()->json(['data' => $this->bib->planLector($d['grado_id'] ?? null, $d['periodo_id'] ?? null),
            'grados' => DB::table('grados')->orderBy('nivel')->orderBy('numero')->get(['id', 'nombre', 'nivel']),
            'periodos' => DB::table('periodos as p')->join('anios_escolares as y', 'y.id', '=', 'p.anio_escolar_id')->where('y.estado', 'abierto')->orderBy('p.numero')->get(['p.id', 'p.nombre'])]);
    }

    public function agregarPlan(Request $request): JsonResponse
    {
        $d = $request->validate(['grado_id' => ['required', 'integer'], 'periodo_id' => ['required', 'integer'], 'bib_libro_id' => ['required', 'integer']]);

        return response()->json($this->bib->agregarPlan((int) $d['grado_id'], (int) $d['periodo_id'], (int) $d['bib_libro_id'], DB::table('personal')->where('user_id', $request->user()->id)->value('id')), 201);
    }

    public function avance(int $plan): JsonResponse
    {
        return response()->json(['data' => $this->bib->avance($plan)]);
    }

    public function marcarAvance(Request $request, int $plan, int $alumno): JsonResponse
    {
        $this->bib->marcarAvance($plan, $alumno, $request->validate(['estado' => ['required', Rule::in(['sin_empezar', 'leyendo', 'terminado'])]])['estado']);

        return response()->json(['ok' => true]);
    }

    public function recordarPlan(int $plan): JsonResponse
    {
        return response()->json(['enviados' => $this->bib->recordarPlan($plan)]);
    }

    public function inventario(Request $request): JsonResponse
    {
        $d = $request->validate(['estante' => ['required', 'string', 'max:40'], 'codigos' => ['present', 'array', 'max:2000'], 'codigos.*' => ['string', 'max:30']]);

        return response()->json($this->bib->inventario($d['estante'], $d['codigos']));
    }

    public function actualizarEjemplar(Request $request, int $ejemplar): JsonResponse
    {
        $d = $request->validate(['conservacion' => ['nullable', Rule::in(['bueno', 'regular', 'malo'])], 'baja' => ['boolean'], 'motivo' => ['nullable', 'string', 'max:200']]);
        $this->bib->actualizarEjemplar($ejemplar, $d['conservacion'] ?? null, (bool) ($d['baja'] ?? false), $d['motivo'] ?? null);

        return response()->json(['ok' => true]);
    }

    public function etiquetas(Request $request): Response
    {
        $d = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:240'], 'ids.*' => ['integer']]);

        return response($this->bib->etiquetas($d['ids']), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="etiquetas-biblioteca.pdf"']);
    }

    public function estadisticas(): JsonResponse
    {
        return response()->json($this->bib->estadisticas());
    }

    // ─── Familia y alumno

    public function familia(Request $request): JsonResponse
    {
        return response()->json($this->bib->deFamilia(FamiliaController::alumnosDe((int) $request->user()->id), (int) $request->user()->id));
    }

    public function reservar(Request $request): JsonResponse
    {
        $d = $request->validate(['bib_libro_id' => ['required', 'integer'], 'alumno_id' => ['nullable', 'integer']]);
        $mios = FamiliaController::alumnosDe((int) $request->user()->id);
        // Sin alumno_id: el alumno que entra (o el primer hijo de la familia).
        $alumno = isset($d['alumno_id']) ? (int) $d['alumno_id'] : ($mios[0] ?? 0);
        abort_unless(in_array($alumno, $mios, true), 403, 'Solo reservas para ti o tus hijos.');

        return response()->json($this->bib->reservar((int) $d['bib_libro_id'], $alumno), 201);
    }

    public function cancelarReserva(Request $request, int $reserva): JsonResponse
    {
        $this->bib->cancelarReserva($reserva, FamiliaController::alumnosDe((int) $request->user()->id));

        return response()->json(['ok' => true]);
    }

    public function leer(Request $request, int $libro): JsonResponse
    {
        return response()->json($this->bib->leerDigital($libro, $request->user()));
    }

    public function devolverDigital(Request $request, int $libro): JsonResponse
    {
        $this->bib->devolverDigital($libro, $request->user());

        return response()->json(['ok' => true]);
    }
}
