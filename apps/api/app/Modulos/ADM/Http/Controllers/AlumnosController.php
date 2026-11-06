<?php

namespace App\Modulos\ADM\Http\Controllers;

use App\Modulos\ADM\Models\Alumno;
use App\Modulos\SEG\Services\AlcanceAcademico;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Lectura mínima de alumnos para aplicar los permisos de EDUN-39. Los datos completos y la
 * gestión llegan con las historias de ADM. Se busca el alumno dentro del colegio activo
 * (el RLS ya lo limita): si es de otro colegio, simplemente no existe.
 */
class AlumnosController
{
    public function index(Request $request, AlcanceAcademico $alcance): JsonResponse
    {
        $usuario = $request->user();
        $consulta = Alumno::query()->orderBy('apellido_paterno')->orderBy('nombres');
        if (! $alcance->veTodoElColegio($usuario)) {
            $consulta->whereIn('id', $alcance->alumnosVisibles($usuario));
        }

        return response()->json(['data' => $consulta->limit(200)->get(['id', 'apellido_paterno', 'apellido_materno', 'nombres'])]);
    }

    public function show(int $alumno): JsonResponse
    {
        $registro = Alumno::query()->findOrFail($alumno);
        Gate::authorize('ver', $registro);

        return response()->json(['data' => $registro->only(['id', 'apellido_paterno', 'apellido_materno', 'nombres', 'estado'])]);
    }
}
