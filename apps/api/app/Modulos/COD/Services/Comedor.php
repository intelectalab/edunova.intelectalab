<?php

namespace App\Modulos\COD\Services;

use App\Models\User;
use App\Modulos\ADM\Services\Carnes;
use App\Modulos\COM\Services\Notificador;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\PAG\Services\GeneradorCargos;
use App\Modulos\SAL\Services\Salud;
use App\Modulos\SEG\Services\Auditoria;
use App\Modulos\SEG\Services\Autorizacion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-168 a 175 · Comedor escolar.
 *
 * - Menú por día (entrada, fondo, bebida, postre) con kcal, nutrientes y alérgenos (Ley 30021).
 *   Flujo: borrador → propuesto → aprobado → publicado. Si el colegio tiene nutricionista, solo
 *   ella aprueba (con porciones por nivel) y sin aprobación no se publica.
 * - Planes mensual o diario con su concepto de cobro (aparte de la pensión): el mensual genera un
 *   cargo por mes al inscribir; el diario, un cargo por cada día servido.
 * - Pase con el QR del carné (o lista rápida): avisa si no está inscrito, si ya comió (un servido
 *   por día, único en la base) o si tiene dieta especial, sin mostrar el detalle de salud.
 * - Restricciones alimentarias en el esquema salud (solo nutrición y enfermería ven el detalle).
 * - Seguimiento nutricional: peso y talla solo de alumnos con la campaña autorizada por la familia.
 */
class Comedor
{
    public const ESTADOS = ['borrador', 'propuesto', 'aprobado', 'publicado'];

    public const NIVELES = ['inicial', 'primaria', 'secundaria'];

    public function __construct(private Notificador $notificador, private Auditoria $auditoria, private Autorizacion $autorizacion) {}

    public static function tieneNutricionista(): bool
    {
        return DB::table('membresias as m')->join('roles as r', 'r.id', '=', 'm.rol_id')->where('r.codigo', 'nutricionista')->exists();
    }

    // ───────────── Menú (EDUN-168 / 172 / 173)

    public function semana(string $lunes, bool $soloPublicados = false): array
    {
        $inicio = Carbon::parse($lunes)->startOfWeek();

        return DB::table('com_menus')->whereBetween('fecha', [$inicio->toDateString(), $inicio->copy()->addDays(6)->toDateString()])
            ->when($soloPublicados, fn ($q) => $q->where('estado', 'publicado'))->orderBy('fecha')->get()
            ->map(fn ($m) => array_merge((array) $m, ['porciones' => $m->porciones ? json_decode($m->porciones, true) : null,
                'proteinas_g' => $m->proteinas_g !== null ? (float) $m->proteinas_g : null, 'carbohidratos_g' => $m->carbohidratos_g !== null ? (float) $m->carbohidratos_g : null,
                'grasas_g' => $m->grasas_g !== null ? (float) $m->grasas_g : null]))->all();
    }

    public function guardarMenu(array $d): array
    {
        $actual = DB::table('com_menus')->where('fecha', $d['fecha'])->first();
        if ($actual && in_array($actual->estado, ['aprobado', 'publicado'], true) && self::tieneNutricionista()) {
            throw ValidationException::withMessages(['fecha' => ['Ese menú ya está aprobado: pídele a nutrición que lo devuelva para cambiarlo.']]);
        }
        $fila = array_intersect_key($d, array_flip(['entrada', 'fondo', 'bebida', 'postre', 'kcal', 'proteinas_g', 'carbohidratos_g', 'grasas_g', 'alergenos']));
        if ($actual) {
            DB::table('com_menus')->where('id', $actual->id)->update($fila + ['estado' => 'borrador', 'publicado' => false]);
        } else {
            DB::table('com_menus')->insert(['institucion_id' => ContextoInstitucion::requerido(), 'fecha' => $d['fecha'], 'estado' => 'borrador'] + $fila);
        }

        return (array) DB::table('com_menus')->where('fecha', $d['fecha'])->first();
    }

    /** Proponer, aprobar (nutrición), devolver con observación o publicar. */
    public function moverMenu(int $id, string $accion, ?array $porciones, ?string $observacion, User $usuario): array
    {
        $m = DB::table('com_menus')->where('id', $id)->lockForUpdate()->first();
        abort_if(! $m, 404);
        $nutri = self::tieneNutricionista();
        $esNutri = $this->autorizacion->tieneRol($usuario, 'nutricionista');
        $cambio = match ($accion) {
            'proponer' => $m->estado === 'borrador' ? ['estado' => 'propuesto'] : null,
            'aprobar' => in_array($m->estado, ['borrador', 'propuesto'], true) ? ['estado' => 'aprobado', 'aprobado_por' => $usuario->id, 'aprobado_at' => now(),
                'porciones' => $porciones ? json_encode($porciones, JSON_UNESCAPED_UNICODE) : $m->porciones, 'observacion_nutricion' => $observacion,
                'validado_por' => mb_substr(trim("{$usuario->nombres} {$usuario->apellidos}"), 0, 150)] : null,
            'devolver' => in_array($m->estado, ['propuesto', 'aprobado'], true) ? ['estado' => 'borrador', 'observacion_nutricion' => $observacion, 'publicado' => false] : null,
            'publicar' => ($m->estado === 'aprobado' || (! $nutri && in_array($m->estado, ['borrador', 'propuesto'], true))) ? ['estado' => 'publicado', 'publicado' => true] : null,
            default => null,
        };
        if (! $cambio) {
            throw ValidationException::withMessages(['estado' => [$accion === 'publicar' && $nutri ? 'Nutrición debe aprobar el menú antes de publicarlo.' : 'Esa acción no corresponde al estado del menú.']]);
        }
        if (in_array($accion, ['aprobar', 'devolver'], true) && $nutri && ! $esNutri) {
            abort(403, 'Solo nutrición aprueba o devuelve el menú.');
        }
        if ($accion === 'devolver' && trim((string) $observacion) === '') {
            throw ValidationException::withMessages(['observacion' => ['Explica qué cambiar en el menú.']]);
        }
        DB::table('com_menus')->where('id', $id)->update($cambio);
        $this->auditoria->registrar('menu_'.$accion, 'com_menus', $id, ['estado' => $m->estado], ['estado' => $cambio['estado']]);
        if ($accion === 'publicar') {
            $this->notificador->enviar($this->familiasInscritas(), ['app'], 'comunicados', 'Menú del comedor', 'Menú del '.Carbon::parse($m->fecha)->format('d/m').": {$m->fondo}.", ['pantalla' => 'comedor']);
        }

        return (array) DB::table('com_menus')->find($id);
    }

    // ───────────── Planes e inscripciones (EDUN-169)

    public function planes(): array
    {
        return DB::table('com_planes as p')->leftJoin('conceptos_cobro as c', 'c.id', '=', 'p.concepto_cobro_id')->orderBy('p.nombre')
            ->get(['p.id', 'p.nombre', 'p.modalidad', 'p.concepto_cobro_id', 'p.activo', 'c.nombre as concepto', 'c.monto'])
            ->map(fn ($p) => array_merge((array) $p, ['monto' => $p->monto !== null ? (float) $p->monto : null]))->all();
    }

    public function guardarPlan(?int $id, array $d): array
    {
        if (! empty($d['concepto_cobro_id']) && ! DB::table('conceptos_cobro')->where('id', $d['concepto_cobro_id'])->exists()) {
            throw ValidationException::withMessages(['concepto_cobro_id' => ['Ese concepto de cobro no existe.']]);
        }
        $fila = ['nombre' => trim($d['nombre']), 'modalidad' => $d['modalidad'], 'concepto_cobro_id' => $d['concepto_cobro_id'] ?? null, 'activo' => (bool) ($d['activo'] ?? true)];
        if ($id) {
            DB::table('com_planes')->where('id', $id)->update($fila);
        } else {
            $id = (int) DB::table('com_planes')->insertGetId(['institucion_id' => ContextoInstitucion::requerido()] + $fila);
        }

        return collect($this->planes())->firstWhere('id', $id);
    }

    public function inscripciones(?string $buscar): array
    {
        return DB::table('com_inscripciones as i')->join('alumnos as a', 'a.id', '=', 'i.alumno_id')->join('com_planes as p', 'p.id', '=', 'i.com_plan_id')
            ->where(fn ($q) => $q->whereNull('i.hasta')->orWhere('i.hasta', '>=', now()->toDateString()))
            ->when($buscar, fn ($q, $b) => $q->whereRaw("lower(a.apellido_paterno || ' ' || a.nombres) like ?", ['%'.mb_strtolower(trim($b)).'%']))
            ->orderBy('a.apellido_paterno')->limit(500)
            ->get(['i.id', 'i.alumno_id', 'i.desde', 'i.hasta', 'p.nombre as plan', 'p.modalidad', DB::raw("a.apellido_paterno || ', ' || a.nombres as alumno")])
            ->map(fn ($i) => array_merge((array) $i, ['dieta_especial' => in_array((int) $i->alumno_id, $this->conRestriccion(), true)]))->all();
    }

    /** Inscribe al alumno; el plan mensual genera un cargo por cada mes del periodo. */
    public function inscribir(int $alumnoId, int $planId, string $desde, ?string $hasta, User $usuario): array
    {
        $plan = DB::table('com_planes')->where('id', $planId)->where('activo', true)->first();
        abort_if(! $plan, 404, 'El plan no existe.');
        abort_if(! DB::table('alumnos')->where('id', $alumnoId)->whereNull('eliminado_at')->exists(), 404);
        if (DB::table('com_inscripciones')->where('alumno_id', $alumnoId)->where(fn ($q) => $q->whereNull('hasta')->orWhere('hasta', '>=', $desde))->where('desde', '<=', $hasta ?? '9999-12-31')->exists()) {
            throw ValidationException::withMessages(['alumno_id' => ['El alumno ya está inscrito en el comedor en esas fechas.']]);
        }
        $fin = $hasta ?? Carbon::parse($desde)->endOfYear()->month(12)->endOfMonth()->toDateString();

        return DB::transaction(function () use ($alumnoId, $plan, $desde, $hasta, $fin) {
            $id = (int) DB::table('com_inscripciones')->insertGetId(['institucion_id' => ContextoInstitucion::requerido(), 'alumno_id' => $alumnoId, 'com_plan_id' => $plan->id, 'desde' => $desde, 'hasta' => $hasta]);
            $cargos = 0;
            if ($plan->modalidad === 'mensual' && $plan->concepto_cobro_id) {
                $c = DB::table('conceptos_cobro')->find($plan->concepto_cobro_id);
                for ($m = Carbon::parse($desde)->startOfMonth(); $m->lessThanOrEqualTo(Carbon::parse($fin)); $m->addMonth()) {
                    $this->cargo($alumnoId, $c, 'Comedor '.$m->locale('es')->isoFormat('MMMM YYYY'), $m->copy()->startOfMonth()->toDateString(), $m->copy()->day(min(15, $m->daysInMonth))->toDateString());
                    $cargos++;
                }
            }
            $this->auditoria->registrar('comedor_inscripcion', 'com_inscripciones', $id, null, ['alumno_id' => $alumnoId, 'plan' => $plan->nombre, 'cargos' => $cargos]);

            return ['id' => $id, 'cargos' => $cargos];
        });
    }

    public function terminarInscripcion(int $id, string $hasta): void
    {
        abort_unless(DB::table('com_inscripciones')->where('id', $id)->update(['hasta' => $hasta]), 404);
    }

    // ───────────── Pase y consumo (EDUN-170 / 171)

    /** Inscritos de hoy con su estado (lista rápida). */
    public function listaDelDia(?string $fecha = null): array
    {
        $dia = $fecha ?? now()->toDateString();
        $servidos = DB::table('com_consumos')->where('fecha', $dia)->pluck('hora', 'alumno_id');
        $restriccion = $this->conRestriccion();

        return DB::table('com_inscripciones as i')->join('alumnos as a', 'a.id', '=', 'i.alumno_id')->join('com_planes as p', 'p.id', '=', 'i.com_plan_id')
            ->where('i.desde', '<=', $dia)->where(fn ($q) => $q->whereNull('i.hasta')->orWhere('i.hasta', '>=', $dia))->orderBy('a.apellido_paterno')
            ->get(['a.id', DB::raw("a.apellido_paterno || ', ' || a.nombres as alumno"), 'p.modalidad'])
            ->map(fn ($a) => ['alumno_id' => (int) $a->id, 'alumno' => $a->alumno, 'modalidad' => $a->modalidad, 'servido' => isset($servidos[$a->id]),
                'hora' => isset($servidos[$a->id]) ? substr((string) $servidos[$a->id], 0, 5) : null, 'dieta_especial' => in_array((int) $a->id, $restriccion, true)])->all();
    }

    /**
     * Registra el servido: por QR del carné (o DNI), por la lista o manual.
     *
     * @return array{ok: bool, alumno: ?string, alerta: ?string, dieta_especial: bool}
     */
    public function servir(?int $alumnoId, ?string $codigo, string $origen, User $usuario): array
    {
        if (! $alumnoId && $codigo) {
            $a = app(Carnes::class)->buscar($codigo);
            if (! $a) {
                return ['ok' => false, 'alumno' => null, 'alerta' => 'Carné no reconocido.', 'dieta_especial' => false];
            }
            $alumnoId = (int) $a['id'];
        }
        abort_if(! $alumnoId, 422, 'Indica el alumno.');
        $hoy = now()->toDateString();
        $nombre = (string) DB::table('alumnos')->where('id', $alumnoId)->value(DB::raw("apellido_paterno || ', ' || nombres"));
        $insc = DB::table('com_inscripciones as i')->join('com_planes as p', 'p.id', '=', 'i.com_plan_id')->where('i.alumno_id', $alumnoId)->where('i.desde', '<=', $hoy)
            ->where(fn ($q) => $q->whereNull('i.hasta')->orWhere('i.hasta', '>=', $hoy))->first(['i.id', 'p.modalidad', 'p.concepto_cobro_id']);
        $dieta = in_array($alumnoId, $this->conRestriccion(), true);
        if (! $insc) {
            return ['ok' => false, 'alumno' => $nombre, 'alerta' => 'No está inscrito en el comedor.', 'dieta_especial' => $dieta];
        }
        if (DB::table('com_consumos')->where('alumno_id', $alumnoId)->where('fecha', $hoy)->exists()) {
            return ['ok' => false, 'alumno' => $nombre, 'alerta' => 'Ya comió hoy.', 'dieta_especial' => $dieta];
        }
        DB::transaction(function () use ($alumnoId, $hoy, $insc, $origen, $usuario) {
            $cargo = null;
            if ($insc->modalidad === 'diario' && $insc->concepto_cobro_id) {
                $cargo = $this->cargo($alumnoId, DB::table('conceptos_cobro')->find($insc->concepto_cobro_id), 'Comedor '.now()->format('d/m/Y'), $hoy, now()->addDays(7)->toDateString());
            }
            DB::table('com_consumos')->insert(['institucion_id' => ContextoInstitucion::requerido(), 'alumno_id' => $alumnoId, 'fecha' => $hoy, 'hora' => now()->format('H:i:s'),
                'com_menu_id' => DB::table('com_menus')->where('fecha', $hoy)->value('id'), 'cargo_id' => $cargo, 'origen' => $origen, 'registrado_por' => $usuario->id]);
        });

        return ['ok' => true, 'alumno' => $nombre, 'alerta' => $dieta ? 'Dieta especial: sirve el reemplazo indicado por nutrición.' : null, 'dieta_especial' => $dieta];
    }

    // ───────────── Restricciones (EDUN-174)

    public function restricciones(): array
    {
        return Salud::conAcceso(fn () => DB::table('salud.restricciones_alimentarias as r')->join('alumnos as a', 'a.id', '=', 'r.alumno_id')->where('r.vigente', true)->orderBy('a.apellido_paterno')
            ->get(['r.id', 'r.alumno_id', 'r.tipo', 'r.detalle', 'r.reemplazo', DB::raw("a.apellido_paterno || ', ' || a.nombres as alumno")])->map(fn ($r) => (array) $r)->all());
    }

    public function registrarRestriccion(int $alumnoId, string $tipo, string $detalle, ?string $reemplazo, User $usuario): array
    {
        abort_if(! DB::table('alumnos')->where('id', $alumnoId)->exists(), 404);
        $id = Salud::conAcceso(fn () => (int) DB::table('salud.restricciones_alimentarias')->insertGetId(['institucion_id' => ContextoInstitucion::requerido(), 'alumno_id' => $alumnoId,
            'tipo' => $tipo, 'detalle' => trim($detalle), 'reemplazo' => $reemplazo ? trim($reemplazo) : null, 'registrado_por' => $usuario->id]));
        $this->auditoria->registrar('restriccion_alimentaria', 'salud.restricciones_alimentarias', $id, null, ['alumno_id' => $alumnoId, 'tipo' => $tipo]);

        return collect($this->restricciones())->firstWhere('id', $id);
    }

    public function retirarRestriccion(int $id): void
    {
        $n = Salud::conAcceso(fn () => DB::table('salud.restricciones_alimentarias')->where('id', $id)->update(['vigente' => false]));
        abort_if(! $n, 404);
    }

    // ───────────── Seguimiento nutricional (EDUN-175)

    /** Registra peso y talla solo si la familia autorizó la campaña. */
    public function medir(int $alumnoId, int $campanaId, float $peso, float $talla, User $usuario): array
    {
        $campana = Salud::conAcceso(fn () => DB::table('salud.campanas')->find($campanaId));
        abort_if(! $campana, 404, 'La campaña no existe.');
        if (! DB::table('autorizacion_firmas')->where('autorizacion_id', $campana->autorizacion_id)->where('alumno_id', $alumnoId)->where('acepta', true)->exists()) {
            throw ValidationException::withMessages(['alumno_id' => ['La familia no autorizó esta campaña: no se registra peso ni talla.']]);
        }
        Salud::conAcceso(fn () => DB::table('salud.mediciones_nutricionales')->insert(['institucion_id' => ContextoInstitucion::requerido(), 'alumno_id' => $alumnoId, 'fecha' => $campana->fecha,
            'peso_kg' => $peso, 'talla_cm' => $talla, 'campana_id' => $campanaId, 'registrado_por' => $usuario->id]));

        return ['imc' => self::imc($peso, $talla)];
    }

    /** Indicadores por nivel: mediciones, IMC promedio y rangos (referencia general; lo interpreta nutrición). */
    public function indicadores(): array
    {
        $filas = Salud::conAcceso(fn () => DB::table('salud.mediciones_nutricionales as m')
            ->join('matriculas as mt', fn ($j) => $j->on('mt.alumno_id', '=', 'm.alumno_id')->where('mt.estado', '=', 'matriculado'))
            ->join('secciones as s', 's.id', '=', 'mt.seccion_id')->join('grados as g', 'g.id', '=', 's.grado_id')
            ->get(['g.nivel', 'm.peso_kg', 'm.talla_cm']));

        return collect(self::NIVELES)->map(function ($nivel) use ($filas) {
            $imc = $filas->where('nivel', $nivel)->map(fn ($f) => self::imc((float) $f->peso_kg, (float) $f->talla_cm));

            return ['nivel' => $nivel, 'mediciones' => $imc->count(), 'imc_promedio' => $imc->isNotEmpty() ? round($imc->avg(), 1) : null,
                'bajo' => $imc->filter(fn ($v) => $v < 14)->count(), 'adecuado' => $imc->filter(fn ($v) => $v >= 14 && $v < 25)->count(), 'alto' => $imc->filter(fn ($v) => $v >= 25)->count()];
        })->all();
    }

    /** Orientación a la familia con el resultado de su hijo. */
    public function orientar(int $alumnoId, string $mensaje): int
    {
        $familia = DB::table('alumno_apoderado as aa')->join('apoderados as p', 'p.id', '=', 'aa.apoderado_id')->where('aa.alumno_id', $alumnoId)->whereNotNull('p.user_id')->pluck('p.user_id')->all();

        return $this->notificador->enviar($familia, ['app'], 'comunicados', 'Orientación nutricional', trim($mensaje), ['pantalla' => 'comedor'])['app'];
    }

    public static function imc(float $peso, float $tallaCm): float
    {
        return $tallaCm > 0 ? round($peso / (($tallaCm / 100) ** 2), 1) : 0.0;
    }

    // ───────────── Familia

    public function deFamilia(array $hijos): array
    {
        return [
            'menu' => $this->semana(now()->toDateString(), true),
            'inscritos' => DB::table('com_inscripciones as i')->join('alumnos as a', 'a.id', '=', 'i.alumno_id')->join('com_planes as p', 'p.id', '=', 'i.com_plan_id')
                ->whereIn('i.alumno_id', $hijos ?: [0])->where(fn ($q) => $q->whereNull('i.hasta')->orWhere('i.hasta', '>=', now()->toDateString()))
                ->get(['a.nombres as alumno', 'p.nombre as plan', 'p.modalidad'])->map(fn ($x) => (array) $x)->all(),
            'consumos' => DB::table('com_consumos as c')->join('alumnos as a', 'a.id', '=', 'c.alumno_id')->whereIn('c.alumno_id', $hijos ?: [0])->where('c.fecha', '>=', now()->subDays(30)->toDateString())
                ->orderByDesc('c.fecha')->get(['c.fecha', 'c.hora', 'a.nombres as alumno'])->map(fn ($x) => (array) $x)->all(),
        ];
    }

    /** @return list<int> alumnos con restricción alimentaria vigente (sin el detalle) */
    private function conRestriccion(): array
    {
        return Salud::conAcceso(fn () => DB::table('salud.restricciones_alimentarias')->where('vigente', true)->pluck('alumno_id')->map(fn ($i) => (int) $i)->unique()->values()->all());
    }

    private function familiasInscritas(): array
    {
        $alumnos = DB::table('com_inscripciones')->where(fn ($q) => $q->whereNull('hasta')->orWhere('hasta', '>=', now()->toDateString()))->pluck('alumno_id');

        return DB::table('alumno_apoderado as aa')->join('apoderados as p', 'p.id', '=', 'aa.apoderado_id')->whereIn('aa.alumno_id', $alumnos)->whereNotNull('p.user_id')->pluck('p.user_id')->unique()->all();
    }

    private function cargo(int $alumnoId, object $concepto, string $descripcion, string $emision, string $vence): int
    {
        $id = (int) DB::table('cargos')->insertGetId([
            'institucion_id' => ContextoInstitucion::requerido(), 'alumno_id' => $alumnoId, 'concepto_cobro_id' => $concepto->id, 'descripcion' => mb_substr($descripcion, 0, 200),
            'matricula_id' => DB::table('matriculas')->where('alumno_id', $alumnoId)->where('estado', 'matriculado')->orderByDesc('anio_escolar_id')->value('id'),
            'emision' => $emision, 'vencimiento' => max($vence, $emision), 'monto' => $concepto->monto,
        ]);
        DB::table('cargos')->where('id', $id)->update(['codigo_recaudacion' => GeneradorCargos::codigo($id)]);

        return $id;
    }
}
