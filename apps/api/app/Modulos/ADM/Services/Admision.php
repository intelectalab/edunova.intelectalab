<?php

namespace App\Modulos\ADM\Services;

use App\Modulos\ADM\Correo\AvisoEntrevista;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\Compartido\Correo\CorreoTransaccional;
use App\Modulos\SEG\Services\Auditoria;
use App\Modulos\SEG\Services\Invitaciones;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-53 / ADM-H11 · Admisión por etapas.
 *
 * - Tablero: interesado → entrevista → evaluación → vacante → matriculado | desistió; cada cambio
 *   queda en la historia del postulante (postulante_eventos).
 * - Agenda de entrevistas con aviso a la familia (correo y, si hay celular, enlace de WhatsApp).
 * - Convertir al postulante en alumno (con su contacto como apoderado); queda listo para
 *   matricularlo en ventanilla. Al matricularse, pasa a «matriculado».
 * - Vacantes por grado del año.
 */
class Admision
{
    public const ETAPAS = ['interesado' => 'Interesado', 'entrevista' => 'Entrevista', 'evaluacion' => 'Evaluación', 'vacante' => 'Vacante otorgada', 'matriculado' => 'Matriculado', 'desistio' => 'Desistió'];

    public function __construct(private Auditoria $auditoria, private RegistroAlumnos $registro, private CorreoTransaccional $correo) {}

    public function tablero(int $anioId): array
    {
        PlanEstudios::anio($anioId);
        $procesos = DB::table('admision_procesos')->where('anio_escolar_id', $anioId)->orderByDesc('fecha_inicio')->get(['id', 'nombre', 'fecha_inicio', 'fecha_fin', 'estado']);
        $postulantes = DB::table('postulantes as p')->join('admision_procesos as ap', 'ap.id', '=', 'p.admision_proceso_id')->join('grados as g', 'g.id', '=', 'p.grado_id')
            ->where('ap.anio_escolar_id', $anioId)->orderBy('p.updated_at', 'desc')
            ->get(['p.id', 'p.admision_proceso_id', 'p.grado_id', 'g.nombre as grado', 'p.apellidos', 'p.nombres', 'p.numero_documento', 'p.contacto_nombre',
                'p.contacto_celular', 'p.contacto_email', 'p.etapa', 'p.alumno_id', 'p.origen', 'p.created_at']);
        $entrevistas = DB::table('postulante_eventos')->where('tipo', 'entrevista')->where('fecha', '>=', now()->subDay())
            ->whereIn('postulante_id', $postulantes->pluck('id'))->orderBy('fecha')->get(['postulante_id', 'fecha', 'resultado'])->groupBy('postulante_id');

        $ocupadas = DB::table('postulantes as p')->join('admision_procesos as ap', 'ap.id', '=', 'p.admision_proceso_id')
            ->where('ap.anio_escolar_id', $anioId)->whereIn('p.etapa', ['vacante', 'matriculado'])->groupBy('p.grado_id')
            ->select('p.grado_id', DB::raw('count(*) as n'))->pluck('n', 'grado_id');

        return [
            'etapas' => self::ETAPAS,
            'procesos' => $procesos,
            'postulantes' => $postulantes->map(fn ($p) => (array) $p + ['proxima_entrevista' => ($entrevistas[$p->id] ?? collect())->first()?->fecha])->all(),
            'vacantes' => DB::table('grados as g')->leftJoin('vacantes as v', fn ($j) => $j->on('v.grado_id', '=', 'g.id')->where('v.anio_escolar_id', '=', $anioId))
                ->orderByRaw("case g.nivel when 'inicial' then 1 when 'primaria' then 2 else 3 end")->orderBy('g.numero')
                ->get(['g.id as grado_id', 'g.nombre as grado', 'v.cantidad'])
                ->map(fn ($v) => (array) $v + ['otorgadas' => (int) ($ocupadas[$v->grado_id] ?? 0)])->all(),
            'agenda' => DB::table('postulante_eventos as e')->join('postulantes as p', 'p.id', '=', 'e.postulante_id')->join('admision_procesos as ap', 'ap.id', '=', 'p.admision_proceso_id')
                ->where('ap.anio_escolar_id', $anioId)->where('e.tipo', 'entrevista')->where('e.fecha', '>=', now()->startOfDay())->orderBy('e.fecha')->limit(50)
                ->get(['e.id', 'e.fecha', 'e.resultado', 'p.id as postulante_id', DB::raw("p.nombres || ' ' || p.apellidos as postulante")])->map(fn ($e) => (array) $e)->all(),
        ];
    }

    public function crearProceso(int $anioId, string $nombre, string $inicio, string $fin): int
    {
        PlanEstudios::anio($anioId);
        if ($fin < $inicio) {
            throw ValidationException::withMessages(['fecha_fin' => ['La fecha de fin debe ser posterior a la de inicio.']]);
        }

        return (int) DB::table('admision_procesos')->insertGetId([
            'institucion_id' => ContextoInstitucion::requerido(), 'anio_escolar_id' => $anioId, 'nombre' => trim($nombre), 'fecha_inicio' => $inicio, 'fecha_fin' => $fin,
        ]);
    }

    public function guardarVacantes(int $anioId, int $gradoId, int $cantidad): void
    {
        PlanEstudios::anio($anioId);
        DB::table('vacantes')->upsert(
            [['institucion_id' => ContextoInstitucion::requerido(), 'anio_escolar_id' => $anioId, 'grado_id' => $gradoId, 'cantidad' => $cantidad]],
            ['anio_escolar_id', 'grado_id'], ['cantidad'],
        );
    }

    public function registrar(array $d, int $usuarioId): int
    {
        $proceso = DB::table('admision_procesos')->find($d['admision_proceso_id']);
        if (! $proceso) {
            throw ValidationException::withMessages(['admision_proceso_id' => ['Elige el proceso de admisión.']]);
        }
        $id = (int) DB::table('postulantes')->insertGetId([
            'institucion_id' => ContextoInstitucion::requerido(), 'admision_proceso_id' => $proceso->id, 'grado_id' => $d['grado_id'],
            'apellidos' => trim($d['apellidos']), 'nombres' => trim($d['nombres']), 'numero_documento' => $d['numero_documento'] ?? null,
            'contacto_nombre' => trim($d['contacto_nombre']), 'contacto_celular' => $d['contacto_celular'], 'contacto_email' => isset($d['contacto_email']) ? mb_strtolower(trim($d['contacto_email'])) : null,
            'origen' => $d['origen'] ?? 'secretaria',
        ]);
        $this->evento($id, 'registro', 'Postulante registrado', $usuarioId);

        return $id;
    }

    /** Mover en el tablero (arrastrar entre etapas). */
    public function moverEtapa(int $id, string $etapa, int $usuarioId): void
    {
        $p = DB::table('postulantes')->find($id);
        abort_if(! $p, 404);
        if ($p->etapa === $etapa) {
            return;
        }
        if ($etapa === 'matriculado' && ! $p->alumno_id) {
            throw ValidationException::withMessages(['etapa' => ['Primero convierte al postulante en alumno y matricúlalo.']]);
        }
        if ($etapa === 'vacante') {
            $vacantes = DB::table('vacantes as v')->join('admision_procesos as ap', 'ap.anio_escolar_id', '=', 'v.anio_escolar_id')
                ->where('ap.id', $p->admision_proceso_id)->where('v.grado_id', $p->grado_id)->value('v.cantidad');
            $otorgadas = DB::table('postulantes as x')->join('admision_procesos as ap', 'ap.id', '=', 'x.admision_proceso_id')
                ->where('ap.anio_escolar_id', DB::table('admision_procesos')->where('id', $p->admision_proceso_id)->value('anio_escolar_id'))
                ->where('x.grado_id', $p->grado_id)->whereIn('x.etapa', ['vacante', 'matriculado'])->count();
            if ($vacantes !== null && $otorgadas >= $vacantes) {
                throw ValidationException::withMessages(['etapa' => ['Ya no quedan vacantes en ese grado.']]);
            }
        }
        DB::table('postulantes')->where('id', $id)->update(['etapa' => $etapa]);
        $this->evento($id, 'cambio_etapa', self::ETAPAS[$p->etapa].' → '.self::ETAPAS[$etapa], $usuarioId);
    }

    /**
     * Agenda la entrevista y avisa a la familia.
     *
     * @return array{correo: bool, whatsapp_url: string|null}
     */
    public function agendarEntrevista(int $id, string $fecha, ?string $lugar, int $usuarioId): array
    {
        $p = DB::table('postulantes')->find($id);
        abort_if(! $p, 404);
        $cuando = Carbon::parse($fecha, 'America/Lima');
        if ($cuando->isPast()) {
            throw ValidationException::withMessages(['fecha' => ['Elige una fecha y hora futuras.']]);
        }
        $this->evento($id, 'entrevista', $lugar ? "Lugar: {$lugar}" : null, $usuarioId, $cuando->copy()->timezone(config('app.timezone')));
        if ($p->etapa === 'interesado') {
            $this->moverEtapa($id, 'entrevista', $usuarioId);
        }
        $colegio = (string) DB::table('instituciones')->where('id', ContextoInstitucion::requerido())->value('nombre');
        $texto = $cuando->locale('es')->translatedFormat('l j \d\e F \a \l\a\s H:i');
        if ($p->contacto_email) {
            $this->correo->enviar(new AvisoEntrevista($colegio, $p->contacto_nombre, trim("{$p->nombres} {$p->apellidos}"), $texto, $lugar), $p->contacto_email, 'aviso_entrevista', ContextoInstitucion::requerido());
        }
        $celular = Invitaciones::celularWhatsapp($p->contacto_celular);
        $wa = $celular ? 'https://wa.me/'.$celular.'?text='.rawurlencode("Hola, {$p->contacto_nombre}. {$colegio} le confirma la entrevista de admisión de {$p->nombres} el {$texto}".($lugar ? " en {$lugar}" : '').'.') : null;

        return ['correo' => (bool) $p->contacto_email, 'whatsapp_url' => $wa];
    }

    /** Crea el alumno con su contacto como apoderado (queda listo para matricular). */
    public function convertir(int $id, array $apoderado, int $usuarioId): int
    {
        $p = DB::table('postulantes')->find($id);
        abort_if(! $p, 404);
        if ($p->alumno_id) {
            return (int) $p->alumno_id;
        }
        if (! in_array($p->etapa, ['vacante', 'evaluacion'], true)) {
            throw ValidationException::withMessages(['etapa' => ['Solo se convierte a quien ya tiene vacante (o está en evaluación).']]);
        }
        [$paterno, $materno] = array_pad(preg_split('/\s+/', trim($p->apellidos), 2) ?: [], 2, null);
        $alumno = $this->registro->guardar([
            'tipo_documento' => 'DNI', 'numero_documento' => $p->numero_documento, 'apellido_paterno' => $paterno, 'apellido_materno' => $materno, 'nombres' => $p->nombres,
            'apoderados' => [$apoderado + ['celular' => $p->contacto_celular, 'email' => $p->contacto_email, 'es_responsable_pago' => true]],
        ]);
        DB::table('postulantes')->where('id', $id)->update(['alumno_id' => $alumno, 'etapa' => 'vacante']);
        $this->evento($id, 'conversion', 'Convertido en alumno', $usuarioId);
        $this->auditoria->registrar('postulante_convertido', 'postulantes', $id, null, ['alumno_id' => $alumno]);

        return $alumno;
    }

    /** Al matricular a un alumno que vino de admisión, el postulante pasa a «matriculado». */
    public static function alMatricular(int $alumnoId): void
    {
        $ids = DB::table('postulantes')->where('alumno_id', $alumnoId)->where('etapa', '<>', 'matriculado')->pluck('id');
        foreach ($ids as $id) {
            DB::table('postulantes')->where('id', $id)->update(['etapa' => 'matriculado']);
            DB::table('postulante_eventos')->insert(['institucion_id' => ContextoInstitucion::requerido(), 'postulante_id' => $id, 'tipo' => 'cambio_etapa', 'fecha' => now(), 'resultado' => 'Matriculado']);
        }
    }

    public function historia(int $id): array
    {
        abort_if(! DB::table('postulantes')->where('id', $id)->exists(), 404);

        return DB::table('postulante_eventos as e')->leftJoin('users as u', 'u.id', '=', 'e.responsable_id')->where('e.postulante_id', $id)->orderBy('e.fecha')
            ->get(['e.tipo', 'e.fecha', 'e.resultado', DB::raw("u.nombres || ' ' || u.apellidos as responsable")])->map(fn ($e) => (array) $e)->all();
    }

    private function evento(int $postulante, string $tipo, ?string $resultado, int $usuarioId, ?Carbon $fecha = null): void
    {
        DB::table('postulante_eventos')->insert([
            'institucion_id' => ContextoInstitucion::requerido(), 'postulante_id' => $postulante, 'tipo' => $tipo,
            'fecha' => $fecha ?? now(), 'resultado' => $resultado, 'responsable_id' => $usuarioId,
        ]);
    }
}
