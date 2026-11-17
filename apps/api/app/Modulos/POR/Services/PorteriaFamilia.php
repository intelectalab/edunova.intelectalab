<?php

namespace App\Modulos\POR\Services;

use App\Models\User;
use App\Modulos\COM\Services\Notificador;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\Auditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-187 / EDUN-189 / EDUN-190 · Portería con la familia y las visitas.
 *
 * - Autorizados a recoger (POR-H02): el apoderado registra nombre, documento, parentesco y
 *   vigencia, y los activa o desactiva desde la app. Al personal se le muestra el documento
 *   enmascarado; la portería lo tiene completo en su lista del turno para verificar.
 * - Salida anticipada (POR-H04): la familia la pide con fecha, hora, motivo y quién recoge;
 *   secretaría o dirección la autoriza o rechaza (avisa a la familia) y la portería la ve en su
 *   lista: al verificar a quien recoge, la salida autorizada pasa a «salió».
 * - Visitas (POR-H05): nombre, documento, a quién visita y motivo, con hora de ingreso y salida.
 */
class PorteriaFamilia
{
    public function __construct(private Notificador $notificador, private Auditoria $auditoria) {}

    public static function enmascarar(?string $doc): string
    {
        $doc = (string) $doc;

        return strlen($doc) <= 4 ? $doc : str_repeat('•', strlen($doc) - 3).substr($doc, -3);
    }

    // ───────────── Autorizados (POR-H02)

    public function autorizados(array $hijos): array
    {
        return DB::table('por_autorizados as p')->join('alumnos as a', 'a.id', '=', 'p.alumno_id')->whereIn('p.alumno_id', $hijos ?: [0])->orderBy('a.nombres')->orderByDesc('p.activo')->orderBy('p.nombres')
            ->get(['p.id', 'p.alumno_id', 'a.nombres as alumno', 'p.nombres', 'p.numero_documento', 'p.parentesco', 'p.vigente_hasta', 'p.activo'])
            ->map(fn ($p) => array_merge((array) $p, ['vigente' => (bool) $p->activo && (! $p->vigente_hasta || $p->vigente_hasta >= now()->toDateString())]))->all();
    }

    /**
     * @param  array{alumno_id: int, nombres: string, numero_documento: string, parentesco: string, vigente_hasta?: string|null, activo?: bool}  $d
     */
    public function guardarAutorizado(?int $id, array $d, User $usuario, array $hijos): array
    {
        abort_unless(in_array((int) $d['alumno_id'], $hijos, true), 403, 'Solo registras autorizados para tus hijos.');
        $doc = strtoupper(preg_replace('/\s+/', '', $d['numero_documento']));
        if (! preg_match('/^(\d{8}|[A-Z0-9]{9,12})$/', $doc)) {
            throw ValidationException::withMessages(['numero_documento' => ['Escribe el DNI (8 dígitos) o el carné de extranjería.']]);
        }
        if (! empty($d['vigente_hasta']) && $d['vigente_hasta'] < now()->toDateString()) {
            throw ValidationException::withMessages(['vigente_hasta' => ['La vigencia no puede terminar en el pasado.']]);
        }
        $apoderado = DB::table('apoderados')->where('user_id', $usuario->id)->value('id');
        $fila = ['nombres' => mb_substr(trim($d['nombres']), 0, 200), 'numero_documento' => $doc, 'parentesco' => mb_substr(trim($d['parentesco']), 0, 40),
            'vigente_hasta' => $d['vigente_hasta'] ?? null, 'activo' => (bool) ($d['activo'] ?? true)];
        if ($id) {
            $actual = DB::table('por_autorizados')->where('id', $id)->first();
            abort_if(! $actual || ! in_array((int) $actual->alumno_id, $hijos, true), 404);
            DB::table('por_autorizados')->where('id', $id)->update($fila);
        } else {
            if (DB::table('por_autorizados')->where('alumno_id', $d['alumno_id'])->where('numero_documento', $doc)->exists()) {
                throw ValidationException::withMessages(['numero_documento' => ['Esa persona ya está registrada para este alumno: actívala si estaba desactivada.']]);
            }
            $id = (int) DB::table('por_autorizados')->insertGetId(['institucion_id' => ContextoInstitucion::requerido(), 'alumno_id' => (int) $d['alumno_id'],
                'registrado_por_apoderado_id' => $apoderado] + $fila);
        }
        $this->auditoria->registrar('porteria_autorizado', 'por_autorizados', $id, null, ['alumno_id' => (int) $d['alumno_id'], 'documento' => self::enmascarar($doc), 'activo' => $fila['activo']]);

        return collect($this->autorizados($hijos))->firstWhere('id', $id);
    }

    // ───────────── Salidas anticipadas (POR-H04)

    /**
     * @param  array{alumno_id: int, fecha: string, hora: string, motivo: string, por_autorizado_id?: int|null}  $d
     */
    public function solicitar(array $d, User $usuario, array $hijos, string $origen = 'app'): array
    {
        $alumno = (int) $d['alumno_id'];
        if ($origen === 'app') {
            abort_unless(in_array($alumno, $hijos, true), 403, 'Solo pides salidas de tus hijos.');
        }
        if ($d['fecha'] < now()->toDateString() || $d['fecha'] > now()->addDays(30)->toDateString()) {
            throw ValidationException::withMessages(['fecha' => ['Elige una fecha desde hoy y hasta dentro de 30 días.']]);
        }
        $autorizado = null;
        if (! empty($d['por_autorizado_id'])) {
            $autorizado = DB::table('por_autorizados')->where('id', $d['por_autorizado_id'])->where('alumno_id', $alumno)->where('activo', true)
                ->where(fn ($q) => $q->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>=', $d['fecha']))->first();
            if (! $autorizado) {
                throw ValidationException::withMessages(['por_autorizado_id' => ['Esa persona no está autorizada (o no estará vigente ese día).']]);
            }
        }
        $apoderado = $autorizado ? null : DB::table('apoderados as p')->join('alumno_apoderado as aa', 'aa.apoderado_id', '=', 'p.id')
            ->where('aa.alumno_id', $alumno)->when($origen === 'app', fn ($q) => $q->where('p.user_id', $usuario->id))->value('p.id');
        if (DB::table('por_salidas')->where('alumno_id', $alumno)->where('fecha', $d['fecha'])->whereIn('estado', ['solicitada', 'autorizada'])->exists()) {
            throw ValidationException::withMessages(['fecha' => ['Ya hay una salida pedida para ese día.']]);
        }
        $directa = $origen !== 'app';
        $id = (int) DB::table('por_salidas')->insertGetId([
            'institucion_id' => ContextoInstitucion::requerido(), 'alumno_id' => $alumno, 'fecha' => $d['fecha'], 'hora' => $d['hora'], 'motivo' => mb_substr(trim($d['motivo']), 0, 200),
            'origen' => $origen, 'por_autorizado_id' => $autorizado?->id, 'apoderado_id' => $apoderado, 'estado' => $directa ? 'autorizada' : 'solicitada',
            'solicitado_por' => $usuario->id, 'autorizado_por' => $directa ? $usuario->id : null,
        ]);
        $this->auditoria->registrar('salida_anticipada_'.($directa ? 'autorizada' : 'solicitada'), 'por_salidas', $id, null, ['alumno_id' => $alumno, 'fecha' => $d['fecha'], 'hora' => $d['hora']]);
        if (! $directa) {
            $usuarios = DB::table('membresias as m')->join('roles as r', 'r.id', '=', 'm.rol_id')->whereIn('r.codigo', ['secretaria'])->pluck('m.user_id')->all();
            $nombre = DB::table('alumnos')->where('id', $alumno)->value('nombres');
            $this->notificador->enviar($usuarios, ['app'], 'comunicados', 'Salida anticipada por autorizar', "{$nombre}: {$d['fecha']} a las ".substr($d['hora'], 0, 5).'.', ['pantalla' => 'salidas', 'id' => $id]);
        }

        return $this->salida($id);
    }

    public function resolver(int $id, bool $autorizar, ?string $respuesta, User $usuario): array
    {
        $s = DB::table('por_salidas')->where('id', $id)->lockForUpdate()->first();
        abort_if(! $s, 404);
        if ($s->estado !== 'solicitada') {
            throw ValidationException::withMessages(['estado' => ['Esa salida ya fue resuelta.']]);
        }
        if (! $autorizar && trim((string) $respuesta) === '') {
            throw ValidationException::withMessages(['respuesta' => ['Explica a la familia por qué no se autoriza.']]);
        }
        DB::table('por_salidas')->where('id', $id)->update(['estado' => $autorizar ? 'autorizada' : 'rechazada', 'autorizado_por' => $usuario->id, 'respuesta' => $respuesta ? mb_substr(trim($respuesta), 0, 300) : null]);
        $this->auditoria->registrar($autorizar ? 'salida_anticipada_autorizada' : 'salida_anticipada_rechazada', 'por_salidas', $id, ['estado' => 'solicitada'], ['estado' => $autorizar ? 'autorizada' : 'rechazada']);
        $nombre = DB::table('alumnos')->where('id', $s->alumno_id)->value('nombres');
        $this->notificador->enviar([(int) $s->solicitado_por], ['app'], 'comunicados', $autorizar ? "Salida de {$nombre} autorizada" : "Salida de {$nombre} no autorizada",
            $autorizar ? 'Portería lo entregará a la persona indicada, con su DNI.' : trim((string) $respuesta), ['pantalla' => 'inicio', 'id' => (int) $s->alumno_id]);

        return $this->salida($id);
    }

    public function anular(int $id, User $usuario, ?array $hijos = null): array
    {
        $s = DB::table('por_salidas')->where('id', $id)->first();
        abort_if(! $s || ($hijos !== null && ! in_array((int) $s->alumno_id, $hijos, true)), 404);
        if (! in_array($s->estado, ['solicitada', 'autorizada'], true)) {
            throw ValidationException::withMessages(['estado' => ['Esa salida ya no se puede anular.']]);
        }
        DB::table('por_salidas')->where('id', $id)->update(['estado' => 'anulada']);
        $this->auditoria->registrar('salida_anticipada_anulada', 'por_salidas', $id, ['estado' => $s->estado], ['estado' => 'anulada', 'por' => $usuario->id]);

        return $this->salida($id);
    }

    /** Pedidas y autorizadas (para secretaría y dirección); la familia ve las de sus hijos. */
    public function anticipadas(?array $hijos = null, ?string $estado = null): array
    {
        return $this->consulta()->whereIn('s.origen', ['app', 'secretaria'])
            ->when($hijos !== null, fn ($q) => $q->whereIn('s.alumno_id', $hijos ?: [0]))
            ->when($estado, fn ($q, $e) => $q->where('s.estado', $e), fn ($q) => $q->where('s.fecha', '>=', now()->subDays(7)->toDateString()))
            ->orderByRaw("s.estado = 'solicitada' desc")->orderBy('s.fecha')->orderBy('s.hora')->limit(200)->get()->map(fn ($s) => (array) $s)->all();
    }

    // ───────────── Visitas (POR-H05)

    public function visitas(?string $fecha): array
    {
        $dia = $fecha ?? now()->toDateString();

        return DB::table('por_visitas as v')->leftJoin('users as u', 'u.id', '=', 'v.registrado_por')
            ->whereRaw('(v.ingreso_at at time zone ?)::date = ?', [config('app.timezone'), $dia])->orderByDesc('v.ingreso_at')
            ->get(['v.id', 'v.nombres', 'v.numero_documento', 'v.visita_a', 'v.motivo', 'v.ingreso_at', 'v.salida_at', DB::raw("trim(coalesce(u.nombres, '') || ' ' || coalesce(u.apellidos, '')) as registrado_por")])
            ->map(fn ($v) => array_merge((array) $v, ['numero_documento' => self::enmascarar($v->numero_documento)]))->all();
    }

    public function registrarVisita(array $d, int $usuarioId): array
    {
        $id = (int) DB::table('por_visitas')->insertGetId([
            'institucion_id' => ContextoInstitucion::requerido(), 'nombres' => mb_substr(trim($d['nombres']), 0, 200), 'numero_documento' => strtoupper(trim($d['numero_documento'])),
            'visita_a' => mb_substr(trim($d['visita_a']), 0, 150), 'motivo' => isset($d['motivo']) ? mb_substr(trim($d['motivo']), 0, 200) : null, 'registrado_por' => $usuarioId,
        ]);

        return collect($this->visitas(null))->firstWhere('id', $id) ?? ['id' => $id];
    }

    public function salidaVisita(int $id): void
    {
        $n = DB::table('por_visitas')->where('id', $id)->whereNull('salida_at')->update(['salida_at' => now()]);
        if (! $n) {
            throw ValidationException::withMessages(['visita' => ['Esa visita ya tiene registrada su salida.']]);
        }
    }

    private function salida(int $id): array
    {
        return (array) $this->consulta()->where('s.id', $id)->first();
    }

    private function consulta()
    {
        return DB::table('por_salidas as s')->join('alumnos as a', 'a.id', '=', 's.alumno_id')->leftJoin('por_autorizados as p', 'p.id', '=', 's.por_autorizado_id')
            ->leftJoin('apoderados as ap', 'ap.id', '=', 's.apoderado_id')
            ->leftJoin('matriculas as m', fn ($j) => $j->on('m.alumno_id', '=', 's.alumno_id')->where('m.estado', '=', 'matriculado'))
            ->leftJoin('secciones as se', 'se.id', '=', 'm.seccion_id')->leftJoin('grados as g', 'g.id', '=', 'se.grado_id')
            ->select(['s.id', 's.alumno_id', 's.fecha', 's.hora', 's.motivo', 's.origen', 's.estado', 's.respuesta', 's.salio_at', 's.recogido_por',
                DB::raw("a.apellido_paterno || ', ' || a.nombres as alumno"), DB::raw("g.nombre || ' ' || se.nombre as seccion"),
                DB::raw("coalesce(p.nombres || ' (' || p.parentesco || ')', ap.nombres || ' ' || ap.apellidos || ' (apoderado)', 'Apoderado') as recoge")]);
    }
}
