<?php

namespace App\Modulos\COM\Http\Controllers;

use App\Modulos\COM\Services\Comunicados;
use App\Modulos\COM\Services\LibroReclamaciones;
use App\Modulos\COM\Services\Mensajeria;
use App\Modulos\COM\Services\Notificador;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Http\Controllers\FamiliaController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Sprint 21 · COM: comunicados, lectura, mensajería, agenda, citas, libro de reclamaciones y bitácora. */
class ComunicacionController
{
    public function __construct(private Comunicados $comunicados, private Mensajeria $mensajeria, private LibroReclamaciones $libro, private Notificador $notificador) {}

    // ── EDUN-60 / EDUN-61 · Comunicados

    public function comunicados(): JsonResponse
    {
        return response()->json(['data' => $this->comunicados->listar(), 'canales' => Notificador::CANALES]);
    }

    public function crearComunicado(Request $request): JsonResponse
    {
        $d = $request->validate([
            'titulo' => ['required', 'string', 'max:200'], 'contenido' => ['required', 'string', 'max:10000'],
            'alcance' => ['required', 'array'], 'alcance.tipo' => ['required', Rule::in(['colegio', 'nivel', 'grado', 'seccion'])],
            'alcance.ids' => ['nullable', 'array'], 'alcance.ids.*' => ['integer'], 'alcance.niveles' => ['nullable', 'array'], 'alcance.niveles.*' => [Rule::in(['inicial', 'primaria', 'secundaria'])],
            'alcance.con_deuda' => ['boolean'], 'canales' => ['required', 'array', 'min:1'], 'canales.*' => [Rule::in(array_keys(Notificador::CANALES))],
            'requiere_confirmacion' => ['boolean'], 'programado_para' => ['nullable', 'date'],
        ], ['titulo.required' => 'Escribe el título.', 'contenido.required' => 'Escribe el comunicado.', 'canales.required' => 'Elige al menos un canal.']);
        $id = $this->comunicados->guardar($d, (int) $request->user()->id);

        return response()->json(['id' => $id, 'data' => $this->comunicados->listar()], 201);
    }

    public function vistaPrevia(Request $request): JsonResponse
    {
        $alcance = $request->validate(['alcance' => ['required', 'array']])['alcance'];
        $d = $this->comunicados->destinatarios($alcance);

        return response()->json(['familias' => $d->pluck('user_id')->unique()->count(), 'alumnos' => $d->pluck('alumno_id')->unique()->count()]);
    }

    public function anularComunicado(Request $request, int $comunicado): JsonResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:300']], ['motivo.required' => 'Indica el motivo de la anulación.']);
        $this->comunicados->anular($comunicado, $d['motivo']);

        return response()->json(['data' => $this->comunicados->listar()]);
    }

    public function lectura(int $comunicado): JsonResponse
    {
        return response()->json($this->comunicados->lectura($comunicado));
    }

    public function recordarComunicado(int $comunicado): JsonResponse
    {
        return response()->json(['recordados' => $this->comunicados->recordar($comunicado)]);
    }

    // ── EDUN-62 · La familia

    public function comunicadosFamilia(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->comunicados->deFamilia((int) $request->user()->id)]);
    }

    public function marcarComunicado(Request $request, int $destinatario): JsonResponse
    {
        $d = $request->validate(['confirmar' => ['boolean']]);
        $this->comunicados->marcar($destinatario, (int) $request->user()->id, (bool) ($d['confirmar'] ?? false));

        return response()->json(['data' => $this->comunicados->deFamilia((int) $request->user()->id)]);
    }

    // ── EDUN-63 · Mensajería

    public function conversaciones(Request $request): JsonResponse
    {
        $hijos = FamiliaController::alumnosDe((int) $request->user()->id);

        return response()->json(['data' => $this->mensajeria->conversaciones($request->user()), 'horario' => Mensajeria::horario(),
            'contactos' => collect($hijos)->mapWithKeys(fn ($h) => [$h => $this->mensajeria->contactosDe($h)])->all()]);
    }

    public function iniciarConversacion(Request $request): JsonResponse
    {
        $d = $request->validate(['alumno_id' => ['required', 'integer'], 'rol' => ['required', Rule::in(['tutor', 'direccion'])], 'asunto' => ['required', 'string', 'max:200'],
            'mensaje' => ['required', 'string', 'max:5000'], 'archivo_id' => ['nullable', 'integer']], ['asunto.required' => 'Escribe el asunto.', 'mensaje.required' => 'Escribe el mensaje.']);
        $id = $this->mensajeria->iniciar($request->user(), (int) $d['alumno_id'], $d['rol'], $d['asunto'], $d['mensaje'], $d['archivo_id'] ?? null);

        return response()->json($this->mensajeria->ver($id, $request->user()), 201);
    }

    public function conversacion(Request $request, int $conversacion): JsonResponse
    {
        return response()->json($this->mensajeria->ver($conversacion, $request->user()));
    }

    public function enviarMensaje(Request $request, int $conversacion): JsonResponse
    {
        $d = $request->validate(['contenido' => ['required', 'string', 'max:5000'], 'archivo_id' => ['nullable', 'integer']], ['contenido.required' => 'Escribe el mensaje.']);
        $r = $this->mensajeria->enviar($conversacion, $request->user(), $d['contenido'], $d['archivo_id'] ?? null);

        return response()->json($r + $this->mensajeria->ver($conversacion, $request->user()), 201);
    }

    // ── EDUN-64 · Agenda

    public function eventos(Request $request): JsonResponse
    {
        $esFamilia = $request->boolean('familia');
        $secciones = $esFamilia ? DB::table('matriculas')->whereIn('alumno_id', FamiliaController::alumnosDe((int) $request->user()->id))->where('estado', 'matriculado')->pluck('seccion_id')->map(fn ($s) => (int) $s)->all() : null;

        return response()->json(['data' => $this->comunicados->eventos($secciones)]);
    }

    public function guardarEvento(Request $request, ?int $evento = null): JsonResponse
    {
        $d = $request->validate(['titulo' => ['required', 'string', 'max:200'], 'descripcion' => ['nullable', 'string', 'max:3000'], 'inicio' => ['required', 'date'],
            'fin' => ['nullable', 'date', 'after_or_equal:inicio'], 'tipo' => ['nullable', Rule::in(['evento', 'feriado', 'reunion', 'actuacion', 'examen'])],
            'niveles' => ['nullable', 'array'], 'niveles.*' => [Rule::in(['inicial', 'primaria', 'secundaria'])], 'secciones' => ['nullable', 'array'], 'secciones.*' => ['integer']],
            ['titulo.required' => 'Escribe el título del evento.', 'inicio.required' => 'Indica cuándo empieza.']);
        $id = $this->comunicados->guardarEvento($d, $evento);

        return response()->json(['id' => $id, 'data' => $this->comunicados->eventos()], $evento ? 200 : 201);
    }

    public function eliminarEvento(int $evento): JsonResponse
    {
        DB::table('eventos_calendario')->where('id', $evento)->delete();

        return response()->json(['data' => $this->comunicados->eventos()]);
    }

    // ── EDUN-65 · Citas

    public function citas(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->mensajeria->citas($request->user(), $request->boolean('familia'))]);
    }

    public function solicitarCita(Request $request): JsonResponse
    {
        $d = $request->validate(['alumno_id' => ['required', 'integer'], 'rol' => ['required', Rule::in(['tutor', 'direccion'])], 'inicio' => ['required', 'date'], 'motivo' => ['required', 'string', 'max:200']],
            ['motivo.required' => 'Indica el motivo de la cita.', 'inicio.required' => 'Propón una fecha y hora.']);
        $this->mensajeria->solicitarCita($request->user(), (int) $d['alumno_id'], $d['rol'], $d['inicio'], $d['motivo']);

        return response()->json(['data' => $this->mensajeria->citas($request->user(), true)], 201);
    }

    public function gestionarCita(Request $request, int $cita): JsonResponse
    {
        $d = $request->validate(['estado' => ['required', Rule::in(['confirmada', 'realizada', 'cancelada'])], 'inicio' => ['nullable', 'date'], 'acuerdos' => ['nullable', 'string', 'max:5000']]);
        $this->mensajeria->gestionarCita($cita, $d['estado'], $d['inicio'] ?? null, $d['acuerdos'] ?? null, $request->user());

        return response()->json(['data' => $this->mensajeria->citas($request->user(), false)]);
    }

    // ── EDUN-66 / EDUN-67 · Libro de reclamaciones

    public function colegioPublico(string $slug): JsonResponse
    {
        $c = LibroReclamaciones::colegioPorSlug($slug);
        abort_if(! $c, 404);

        return response()->json(['nombre' => $c->nombre, 'razon_social' => $c->razon_social, 'ruc' => $c->ruc, 'direccion' => $c->direccion, 'plazo_dias' => DB::connection('plataforma')
            ->table('parametros_legales')->where('codigo', 'reclamaciones.plazo_respuesta_dias')->orderByDesc('vigente_desde')->value('valor') ?? 15]);
    }

    public function reclamoPublico(Request $request, string $slug): JsonResponse
    {
        $c = LibroReclamaciones::colegioPorSlug($slug);
        abort_if(! $c, 404);
        $d = $request->validate([
            'tipo' => ['required', Rule::in(['reclamo', 'queja'])], 'nombre' => ['required', 'string', 'max:200'], 'documento' => ['required', 'string', 'max:15'],
            'email' => ['required', 'email', 'max:150'], 'celular' => ['nullable', 'string', 'max:15'], 'detalle' => ['required', 'string', 'min:10', 'max:5000'],
            'pedido' => ['required', 'string', 'min:5', 'max:3000'], 'monto' => ['nullable', 'numeric', 'min:0', 'max:999999'], 'acepta' => ['accepted'],
        ], ['nombre.required' => 'Escribe tu nombre completo.', 'documento.required' => 'Escribe tu documento de identidad.', 'email.required' => 'Escribe tu correo: te enviaremos la copia.',
            'detalle.required' => 'Describe lo ocurrido.', 'pedido.required' => 'Indica tu pedido.', 'acepta.accepted' => 'Confirma que los datos son verdaderos.']);

        $r = DB::transaction(function () use ($c, $d) {
            ContextoInstitucion::fijar((int) $c->id);

            return $this->libro->registrar($d);
        });

        return response()->json($r, 201);
    }

    public function reclamos(): JsonResponse
    {
        return response()->json(['data' => $this->libro->listar(), 'plazo_dias' => LibroReclamaciones::plazoDias()]);
    }

    public function responderReclamo(Request $request, int $reclamo): JsonResponse
    {
        $d = $request->validate(['respuesta' => ['required', 'string', 'min:20', 'max:10000']], ['respuesta.required' => 'Escribe la respuesta.', 'respuesta.min' => 'La respuesta debe ser completa.']);
        $this->libro->responder($reclamo, $d['respuesta'], (int) $request->user()->id);

        return response()->json(['data' => $this->libro->listar()]);
    }

    // ── EDUN-68 · Bitácora

    public function bitacora(Request $request): JsonResponse
    {
        $f = $request->validate(['canal' => ['nullable', Rule::in(['push', 'whatsapp', 'email', 'sms'])], 'estado' => ['nullable', 'string'], 'mes' => ['nullable', 'regex:/^\d{4}-\d{2}$/']]);

        return response()->json($this->notificador->bitacora($f));
    }
}
