<?php

namespace App\Modulos\LAB\Services;

use App\Models\User;
use App\Modulos\COM\Services\Notificador;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Models\Archivo;
use App\Modulos\SEG\Services\AlmacenArchivos;
use App\Modulos\SEG\Services\Auditoria;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-191 a 194 · Laboratorios y talleres.
 *
 * - Reservas: espacio, fecha y horario (sin cruces con otra reserva del mismo espacio), sección y
 *   materiales que necesita; avisa al jefe de laboratorio.
 * - Equipos: préstamo por código a un docente (y su sección), no se presta dos veces y al devolver
 *   se revisa (vuelve disponible o pasa a reparación).
 * - Materiales y reactivos: stock y vencimiento; los peligrosos exigen hoja de seguridad y ubicación
 *   con llave, y los vencidos no se entregan. Alerta diaria de stock mínimo y vencimiento a 30 días.
 * - Incidencias: el docente las reporta (con el equipo, si aplica) y se siguen hasta cerrarlas.
 */
class Laboratorios
{
    public const DIAS_AVISO = 30;

    public function __construct(private Notificador $notificador, private Auditoria $auditoria, private AlmacenArchivos $almacen) {}

    // ───────────── Espacios y reservas (EDUN-191)

    public function espacios(): array
    {
        return DB::table('lab_espacios')->orderBy('nombre')->get()->map(fn ($e) => (array) $e)->all();
    }

    public function guardarEspacio(?int $id, array $d): array
    {
        $fila = ['nombre' => trim($d['nombre']), 'tipo' => $d['tipo'], 'capacidad' => $d['capacidad'] ?? null, 'activo' => (bool) ($d['activo'] ?? true)];
        if ($id) {
            abort_unless(DB::table('lab_espacios')->where('id', $id)->update($fila) || DB::table('lab_espacios')->where('id', $id)->exists(), 404);
        } else {
            $id = (int) DB::table('lab_espacios')->insertGetId(['institucion_id' => ContextoInstitucion::requerido()] + $fila);
        }

        return (array) DB::table('lab_espacios')->find($id);
    }

    public function reservas(string $desde, string $hasta, ?int $espacioId = null): array
    {
        return DB::table('lab_reservas as r')->join('lab_espacios as e', 'e.id', '=', 'r.lab_espacio_id')->join('personal as p', 'p.id', '=', 'r.docente_id')
            ->leftJoin('secciones as s', 's.id', '=', 'r.seccion_id')->leftJoin('grados as g', 'g.id', '=', 's.grado_id')
            ->whereBetween('r.fecha', [$desde, $hasta])->when($espacioId, fn ($q, $v) => $q->where('r.lab_espacio_id', $v))->orderBy('r.fecha')->orderBy('r.hora_inicio')
            ->get(['r.id', 'r.fecha', 'r.hora_inicio', 'r.hora_fin', 'r.materiales', 'r.estado', 'r.lab_espacio_id', 'e.nombre as espacio', 'r.docente_id',
                DB::raw("p.apellidos || ', ' || p.nombres as docente"), DB::raw("g.nombre || ' ' || s.nombre as seccion")])
            ->map(fn ($r) => array_merge((array) $r, ['hora_inicio' => substr((string) $r->hora_inicio, 0, 5), 'hora_fin' => substr((string) $r->hora_fin, 0, 5)]))->all();
    }

    public function reservar(array $d, User $usuario): array
    {
        $docente = DB::table('personal')->where('user_id', $usuario->id)->value('id');
        if (! $docente) {
            throw ValidationException::withMessages(['docente' => ['Tu usuario no está en el personal del colegio.']]);
        }
        abort_unless(DB::table('lab_espacios')->where('id', $d['lab_espacio_id'])->where('activo', true)->exists(), 404, 'El espacio no existe.');
        if ($d['hora_fin'] <= $d['hora_inicio']) {
            throw ValidationException::withMessages(['hora_fin' => ['La hora de fin debe ser después del inicio.']]);
        }
        if ($d['fecha'] < now()->toDateString()) {
            throw ValidationException::withMessages(['fecha' => ['No se reserva en una fecha pasada.']]);
        }

        return DB::transaction(function () use ($d, $docente, $usuario) {
            DB::select('select pg_advisory_xact_lock(?)', [crc32('lab-'.$d['lab_espacio_id'].'-'.$d['fecha'])]);
            $cruce = DB::table('lab_reservas')->where('lab_espacio_id', $d['lab_espacio_id'])->where('fecha', $d['fecha'])->where('estado', '!=', 'cancelada')
                ->where('hora_inicio', '<', $d['hora_fin'])->where('hora_fin', '>', $d['hora_inicio'])->first();
            if ($cruce) {
                throw ValidationException::withMessages(['hora_inicio' => ['El espacio ya está reservado de '.substr((string) $cruce->hora_inicio, 0, 5).' a '.substr((string) $cruce->hora_fin, 0, 5).'.']]);
            }
            $id = (int) DB::table('lab_reservas')->insertGetId(['institucion_id' => ContextoInstitucion::requerido(), 'lab_espacio_id' => $d['lab_espacio_id'], 'fecha' => $d['fecha'],
                'hora_inicio' => $d['hora_inicio'], 'hora_fin' => $d['hora_fin'], 'seccion_id' => $d['seccion_id'] ?? null, 'docente_id' => $docente, 'materiales' => $d['materiales'] ?? null]);
            $espacio = DB::table('lab_espacios')->where('id', $d['lab_espacio_id'])->value('nombre');
            $this->notificador->enviar($this->jefes(), ['app'], 'comunicados', "Reserva: {$espacio}", Carbon::parse($d['fecha'])->format('d/m')." de {$d['hora_inicio']} a {$d['hora_fin']} · {$usuario->nombres} {$usuario->apellidos}"
                .(! empty($d['materiales']) ? '. Necesita: '.mb_substr($d['materiales'], 0, 80) : '.'), ['pantalla' => 'inicio']);

            return collect($this->reservas($d['fecha'], $d['fecha']))->firstWhere('id', $id);
        });
    }

    public function moverReserva(int $id, string $estado, User $usuario, bool $gestiona): void
    {
        $r = DB::table('lab_reservas')->find($id);
        abort_if(! $r, 404);
        $docente = (int) DB::table('personal')->where('user_id', $usuario->id)->value('id');
        abort_unless($gestiona || ($estado === 'cancelada' && (int) $r->docente_id === $docente), 403);
        if ($r->estado !== 'reservada') {
            throw ValidationException::withMessages(['estado' => ['La reserva ya está '.$r->estado.'.']]);
        }
        DB::table('lab_reservas')->where('id', $id)->update(['estado' => $estado]);
    }

    // ───────────── Equipos (EDUN-192)

    public function equipos(?string $estado): array
    {
        return DB::table('lab_equipos as e')->leftJoin('lab_prestamos as p', fn ($j) => $j->on('p.lab_equipo_id', '=', 'e.id')->whereNull('p.devuelto_at'))
            ->leftJoin('personal as pe', 'pe.id', '=', 'p.prestado_a')->when($estado, fn ($q, $v) => $q->where('e.estado', $v))->orderBy('e.codigo')
            ->get(['e.id', 'e.codigo', 'e.tipo', 'e.descripcion', 'e.estado', 'p.id as prestamo_id', 'p.hasta', DB::raw("pe.apellidos || ', ' || pe.nombres as prestado_a")])
            ->map(fn ($e) => array_merge((array) $e, ['vencido' => $e->hasta && Carbon::parse($e->hasta)->isPast()]))->all();
    }

    public function guardarEquipo(?int $id, array $d): array
    {
        $codigo = strtoupper(trim($d['codigo']));
        if (DB::table('lab_equipos')->where('codigo', $codigo)->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
            throw ValidationException::withMessages(['codigo' => ['Ya hay un equipo con ese código.']]);
        }
        $fila = ['codigo' => $codigo, 'tipo' => trim($d['tipo']), 'descripcion' => $d['descripcion'] ?? null] + (isset($d['estado']) ? ['estado' => $d['estado']] : []);
        if ($id) {
            $e = DB::table('lab_equipos')->find($id);
            abort_if(! $e, 404);
            if (isset($d['estado']) && $e->estado === 'prestado' && $d['estado'] !== 'prestado') {
                throw ValidationException::withMessages(['estado' => ['El equipo está prestado: regístralo como devuelto.']]);
            }
            DB::table('lab_equipos')->where('id', $id)->update($fila);
        } else {
            $id = (int) DB::table('lab_equipos')->insertGetId(['institucion_id' => ContextoInstitucion::requerido()] + $fila);
        }

        return collect($this->equipos(null))->firstWhere('id', $id);
    }

    public function prestar(string $codigo, int $personalId, ?int $seccionId, string $hasta, int $usuarioId): array
    {
        return DB::transaction(function () use ($codigo, $personalId, $seccionId, $hasta, $usuarioId) {
            $e = DB::table('lab_equipos')->where('codigo', strtoupper(trim($codigo)))->lockForUpdate()->first();
            if (! $e) {
                throw ValidationException::withMessages(['codigo' => ['No hay un equipo con ese código.']]);
            }
            if ($e->estado !== 'disponible') {
                throw ValidationException::withMessages(['codigo' => ["El equipo no está disponible ({$e->estado})."]]);
            }
            abort_unless(DB::table('personal')->where('id', $personalId)->exists(), 404, 'La persona no está en el personal.');
            $id = (int) DB::table('lab_prestamos')->insertGetId(['institucion_id' => ContextoInstitucion::requerido(), 'lab_equipo_id' => $e->id, 'prestado_a' => $personalId,
                'seccion_id' => $seccionId, 'hasta' => Carbon::parse($hasta), 'registrado_por' => $usuarioId]);
            DB::table('lab_equipos')->where('id', $e->id)->update(['estado' => 'prestado']);

            return ['id' => $id, 'equipo' => $e->codigo];
        });
    }

    /** Devuelve con la revisión: «bueno» lo deja disponible; «dañado» lo pasa a reparación y abre una incidencia. */
    public function devolver(string $codigo, string $resultado, ?string $observacion, int $usuarioId): array
    {
        return DB::transaction(function () use ($codigo, $resultado, $observacion, $usuarioId) {
            $e = DB::table('lab_equipos')->where('codigo', strtoupper(trim($codigo)))->lockForUpdate()->first();
            abort_if(! $e, 404, 'No hay un equipo con ese código.');
            $p = DB::table('lab_prestamos')->where('lab_equipo_id', $e->id)->whereNull('devuelto_at')->first();
            if (! $p) {
                throw ValidationException::withMessages(['codigo' => ['Ese equipo no está prestado.']]);
            }
            if ($resultado === 'danado' && trim((string) $observacion) === '') {
                throw ValidationException::withMessages(['observacion' => ['Describe el daño encontrado al revisarlo.']]);
            }
            DB::table('lab_prestamos')->where('id', $p->id)->update(['devuelto_at' => now(), 'revision' => $resultado === 'danado' ? 'Dañado: '.trim((string) $observacion) : 'Revisado sin observaciones', 'observacion' => $observacion]);
            DB::table('lab_equipos')->where('id', $e->id)->update(['estado' => $resultado === 'danado' ? 'reparacion' : 'disponible']);
            if ($resultado === 'danado') {
                DB::table('lab_incidencias')->insert(['institucion_id' => ContextoInstitucion::requerido(), 'fecha' => now()->toDateString(), 'detalle' => 'Devuelto dañado: '.trim((string) $observacion),
                    'lab_equipo_id' => $e->id, 'reportado_por' => $usuarioId]);
            }

            return ['estado' => $resultado === 'danado' ? 'reparacion' : 'disponible'];
        });
    }

    // ───────────── Materiales y reactivos (EDUN-193)

    public function materiales(): array
    {
        $limite = now()->addDays(self::DIAS_AVISO)->toDateString();

        return DB::table('lab_materiales')->orderBy('nombre')->get()->map(fn ($m) => array_merge((array) $m, [
            'stock' => (float) $m->stock, 'stock_minimo' => (float) $m->stock_minimo, 'tiene_hoja' => (bool) $m->hoja_seguridad_archivo_id,
            'vencido' => $m->vence && $m->vence < now()->toDateString(), 'por_vencer' => $m->vence && $m->vence >= now()->toDateString() && $m->vence <= $limite,
            'bajo_minimo' => (float) $m->stock <= (float) $m->stock_minimo,
        ]))->all();
    }

    public function guardarMaterial(?int $id, array $d, ?UploadedFile $hoja, int $usuarioId): array
    {
        $fila = array_intersect_key($d, array_flip(['nombre', 'unidad', 'stock', 'stock_minimo', 'vence', 'ubicacion', 'peligroso', 'con_llave']));
        if ($hoja) {
            $fila['hoja_seguridad_archivo_id'] = $this->almacen->guardar($hoja, $usuarioId)->id;
        }
        $actual = $id ? DB::table('lab_materiales')->find($id) : null;
        abort_if($id && ! $actual, 404);
        $peligroso = (bool) ($fila['peligroso'] ?? $actual?->peligroso ?? false);
        if ($peligroso) {
            if (! ($fila['hoja_seguridad_archivo_id'] ?? $actual?->hoja_seguridad_archivo_id)) {
                throw ValidationException::withMessages(['hoja_seguridad' => ['Un reactivo peligroso necesita su hoja de seguridad.']]);
            }
            if (! (bool) ($fila['con_llave'] ?? $actual?->con_llave ?? false) || trim((string) ($fila['ubicacion'] ?? $actual?->ubicacion ?? '')) === '') {
                throw ValidationException::withMessages(['ubicacion' => ['Un reactivo peligroso se guarda en una ubicación con llave: indícala.']]);
            }
        }
        if ($id) {
            DB::table('lab_materiales')->where('id', $id)->update($fila);
        } else {
            $id = (int) DB::table('lab_materiales')->insertGetId(['institucion_id' => ContextoInstitucion::requerido()] + $fila);
        }

        return collect($this->materiales())->firstWhere('id', $id);
    }

    /** Entrega (consumo) para una práctica: los vencidos no se entregan y el stock no queda negativo. */
    public function entregarMaterial(int $id, float $cantidad, int $usuarioId): array
    {
        return DB::transaction(function () use ($id, $cantidad, $usuarioId) {
            $m = DB::table('lab_materiales')->where('id', $id)->lockForUpdate()->first();
            abort_if(! $m, 404);
            if ($m->vence && $m->vence < now()->toDateString()) {
                throw ValidationException::withMessages(['material' => ['El material está vencido: no se entrega. Dalo de baja según su hoja de seguridad.']]);
            }
            if ((float) $m->stock < $cantidad) {
                throw ValidationException::withMessages(['cantidad' => ["Solo quedan {$m->stock} {$m->unidad}."]]);
            }
            DB::table('lab_materiales')->where('id', $id)->update(['stock' => (float) $m->stock - $cantidad]);
            $this->auditoria->registrar('lab_material_entregado', 'lab_materiales', $id, ['stock' => (float) $m->stock], ['stock' => (float) $m->stock - $cantidad, 'por' => $usuarioId]);

            return ['stock' => (float) $m->stock - $cantidad];
        });
    }

    public function urlHoja(int $id): string
    {
        $a = DB::table('lab_materiales')->where('id', $id)->value('hoja_seguridad_archivo_id');
        abort_if(! $a, 404);

        return $this->almacen->urlTemporal(Archivo::query()->findOrFail($a));
    }

    /** Alerta diaria al jefe de laboratorio (lab:alertas). */
    public function alertas(): int
    {
        $m = collect($this->materiales());
        $bajo = $m->where('bajo_minimo', true)->pluck('nombre');
        $vence = $m->filter(fn ($x) => $x['por_vencer'] || $x['vencido'])->pluck('nombre');
        if ($bajo->isEmpty() && $vence->isEmpty()) {
            return 0;
        }
        $texto = trim(($bajo->isNotEmpty() ? 'Stock bajo: '.$bajo->take(5)->implode(', ').'. ' : '').($vence->isNotEmpty() ? 'Vencen o vencidos: '.$vence->take(5)->implode(', ').'.' : ''));
        $this->notificador->enviar($this->jefes(), ['app'], 'comunicados', 'Laboratorio: materiales por revisar', $texto, ['pantalla' => 'inicio']);

        return $bajo->count() + $vence->count();
    }

    // ───────────── Incidencias (EDUN-194)

    public function incidencias(?string $estado): array
    {
        return DB::table('lab_incidencias as i')->leftJoin('lab_equipos as e', 'e.id', '=', 'i.lab_equipo_id')->join('users as u', 'u.id', '=', 'i.reportado_por')
            ->when($estado, fn ($q, $v) => $q->where('i.estado', $v))->orderByRaw("i.estado = 'cerrada'")->orderByDesc('i.id')->limit(200)
            ->get(['i.id', 'i.fecha', 'i.detalle', 'i.estado', 'i.respuesta', 'i.cerrada_at', 'e.codigo as equipo', DB::raw("u.nombres || ' ' || u.apellidos as reportado_por")])
            ->map(fn ($i) => (array) $i)->all();
    }

    public function reportar(string $detalle, ?string $codigoEquipo, User $usuario): array
    {
        $equipo = $codigoEquipo ? DB::table('lab_equipos')->where('codigo', strtoupper(trim($codigoEquipo)))->value('id') : null;
        if ($codigoEquipo && ! $equipo) {
            throw ValidationException::withMessages(['equipo' => ['No hay un equipo con ese código.']]);
        }
        $id = (int) DB::table('lab_incidencias')->insertGetId(['institucion_id' => ContextoInstitucion::requerido(), 'fecha' => now()->toDateString(), 'detalle' => trim($detalle),
            'lab_equipo_id' => $equipo, 'reportado_por' => $usuario->id]);
        $this->notificador->enviar($this->jefes(), ['app'], 'comunicados', 'Incidencia en laboratorio', mb_substr(trim($detalle), 0, 140), ['pantalla' => 'inicio']);

        return collect($this->incidencias(null))->firstWhere('id', $id);
    }

    public function atender(int $id, string $estado, ?string $respuesta, User $usuario): array
    {
        $i = DB::table('lab_incidencias')->find($id);
        abort_if(! $i, 404);
        if ($i->estado === 'cerrada') {
            throw ValidationException::withMessages(['estado' => ['La incidencia ya está cerrada.']]);
        }
        if ($estado === 'cerrada' && trim((string) $respuesta) === '') {
            throw ValidationException::withMessages(['respuesta' => ['Indica cómo se resolvió antes de cerrarla.']]);
        }
        DB::table('lab_incidencias')->where('id', $id)->update(['estado' => $estado, 'respuesta' => $respuesta ?? $i->respuesta, 'atendido_por' => $usuario->id] + ($estado === 'cerrada' ? ['cerrada_at' => now()] : []));
        if ($estado === 'cerrada') {
            $this->notificador->enviar([(int) $i->reportado_por], ['app'], 'comunicados', 'Incidencia de laboratorio cerrada', mb_substr(trim((string) $respuesta), 0, 140), ['pantalla' => 'inicio']);
        }

        return collect($this->incidencias(null))->firstWhere('id', $id);
    }

    /** @return list<int> usuarios con el rol de laboratorio */
    private function jefes(): array
    {
        return DB::table('membresias as m')->join('roles as r', 'r.id', '=', 'm.rol_id')->where('r.codigo', 'laboratorio')->pluck('m.user_id')->unique()->all();
    }
}
