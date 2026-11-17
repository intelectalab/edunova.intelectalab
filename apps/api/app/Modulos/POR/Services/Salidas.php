<?php

namespace App\Modulos\POR\Services;

use App\Modulos\ADM\Services\Carnes;
use App\Modulos\COM\Services\Notificador;
use App\Modulos\Compartido\ContextoInstitucion;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * EDUN-214 / EDUN-176 · Recojo verificado: quién puede llevarse a cada alumno y registro de salidas.
 *
 * - Pueden recoger los apoderados del alumno marcados con «puede recoger» y las personas autorizadas vigentes (ficha del alumno,
 *   tabla por_autorizados). Se verifica por número de documento.
 * - La portería descarga la lista al inicio del turno (lista()) para verificar sin señal; las salidas
 *   registradas sin conexión llegan en lote con su hora real (registrarLote()).
 * - Enfermería usa la misma verificación al entregar a un alumno derivado (origen «topico»).
 * - Cada salida avisa a la familia.
 */
class Salidas
{
    /** Máximo de horas atrás que se acepta una salida guardada sin conexión. */
    public const HORAS_ATRAS = 48;

    public function __construct(private Notificador $notificador) {}

    /** Lista del turno: alumnos activos con matrícula vigente y quiénes pueden recogerlos. */
    public function lista(?int $soloAlumno = null): array
    {
        $hoy = now()->toDateString();
        $alumnos = DB::table('alumnos as a')
            ->join('matriculas as m', fn ($j) => $j->on('m.alumno_id', '=', 'a.id')->where('m.estado', '=', 'matriculado'))
            ->join('anios_escolares as y', fn ($j) => $j->on('y.id', '=', 'm.anio_escolar_id')->where('y.estado', '=', 'abierto'))
            ->join('secciones as s', 's.id', '=', 'm.seccion_id')->join('grados as g', 'g.id', '=', 's.grado_id')
            ->whereNull('a.eliminado_at')->where('a.estado', 'activo')->when($soloAlumno, fn ($q) => $q->where('a.id', $soloAlumno))
            ->orderBy('a.apellido_paterno')->orderBy('a.nombres')
            ->get(['a.id', 'a.nombres', 'a.apellido_paterno', 'a.apellido_materno', 'a.codigo_carne', 'a.numero_documento', DB::raw("g.nombre || ' ' || s.nombre as seccion")]);
        $ids = $alumnos->pluck('id');
        $apoderados = DB::table('alumno_apoderado as aa')->join('apoderados as p', 'p.id', '=', 'aa.apoderado_id')->whereIn('aa.alumno_id', $ids)->where('aa.puede_recoger', true)
            ->get(['aa.alumno_id', DB::raw("p.nombres || ' ' || p.apellidos as nombres"), 'p.numero_documento', DB::raw("coalesce(aa.parentesco, 'Apoderado') as parentesco")])->groupBy('alumno_id');
        $autorizados = DB::table('por_autorizados')->whereIn('alumno_id', $ids)->where('activo', true)
            ->where(fn ($q) => $q->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>=', $hoy))
            ->get(['id', 'alumno_id', 'nombres', 'numero_documento', 'parentesco'])->groupBy('alumno_id');

        $anticipadas = DB::table('por_salidas as s')->leftJoin('por_autorizados as p', 'p.id', '=', 's.por_autorizado_id')->whereIn('s.alumno_id', $ids)
            ->where('s.fecha', $hoy)->where('s.estado', 'autorizada')->get(['s.alumno_id', 's.hora', 's.motivo', 'p.nombres'])->keyBy('alumno_id');

        return [
            'generado_at' => now()->toIso8601String(),
            'alumnos' => $alumnos->map(fn ($a) => [
                'id' => (int) $a->id, 'nombre' => trim("{$a->apellido_paterno} {$a->apellido_materno}, {$a->nombres}"), 'seccion' => $a->seccion,
                'codigo_carne' => $a->codigo_carne, 'numero_documento' => $a->numero_documento,
                'salida_anticipada' => isset($anticipadas[$a->id]) ? ['hora' => substr((string) $anticipadas[$a->id]->hora, 0, 5), 'motivo' => $anticipadas[$a->id]->motivo, 'recoge' => $anticipadas[$a->id]->nombres] : null,
                'pueden_recoger' => [
                    ...collect($apoderados[$a->id] ?? [])->map(fn ($p) => ['id' => null, 'nombres' => $p->nombres, 'numero_documento' => $p->numero_documento, 'parentesco' => $p->parentesco, 'apoderado' => true])->all(),
                    ...collect($autorizados[$a->id] ?? [])->map(fn ($p) => ['id' => (int) $p->id, 'nombres' => $p->nombres, 'numero_documento' => $p->numero_documento, 'parentesco' => $p->parentesco, 'apoderado' => false])->all(),
                ],
            ])->all(),
        ];
    }

    /** Quién puede recoger a un alumno (para enfermería y la verificación en línea). */
    public function puedenRecoger(int $alumnoId): array
    {
        $a = $this->lista($alumnoId)['alumnos'][0] ?? null;
        abort_if(! $a, 404, 'El alumno no tiene matrícula vigente.');

        return $a['pueden_recoger'];
    }

    /**
     * Verifica el documento de quien recoge y registra la salida.
     *
     * @return array{id: int, recoge: string, alumno: string}
     */
    public function registrar(int $alumnoId, string $documento, string $origen, ?string $motivo, int $usuarioId, ?Carbon $cuando = null): array
    {
        $documento = strtoupper(trim($documento));
        $persona = collect($this->puedenRecoger($alumnoId))->first(fn ($p) => strtoupper((string) $p['numero_documento']) === $documento);
        if (! $persona) {
            throw ValidationException::withMessages(['numero_documento' => ['Esa persona no está autorizada para recoger a este alumno.']]);
        }
        $ahora = ($cuando ?? now())->copy()->timezone(config('app.timezone'));
        if ($ahora->greaterThan(now()->addMinutes(5)) || $ahora->lessThan(now()->subHours(self::HORAS_ATRAS))) {
            throw ValidationException::withMessages(['salio_at' => ['La hora de la salida no es válida.']]);
        }
        $salida = [
            'por_autorizado_id' => $persona['id'], 'estado' => 'salio', 'salio_at' => $ahora, 'verificado_por' => $usuarioId,
            'recogido_por' => mb_substr("{$persona['nombres']} ({$persona['parentesco']})", 0, 200), 'recogido_documento' => $documento,
        ];
        // EDUN-189 · Si la familia pidió una salida anticipada para hoy y el colegio la autorizó, esa salida se cumple.
        $anticipada = $origen === 'porteria' ? DB::table('por_salidas')->where('alumno_id', $alumnoId)->where('fecha', $ahora->toDateString())->where('estado', 'autorizada')->value('id') : null;
        if ($anticipada) {
            DB::table('por_salidas')->where('id', $anticipada)->update($salida);
            $id = (int) $anticipada;
        } else {
            $id = (int) DB::table('por_salidas')->insertGetId([
                'institucion_id' => ContextoInstitucion::requerido(), 'alumno_id' => $alumnoId, 'fecha' => $ahora->toDateString(), 'hora' => $ahora->format('H:i:s'),
                'motivo' => mb_substr(trim($motivo ?: 'Recojo verificado en '.($origen === 'topico' ? 'tópico' : 'portería')), 0, 200), 'origen' => $origen,
            ] + $salida);
        }
        $alumno = DB::table('alumnos')->where('id', $alumnoId)->value('nombres');
        $padres = DB::table('alumno_apoderado as aa')->join('apoderados as p', 'p.id', '=', 'aa.apoderado_id')->where('aa.alumno_id', $alumnoId)->whereNotNull('p.user_id')->pluck('p.user_id')->all();
        $this->notificador->enviar($padres, ['app'], 'comunicados', "{$alumno} salió del colegio",
            "{$alumno} salió a las {$ahora->format('H:i')} con {$persona['nombres']} ({$persona['parentesco']}).", ['pantalla' => 'inicio', 'id' => $alumnoId]);

        return ['id' => $id, 'recoge' => "{$persona['nombres']} ({$persona['parentesco']})", 'alumno' => (string) $alumno];
    }

    /**
     * Salidas guardadas sin conexión, en orden. Cada una responde por separado: una rechazada no
     * frena a las demás, y la misma salida enviada dos veces no se registra dos veces.
     *
     * @param  list<array{id: string, alumno_id: int, numero_documento: string, salio_at: string, motivo?: string|null}>  $salidas
     * @return array{resultados: list<array{id: string, ok: bool, mensaje?: string}>}
     */
    public function registrarLote(array $salidas, int $usuarioId): array
    {
        $resultados = [];
        foreach ($salidas as $s) {
            $cuando = Carbon::parse($s['salio_at']);
            $repetida = DB::table('por_salidas')->where('alumno_id', $s['alumno_id'])->where('estado', 'salio')->where('salio_at', $cuando)
                ->where('recogido_documento', strtoupper(trim((string) $s['numero_documento'])))->exists();
            if ($repetida) {
                $resultados[] = ['id' => $s['id'], 'ok' => true];

                continue;
            }
            try {
                DB::transaction(fn () => $this->registrar((int) $s['alumno_id'], (string) $s['numero_documento'], 'porteria', $s['motivo'] ?? null, $usuarioId, $cuando));
                $resultados[] = ['id' => $s['id'], 'ok' => true];
            } catch (ValidationException $e) {
                $resultados[] = ['id' => $s['id'], 'ok' => false, 'mensaje' => collect($e->errors())->flatten()->first()];
            } catch (HttpException $e) {
                $resultados[] = ['id' => $s['id'], 'ok' => false, 'mensaje' => $e->getMessage() ?: 'Alumno no encontrado.'];
            }
        }

        return ['resultados' => $resultados];
    }

    /** Salidas del día (para la portería y la dirección). */
    public function delDia(?string $fecha = null): array
    {
        return DB::table('por_salidas as s')->join('alumnos as a', 'a.id', '=', 's.alumno_id')->leftJoin('por_autorizados as p', 'p.id', '=', 's.por_autorizado_id')
            ->where('s.fecha', $fecha ?? now()->toDateString())->orderByDesc('s.salio_at')
            ->get(['s.id', 's.hora', 's.origen', 's.motivo', 's.estado', DB::raw("a.apellido_paterno || ', ' || a.nombres as alumno"), DB::raw("coalesce(s.recogido_por, p.nombres, 'Apoderado') as recoge")])
            ->map(fn ($s) => (array) $s)->all();
    }

    /** Busca un alumno por carné, DNI o nombre (para la verificación en línea). */
    public function buscar(string $texto): array
    {
        if ($a = app(Carnes::class)->buscar($texto)) {
            return [$a];
        }
        $t = '%'.mb_strtolower(trim($texto)).'%';

        return DB::table('alumnos')->whereNull('eliminado_at')->whereRaw("lower(apellido_paterno || ' ' || coalesce(apellido_materno, '') || ' ' || nombres) like ?", [$t])
            ->orderBy('apellido_paterno')->limit(10)->get(['id', 'apellido_paterno', 'apellido_materno', 'nombres'])->map(fn ($a) => (array) $a)->all();
    }
}
