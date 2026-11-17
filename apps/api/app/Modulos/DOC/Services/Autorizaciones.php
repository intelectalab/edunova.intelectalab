<?php

namespace App\Modulos\DOC\Services;

use App\Models\User;
use App\Modulos\COM\Services\Notificador;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\AlcanceAcademico;
use App\Modulos\SEG\Services\Auditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-122 / DOC-H10 · Autorizaciones firmadas por los padres (salidas, paseos, campañas).
 *
 * - Alcance por sección (una o varias); el docente solo elige las secciones que ve.
 * - Cada apoderado firma en la app por cada hijo: acepta o no acepta, con fecha, hora e IP.
 *   Una firma no se cambia (si se equivocó, el colegio la anula y vuelve a pedirla).
 * - Seguimiento: firmadas (sí / no) y pendientes por alumno; recordatorio a los pendientes.
 */
class Autorizaciones
{
    public function __construct(private Notificador $notificador, private Auditoria $auditoria, private AlcanceAcademico $alcance) {}

    /**
     * @param  list<int>  $secciones
     */
    public function crear(string $titulo, string $contenido, array $secciones, string $vence, User $usuario): array
    {
        $secciones = array_values(array_unique(array_map('intval', $secciones)));
        $visibles = $this->alcance->veTodoElColegio($usuario) ? null : $this->alcance->seccionesVisibles($usuario)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $existentes = DB::table('secciones')->whereIn('id', $secciones)->pluck('id')->map(fn ($i) => (int) $i)->all();
        if (count($existentes) !== count($secciones) || ($visibles !== null && array_diff($secciones, $visibles))) {
            throw ValidationException::withMessages(['secciones' => ['Elige secciones que tienes a tu cargo.']]);
        }
        if ($vence < now()->toDateString()) {
            throw ValidationException::withMessages(['vence' => ['La fecha límite no puede ser pasada.']]);
        }
        $id = (int) DB::table('autorizaciones')->insertGetId([
            'institucion_id' => ContextoInstitucion::requerido(), 'titulo' => trim($titulo), 'contenido' => trim($contenido),
            'alcance' => json_encode(['secciones' => $secciones]), 'vence' => $vence, 'creado_por' => $usuario->id,
        ]);
        $this->auditoria->registrar('autorizacion_creada', 'autorizaciones', $id, null, ['titulo' => trim($titulo), 'secciones' => $secciones]);
        $this->avisar($id, null);

        return $this->resumen($id);
    }

    /** Las que creó el usuario (o todas, para dirección), con avance de firmas. */
    public function lista(User $usuario): array
    {
        $todas = $this->alcance->veTodoElColegio($usuario);

        return DB::table('autorizaciones')->when(! $todas, fn ($q) => $q->where('creado_por', $usuario->id))->orderByDesc('id')->limit(100)->pluck('id')
            ->map(fn ($id) => $this->resumen((int) $id))->all();
    }

    public function seguimiento(int $id): array
    {
        $a = DB::table('autorizaciones')->find($id);
        abort_if(! $a, 404);
        $firmas = DB::table('autorizacion_firmas as f')->join('apoderados as p', 'p.id', '=', 'f.apoderado_id')->where('f.autorizacion_id', $id)
            ->get(['f.alumno_id', 'f.acepta', 'f.firmado_at', DB::raw("p.nombres || ' ' || p.apellidos as apoderado")])->keyBy('alumno_id');

        return array_merge($this->resumen($id), [
            'contenido' => $a->contenido,
            'alumnos' => $this->alumnos($a)->map(fn ($al) => [
                'alumno_id' => (int) $al->id, 'alumno' => $al->alumno, 'seccion' => $al->seccion,
                'estado' => isset($firmas[$al->id]) ? ($firmas[$al->id]->acepta ? 'acepta' : 'no_acepta') : 'pendiente',
                'apoderado' => $firmas[$al->id]->apoderado ?? null, 'firmado_at' => $firmas[$al->id]->firmado_at ?? null,
            ])->all(),
        ]);
    }

    public function recordar(int $id): int
    {
        abort_if(! DB::table('autorizaciones')->where('id', $id)->exists(), 404);

        return $this->avisar($id, 'Recordatorio: ');
    }

    /** Pendientes y firmadas de los hijos del usuario. */
    public function deFamilia(array $hijos): array
    {
        if (! $hijos) {
            return [];
        }
        $secciones = DB::table('matriculas')->whereIn('alumno_id', $hijos)->where('estado', 'matriculado')->get(['alumno_id', 'seccion_id']);
        $firmas = DB::table('autorizacion_firmas')->whereIn('alumno_id', $hijos)->get()->groupBy('autorizacion_id');
        $nombres = DB::table('alumnos')->whereIn('id', $hijos)->pluck('nombres', 'id');
        $r = [];
        foreach (DB::table('autorizaciones')->where('vence', '>=', now()->subDays(30)->toDateString())->orderByDesc('id')->get() as $a) {
            $alcance = json_decode($a->alcance, true)['secciones'] ?? [];
            foreach ($secciones->whereIn('seccion_id', $alcance) as $m) {
                $f = collect($firmas[$a->id] ?? [])->firstWhere('alumno_id', $m->alumno_id);
                $r[] = ['id' => (int) $a->id, 'titulo' => $a->titulo, 'contenido' => $a->contenido, 'vence' => $a->vence, 'alumno_id' => (int) $m->alumno_id,
                    'alumno' => $nombres[$m->alumno_id] ?? '', 'estado' => $f ? ($f->acepta ? 'acepta' : 'no_acepta') : ($a->vence < now()->toDateString() ? 'vencida' : 'pendiente'),
                    'firmado_at' => $f->firmado_at ?? null];
            }
        }

        return $r;
    }

    public function firmar(int $id, int $alumnoId, bool $acepta, int $usuarioId, array $hijos, ?string $ip): void
    {
        abort_unless(in_array($alumnoId, $hijos, true), 403, 'Solo firmas por tus hijos.');
        $a = DB::table('autorizaciones')->find($id);
        abort_if(! $a, 404);
        $seccion = DB::table('matriculas')->where('alumno_id', $alumnoId)->where('estado', 'matriculado')->orderByDesc('anio_escolar_id')->value('seccion_id');
        abort_unless(in_array((int) $seccion, json_decode($a->alcance, true)['secciones'] ?? [], true), 404);
        if ($a->vence < now()->toDateString()) {
            throw ValidationException::withMessages(['autorizacion' => ['El plazo para firmar esta autorización ya venció.']]);
        }
        $apoderado = DB::table('apoderados as p')->join('alumno_apoderado as aa', 'aa.apoderado_id', '=', 'p.id')->where('p.user_id', $usuarioId)->where('aa.alumno_id', $alumnoId)->value('p.id');
        abort_if(! $apoderado, 403, 'Solo el apoderado del alumno firma la autorización.');
        if (DB::table('autorizacion_firmas')->where('autorizacion_id', $id)->where('alumno_id', $alumnoId)->exists()) {
            throw ValidationException::withMessages(['autorizacion' => ['Esta autorización ya está firmada para este alumno.']]);
        }
        DB::table('autorizacion_firmas')->insert(['institucion_id' => ContextoInstitucion::requerido(), 'autorizacion_id' => $id, 'alumno_id' => $alumnoId,
            'apoderado_id' => $apoderado, 'acepta' => $acepta, 'firmado_at' => now(), 'ip' => $ip]);
        $this->auditoria->registrar('autorizacion_firmada', 'autorizaciones', $id, null, ['alumno_id' => $alumnoId, 'acepta' => $acepta]);
    }

    /** Anula una firma para que el apoderado vuelva a firmar (por ejemplo, si se equivocó). */
    public function anularFirma(int $id, int $alumnoId): void
    {
        $n = DB::table('autorizacion_firmas')->where('autorizacion_id', $id)->where('alumno_id', $alumnoId)->delete();
        abort_if(! $n, 404);
        $this->auditoria->registrar('autorizacion_firma_anulada', 'autorizaciones', $id, ['alumno_id' => $alumnoId], null);
    }

    private function resumen(int $id): array
    {
        $a = DB::table('autorizaciones as a')->leftJoin('users as u', 'u.id', '=', 'a.creado_por')->where('a.id', $id)
            ->first(['a.id', 'a.titulo', 'a.vence', 'a.alcance', 'a.created_at', DB::raw("trim(coalesce(u.nombres, '') || ' ' || coalesce(u.apellidos, '')) as creado_por")]);
        $total = $this->alumnos($a)->count();
        $f = DB::table('autorizacion_firmas')->where('autorizacion_id', $id)->selectRaw('count(*) filter (where acepta) as si, count(*) filter (where not acepta) as no')->first();
        $secciones = DB::table('secciones as s')->join('grados as g', 'g.id', '=', 's.grado_id')->whereIn('s.id', json_decode($a->alcance, true)['secciones'] ?? [])
            ->orderBy('g.nivel')->orderBy('g.numero')->orderBy('s.nombre')->get([DB::raw("g.nombre || ' ' || s.nombre as nombre")])->pluck('nombre')->all();

        return ['id' => (int) $a->id, 'titulo' => $a->titulo, 'vence' => $a->vence, 'created_at' => $a->created_at, 'creado_por' => $a->creado_por, 'secciones' => $secciones,
            'total' => $total, 'aceptan' => (int) $f->si, 'no_aceptan' => (int) $f->no, 'pendientes' => max(0, $total - (int) $f->si - (int) $f->no)];
    }

    private function alumnos(object $a)
    {
        return DB::table('matriculas as m')->join('alumnos as al', 'al.id', '=', 'm.alumno_id')->join('secciones as s', 's.id', '=', 'm.seccion_id')->join('grados as g', 'g.id', '=', 's.grado_id')
            ->where('m.estado', 'matriculado')->whereIn('m.seccion_id', json_decode($a->alcance, true)['secciones'] ?? [])->whereNull('al.eliminado_at')
            ->orderBy('al.apellido_paterno')->get(['al.id', DB::raw("al.apellido_paterno || ', ' || al.nombres as alumno"), DB::raw("g.nombre || ' ' || s.nombre as seccion")]);
    }

    private function avisar(int $id, ?string $prefijo): int
    {
        $a = DB::table('autorizaciones')->find($id);
        $firmados = DB::table('autorizacion_firmas')->where('autorizacion_id', $id)->pluck('alumno_id');
        $alumnos = $this->alumnos($a)->pluck('id')->diff($firmados);
        $usuarios = DB::table('alumno_apoderado as aa')->join('apoderados as p', 'p.id', '=', 'aa.apoderado_id')->whereIn('aa.alumno_id', $alumnos)->whereNotNull('p.user_id')->pluck('p.user_id')->unique()->all();

        return $this->notificador->enviar($usuarios, ['app'], 'comunicados', ($prefijo ?? '').'Autorización: '.$a->titulo,
            'Fírmala en la app antes del '.date('d/m/Y', strtotime((string) $a->vence)).'.', ['pantalla' => 'autorizaciones', 'id' => $id])['app'];
    }
}
