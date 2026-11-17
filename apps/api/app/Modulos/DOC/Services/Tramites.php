<?php

namespace App\Modulos\DOC\Services;

use App\Models\User;
use App\Modulos\COM\Services\Notificador;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\PAG\Services\Cobros;
use App\Modulos\PAG\Services\GeneradorCargos;
use App\Modulos\SEG\Models\Archivo;
use App\Modulos\SEG\Services\AlmacenArchivos;
use App\Modulos\SEG\Services\Auditoria;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * EDUN-113 a 116 · Trámites en línea.
 *
 * - Catálogo (DOC-H01): requisitos, costo (concepto de cobro), plazo en días, firmante, plantilla
 *   y si exige estar sin deuda (constancia de no adeudo).
 * - La familia lo pide desde la app o la web (DOC-H02): elige tipo y alumno, adjunta requisitos y,
 *   si tiene costo, se genera el cargo y lo paga como cualquier otro (Mis pagos).
 * - Bandeja (DOC-H03): observar con comentario (la familia responde y vuelve a revisión), pasar a
 *   firma y semáforo de plazo. Un trámite con costo solo pasa a firma con el cargo pagado; uno
 *   «sin deuda» valida que el alumno no tenga cargos vencidos.
 * - Firma por lote (DOC-H04): genera el PDF con QR y la familia lo recibe en la app.
 *
 * Estados: solicitado → observado ⇄ en_revision → por_firmar → entregado | rechazado.
 */
class Tramites
{
    public const ESTADOS = ['solicitado', 'observado', 'en_revision', 'por_firmar', 'entregado', 'rechazado'];

    public function __construct(
        private Emision $emision, private AlmacenArchivos $almacen, private Auditoria $auditoria, private Notificador $notificador, private Cobros $cobros,
    ) {}

    // ───────────────────────── Catálogo (DOC-H01)

    public function catalogo(bool $soloActivos = false): array
    {
        $tipos = DB::table('tramite_tipos as t')->leftJoin('conceptos_cobro as c', 'c.id', '=', 't.concepto_cobro_id')
            ->leftJoin('plantillas_documento as p', 'p.id', '=', 't.plantilla_documento_id')
            ->when($soloActivos, fn ($q) => $q->where('t.activo', true))->orderBy('t.nombre')
            ->get(['t.id', 't.nombre', 't.descripcion', 't.concepto_cobro_id', 't.plazo_dias', 't.requiere_sin_deuda', 't.plantilla_documento_id', 't.firma_cargo', 't.activo',
                'c.nombre as concepto', 'c.monto as costo', 'p.nombre as plantilla']);
        $requisitos = DB::table('tramite_requisitos')->whereIn('tramite_tipo_id', $tipos->pluck('id'))->orderBy('id')->get()->groupBy('tramite_tipo_id');

        return $tipos->map(fn ($t) => array_merge((array) $t, [
            'costo' => $t->costo !== null ? (float) $t->costo : 0.0,
            'requisitos' => collect($requisitos[$t->id] ?? [])->map(fn ($r) => ['id' => (int) $r->id, 'descripcion' => $r->descripcion, 'obligatorio' => (bool) $r->obligatorio])->all(),
        ]))->all();
    }

    /** Conceptos de cobro que puede usar un trámite. */
    public function conceptos(): array
    {
        return DB::table('conceptos_cobro')->where('activo', true)->whereIn('tipo', ['tramite', 'servicio', 'otro'])->orderBy('nombre')
            ->get(['id', 'nombre', 'monto'])->map(fn ($c) => ['id' => (int) $c->id, 'nombre' => $c->nombre, 'monto' => (float) $c->monto])->all();
    }

    /**
     * @param  array{nombre: string, descripcion?: string|null, concepto_cobro_id?: int|null, plazo_dias: int, requiere_sin_deuda?: bool, plantilla_documento_id?: int|null, firma_cargo?: string|null, activo?: bool, requisitos?: list<array{id?: int|null, descripcion: string, obligatorio?: bool}>}  $d
     */
    public function guardarTipo(?int $id, array $d): array
    {
        if (! empty($d['concepto_cobro_id']) && ! DB::table('conceptos_cobro')->where('id', $d['concepto_cobro_id'])->where('activo', true)->exists()) {
            throw ValidationException::withMessages(['concepto_cobro_id' => ['Ese concepto de cobro no existe o está inactivo.']]);
        }
        if (! empty($d['plantilla_documento_id']) && ! DB::table('plantillas_documento')->where('id', $d['plantilla_documento_id'])->where('activa', true)->exists()) {
            throw ValidationException::withMessages(['plantilla_documento_id' => ['Elige la versión vigente de una plantilla.']]);
        }
        $inst = ContextoInstitucion::requerido();
        $fila = [
            'nombre' => trim($d['nombre']), 'descripcion' => $d['descripcion'] ?? null, 'concepto_cobro_id' => $d['concepto_cobro_id'] ?? null,
            'plazo_dias' => (int) $d['plazo_dias'], 'requiere_sin_deuda' => (bool) ($d['requiere_sin_deuda'] ?? false),
            'plantilla_documento_id' => $d['plantilla_documento_id'] ?? null, 'firma_cargo' => $d['firma_cargo'] ?? 'director', 'activo' => (bool) ($d['activo'] ?? true),
        ];

        return DB::transaction(function () use ($id, $fila, $d, $inst) {
            if ($id) {
                abort_if(! DB::table('tramite_tipos')->where('id', $id)->exists(), 404);
                DB::table('tramite_tipos')->where('id', $id)->update($fila);
            } else {
                $id = (int) DB::table('tramite_tipos')->insertGetId(['institucion_id' => $inst] + $fila);
            }
            if (array_key_exists('requisitos', $d)) {
                $quedan = [];
                foreach ($d['requisitos'] ?? [] as $r) {
                    $datos = ['descripcion' => trim($r['descripcion']), 'obligatorio' => (bool) ($r['obligatorio'] ?? true)];
                    if (! empty($r['id']) && DB::table('tramite_requisitos')->where('id', $r['id'])->where('tramite_tipo_id', $id)->exists()) {
                        DB::table('tramite_requisitos')->where('id', $r['id'])->update($datos);
                        $quedan[] = (int) $r['id'];
                    } else {
                        $quedan[] = (int) DB::table('tramite_requisitos')->insertGetId(['institucion_id' => $inst, 'tramite_tipo_id' => $id] + $datos);
                    }
                }
                // Los requisitos con adjuntos de trámites anteriores no se borran: quedan como opcionales.
                $sobran = DB::table('tramite_requisitos')->where('tramite_tipo_id', $id)->whereNotIn('id', $quedan ?: [0])->pluck('id');
                $usados = DB::table('tramite_adjuntos')->whereIn('tramite_requisito_id', $sobran)->pluck('tramite_requisito_id')->unique();
                DB::table('tramite_requisitos')->whereIn('id', $sobran->diff($usados))->delete();
                DB::table('tramite_requisitos')->whereIn('id', $usados)->update(['obligatorio' => false, 'descripcion' => DB::raw("left('(Ya no se pide) ' || descripcion, 200)")]);
            }
            $this->auditoria->registrar('tramite_tipo_guardado', 'tramite_tipos', $id, null, ['nombre' => $fila['nombre'], 'activo' => $fila['activo']]);

            return collect($this->catalogo())->firstWhere('id', $id);
        });
    }

    // ───────────────────────── Familia (DOC-H02)

    /**
     * @param  array<int, UploadedFile>  $adjuntos  requisito_id => archivo
     */
    public function solicitar(int $tipoId, int $alumnoId, ?string $comentario, array $adjuntos, User $usuario): array
    {
        $tipo = DB::table('tramite_tipos')->where('id', $tipoId)->where('activo', true)->first();
        if (! $tipo) {
            throw ValidationException::withMessages(['tramite_tipo_id' => ['Ese trámite no está disponible.']]);
        }
        $requisitos = DB::table('tramite_requisitos')->where('tramite_tipo_id', $tipoId)->get()->keyBy('id');
        $faltan = $requisitos->filter(fn ($r) => $r->obligatorio && ! isset($adjuntos[$r->id]))->pluck('descripcion');
        if ($faltan->isNotEmpty()) {
            throw ValidationException::withMessages(['adjuntos' => ['Falta adjuntar: '.$faltan->implode(', ').'.']]);
        }
        foreach (array_keys($adjuntos) as $req) {
            if (! $requisitos->has($req)) {
                throw ValidationException::withMessages(['adjuntos' => ['Uno de los archivos no corresponde a un requisito de este trámite.']]);
            }
        }
        if (DB::table('tramites')->where('alumno_id', $alumnoId)->where('tramite_tipo_id', $tipoId)->whereIn('estado', ['solicitado', 'observado', 'en_revision', 'por_firmar'])->exists()) {
            throw ValidationException::withMessages(['tramite_tipo_id' => ['Ya hay un trámite igual en curso para este alumno.']]);
        }
        $inst = ContextoInstitucion::requerido();

        $id = DB::transaction(function () use ($tipo, $alumnoId, $comentario, $adjuntos, $usuario, $inst) {
            $anio = now()->format('y');
            // Numeración correlativa por colegio y año: un candado de la transacción evita números repetidos.
            DB::select('select pg_advisory_xact_lock(?)', [crc32("tramites-{$inst}")]);
            $n = DB::table('tramites')->where('numero', 'like', "T{$anio}-%")->count() + 1;
            $numero = sprintf('T%s-%06d', $anio, $n);
            while (DB::table('tramites')->where('numero', $numero)->exists()) {
                $numero = sprintf('T%s-%06d', $anio, ++$n);
            }
            $cargo = null;
            if ($tipo->concepto_cobro_id) {
                $c = DB::table('conceptos_cobro')->find($tipo->concepto_cobro_id);
                if ($c && (float) $c->monto > 0) {
                    $cargo = (int) DB::table('cargos')->insertGetId([
                        'institucion_id' => $inst, 'alumno_id' => $alumnoId, 'concepto_cobro_id' => $c->id,
                        'matricula_id' => DB::table('matriculas')->where('alumno_id', $alumnoId)->where('estado', 'matriculado')->orderByDesc('anio_escolar_id')->value('id'),
                        'descripcion' => mb_substr("Trámite {$numero}: {$tipo->nombre}", 0, 200), 'emision' => now()->toDateString(),
                        'vencimiento' => now()->addDays(7)->toDateString(), 'monto' => $c->monto,
                    ]);
                    DB::table('cargos')->where('id', $cargo)->update(['codigo_recaudacion' => GeneradorCargos::codigo($cargo)]);
                }
            }
            $id = (int) DB::table('tramites')->insertGetId([
                'institucion_id' => $inst, 'numero' => $numero, 'tramite_tipo_id' => $tipo->id, 'alumno_id' => $alumnoId, 'solicitado_por' => $usuario->id,
                'cargo_id' => $cargo, 'estado' => 'solicitado', 'vence' => $this->vence((int) $tipo->plazo_dias), 'comentario' => $comentario ? mb_substr(trim($comentario), 0, 500) : null,
            ]);
            $this->historial($id, 'solicitado', $comentario, $usuario->id);
            $this->guardarAdjuntos($id, $adjuntos, $usuario->id);
            $this->auditoria->registrar('tramite_solicitado', 'tramites', $id, null, ['numero' => $numero, 'tipo' => $tipo->nombre, 'alumno_id' => $alumnoId]);

            return $id;
        });
        $this->avisarBandeja($id);

        return $this->detalle($id);
    }

    /** La familia responde una observación: comentario y archivos nuevos; vuelve a revisión. */
    public function responder(int $id, ?string $comentario, array $adjuntos, User $usuario, array $hijos): array
    {
        $t = $this->deFamilia($id, $hijos);
        if ($t->estado !== 'observado') {
            throw ValidationException::withMessages(['tramite' => ['Solo se responde un trámite observado.']]);
        }
        if (! trim((string) $comentario) && ! $adjuntos) {
            throw ValidationException::withMessages(['comentario' => ['Escribe una respuesta o adjunta lo que te pidieron.']]);
        }
        $validos = DB::table('tramite_requisitos')->where('tramite_tipo_id', $t->tramite_tipo_id)->pluck('id')->map(fn ($i) => (int) $i)->all();
        foreach (array_keys($adjuntos) as $req) {
            if ($req !== 0 && ! in_array($req, $validos, true)) {
                throw ValidationException::withMessages(['adjuntos' => ['Uno de los archivos no corresponde a un requisito de este trámite.']]);
            }
        }
        DB::transaction(function () use ($id, $comentario, $adjuntos, $usuario) {
            $this->guardarAdjuntos($id, $adjuntos, $usuario->id);
            DB::table('tramites')->where('id', $id)->update(['estado' => 'en_revision']);
            $this->historial($id, 'en_revision', 'Respuesta de la familia: '.trim((string) $comentario), $usuario->id);
        });
        $this->avisarBandeja($id, 'La familia respondió la observación');

        return $this->detalle($id);
    }

    public function deFamiliaLista(array $hijos): array
    {
        return $this->consulta()->whereIn('t.alumno_id', $hijos ?: [0])->orderByDesc('t.id')->limit(50)->get()->map(fn ($t) => $this->fila($t))->all();
    }

    public function documentoFamilia(int $id, array $hijos): string
    {
        $t = $this->deFamilia($id, $hijos);
        abort_if(! $t->documento_emitido_id, 404, 'El documento todavía no está listo.');

        return $this->emision->url((int) $t->documento_emitido_id);
    }

    // ───────────────────────── Bandeja (DOC-H03)

    public function bandeja(?string $estado, ?string $buscar): array
    {
        return $this->consulta()
            ->when($estado === 'activos' || ! $estado, fn ($q) => $q->whereIn('t.estado', ['solicitado', 'observado', 'en_revision', 'por_firmar']))
            ->when($estado && $estado !== 'activos' && $estado !== 'todos', fn ($q) => $q->where('t.estado', $estado))
            ->when($buscar, fn ($q, $b) => $q->where(fn ($w) => $w->where('t.numero', 'ilike', '%'.trim($b).'%')
                ->orWhereRaw("lower(a.apellido_paterno || ' ' || a.nombres) like ?", ['%'.mb_strtolower(trim($b)).'%'])))
            ->orderByRaw("case t.estado when 'por_firmar' then 0 when 'en_revision' then 1 when 'solicitado' then 2 when 'observado' then 3 else 4 end")
            ->orderBy('t.vence')->limit(300)->get()->map(fn ($t) => $this->fila($t))->all();
    }

    public function detalle(int $id): array
    {
        $t = $this->consulta()->where('t.id', $id)->first();
        abort_if(! $t, 404);

        return array_merge($this->fila($t), [
            'historial' => DB::table('tramite_historial as h')->leftJoin('users as u', 'u.id', '=', 'h.user_id')->where('h.tramite_id', $id)->orderBy('h.id')
                ->get(['h.estado', 'h.comentario', 'h.created_at', DB::raw("trim(coalesce(u.nombres, '') || ' ' || coalesce(u.apellidos, '')) as usuario")])->map(fn ($h) => (array) $h)->all(),
            'adjuntos' => DB::table('tramite_adjuntos as x')->join('archivos as f', 'f.id', '=', 'x.archivo_id')->leftJoin('tramite_requisitos as r', 'r.id', '=', 'x.tramite_requisito_id')
                ->where('x.tramite_id', $id)->orderBy('x.id')->get(['x.id', 'f.nombre_original', 'f.mime', 'x.created_at', DB::raw("coalesce(r.descripcion, 'Archivo adicional') as requisito")])->map(fn ($a) => (array) $a)->all(),
        ]);
    }

    /**
     * Cambia el estado desde la bandeja: atender (en_revision), observar, pasar a firma o rechazar.
     */
    public function mover(int $id, string $accion, ?string $comentario, User $usuario): array
    {
        $t = DB::table('tramites')->where('id', $id)->lockForUpdate()->first();
        abort_if(! $t, 404);
        $permitidas = [
            'atender' => ['solicitado'],
            'observar' => ['solicitado', 'en_revision'],
            'a_firma' => ['solicitado', 'en_revision'],
            'rechazar' => ['solicitado', 'observado', 'en_revision', 'por_firmar'],
        ];
        if (! in_array($t->estado, $permitidas[$accion] ?? [], true)) {
            throw ValidationException::withMessages(['estado' => ['El trámite está «'.str_replace('_', ' ', $t->estado).'»: esa acción no corresponde.']]);
        }
        if (in_array($accion, ['observar', 'rechazar'], true) && trim((string) $comentario) === '') {
            throw ValidationException::withMessages(['comentario' => [$accion === 'observar' ? 'Explica a la familia qué falta o qué corregir.' : 'Explica a la familia por qué se rechaza.']]);
        }
        if ($accion === 'a_firma') {
            $this->validarParaFirma($t);
        }
        $nuevo = ['atender' => 'en_revision', 'observar' => 'observado', 'a_firma' => 'por_firmar', 'rechazar' => 'rechazado'][$accion];
        DB::table('tramites')->where('id', $id)->update(['estado' => $nuevo, 'atendido_por' => $usuario->id]);
        $this->historial($id, $nuevo, $comentario, $usuario->id);
        if ($nuevo === 'rechazado' && $t->cargo_id) {
            // Un trámite rechazado no se cobra: si el cargo seguía sin pagar, se anula.
            DB::table('cargos')->where('id', $t->cargo_id)->where('estado', 'pendiente')->where('pagado', 0)->update(['estado' => 'anulado']);
        }
        $this->auditoria->registrar('tramite_'.$nuevo, 'tramites', $id, ['estado' => $t->estado], ['estado' => $nuevo]);
        if (in_array($nuevo, ['observado', 'rechazado'], true)) {
            $this->avisarFamilia($t, $nuevo === 'observado' ? "Trámite {$t->numero} observado" : "Trámite {$t->numero} rechazado", trim((string) $comentario));
        }

        return $this->detalle($id);
    }

    /**
     * Firma por lote (DOC-H04): genera el PDF con QR y lo entrega a la familia.
     *
     * @param  list<int>  $ids
     * @return list<array{id: int, ok: bool, mensaje?: string, codigo?: string}>
     */
    public function firmarLote(array $ids, User $usuario): array
    {
        $resultados = [];
        foreach (array_unique($ids) as $id) {
            try {
                $resultados[] = DB::transaction(function () use ($id, $usuario) {
                    $t = DB::table('tramites')->where('id', $id)->lockForUpdate()->first();
                    abort_if(! $t, 404, 'El trámite no existe.');
                    if ($t->estado !== 'por_firmar') {
                        throw ValidationException::withMessages(['estado' => ["{$t->numero}: no está por firmar."]]);
                    }
                    $plantilla = DB::table('tramite_tipos')->where('id', $t->tramite_tipo_id)->value('plantilla_documento_id');
                    if (! $plantilla) {
                        throw ValidationException::withMessages(['plantilla' => ["{$t->numero}: el trámite no tiene plantilla; entrégalo subiendo el documento."]]);
                    }
                    $this->validarParaFirma($t);
                    $doc = $this->emision->emitir((int) $plantilla, (int) $t->alumno_id, $t, $usuario->id);
                    $this->entregado($t, $doc['id'], $usuario->id, 'Firmado y entregado');

                    return ['id' => (int) $id, 'ok' => true, 'codigo' => $doc['codigo']];
                });
            } catch (ValidationException $e) {
                $resultados[] = ['id' => (int) $id, 'ok' => false, 'mensaje' => collect($e->errors())->flatten()->first()];
            } catch (HttpException $e) {
                $resultados[] = ['id' => (int) $id, 'ok' => false, 'mensaje' => $e->getMessage() ?: 'No se pudo firmar.'];
            }
        }

        return $resultados;
    }

    /** Entrega un trámite subiendo el documento ya firmado (trámites sin plantilla). */
    public function entregarArchivo(int $id, UploadedFile $archivo, User $usuario): array
    {
        return DB::transaction(function () use ($id, $archivo, $usuario) {
            $t = DB::table('tramites')->where('id', $id)->lockForUpdate()->first();
            abort_if(! $t, 404);
            if ($t->estado !== 'por_firmar') {
                throw ValidationException::withMessages(['estado' => ['El trámite no está por firmar.']]);
            }
            $this->validarParaFirma($t);
            $guardado = $this->almacen->guardar($archivo, $usuario->id, true);
            $doc = $this->emision->registrarArchivo($guardado, (int) $t->alumno_id, $t, $usuario->id);
            $this->entregado($t, $doc['id'], $usuario->id, 'Entregado con el documento subido');

            return $this->detalle($id);
        });
    }

    public function urlAdjunto(int $tramiteId, int $adjuntoId, ?array $hijos = null): string
    {
        if ($hijos !== null) {
            $this->deFamilia($tramiteId, $hijos);
        }
        $a = DB::table('tramite_adjuntos')->where('id', $adjuntoId)->where('tramite_id', $tramiteId)->first();
        abort_if(! $a, 404);

        return $this->almacen->urlTemporal(Archivo::query()->findOrFail($a->archivo_id));
    }

    public function urlDocumento(int $tramiteId): string
    {
        $doc = DB::table('tramites')->where('id', $tramiteId)->value('documento_emitido_id');
        abort_if(! $doc, 404, 'El trámite no tiene documento.');

        return $this->emision->url((int) $doc);
    }

    /** Semáforo de plazo: verde (más de 1 día), ámbar (vence hoy o mañana), rojo (vencido). */
    public static function semaforo(?string $vence, string $estado): ?string
    {
        if (! $vence || in_array($estado, ['entregado', 'rechazado'], true)) {
            return null;
        }
        $dias = now()->startOfDay()->diffInDays(Carbon::parse($vence)->startOfDay(), false);

        return $dias < 0 ? 'rojo' : ($dias <= 1 ? 'ambar' : 'verde');
    }

    // ───────────────────────── Internos

    private function validarParaFirma(object $t): void
    {
        if ($t->cargo_id) {
            $cargo = DB::table('cargos')->find($t->cargo_id, ['estado']);
            if ($cargo && ! in_array($cargo->estado, ['pagado', 'exonerado'], true)) {
                throw ValidationException::withMessages(['cargo' => ["{$t->numero}: el pago del trámite está pendiente."]]);
            }
        }
        if (DB::table('tramite_tipos')->where('id', $t->tramite_tipo_id)->value('requiere_sin_deuda')) {
            $vencidos = collect($this->cobros->pendientes([(int) $t->alumno_id], true))->where('vencido', true)->where('id', '!=', $t->cargo_id);
            if ($vencidos->isNotEmpty()) {
                throw ValidationException::withMessages(['deuda' => ["{$t->numero}: el alumno tiene ".$vencidos->count().' cargo(s) vencido(s); este trámite exige estar al día.']]);
            }
        }
    }

    private function entregado(object $t, int $documentoId, int $usuarioId, string $comentario): void
    {
        DB::table('tramites')->where('id', $t->id)->update(['estado' => 'entregado', 'documento_emitido_id' => $documentoId, 'atendido_por' => $usuarioId]);
        $this->historial((int) $t->id, 'entregado', $comentario, $usuarioId);
        $this->auditoria->registrar('tramite_entregado', 'tramites', (int) $t->id, ['estado' => 'por_firmar'], ['estado' => 'entregado', 'documento_emitido_id' => $documentoId]);
        $this->avisarFamilia($t, "Tu trámite {$t->numero} está listo", 'Descarga el documento firmado desde la app, en Trámites.');
    }

    private function vence(int $dias): string
    {
        // Plazo en días hábiles (lunes a viernes).
        $f = now()->startOfDay();
        while ($dias > 0) {
            $f->addDay();
            if (! $f->isWeekend()) {
                $dias--;
            }
        }

        return $f->toDateString();
    }

    /** @param  array<int, UploadedFile>  $adjuntos */
    private function guardarAdjuntos(int $tramiteId, array $adjuntos, int $usuarioId): void
    {
        foreach ($adjuntos as $req => $archivo) {
            $a = $this->almacen->guardar($archivo, $usuarioId, true);
            DB::table('tramite_adjuntos')->insert(['institucion_id' => ContextoInstitucion::requerido(), 'tramite_id' => $tramiteId, 'tramite_requisito_id' => $req ?: null, 'archivo_id' => $a->id]);
        }
    }

    private function historial(int $id, string $estado, ?string $comentario, ?int $usuarioId): void
    {
        DB::table('tramite_historial')->insert(['institucion_id' => ContextoInstitucion::requerido(), 'tramite_id' => $id, 'estado' => $estado,
            'comentario' => $comentario !== null && trim($comentario) !== '' ? trim($comentario) : null, 'user_id' => $usuarioId]);
    }

    private function deFamilia(int $id, array $hijos): object
    {
        $t = DB::table('tramites')->where('id', $id)->first();
        abort_if(! $t || ! in_array((int) $t->alumno_id, $hijos, true), 404);

        return $t;
    }

    private function avisarFamilia(object $t, string $titulo, string $cuerpo): void
    {
        $usuarios = DB::table('alumno_apoderado as aa')->join('apoderados as p', 'p.id', '=', 'aa.apoderado_id')->where('aa.alumno_id', $t->alumno_id)
            ->whereNotNull('p.user_id')->pluck('p.user_id')->push($t->solicitado_por)->unique()->all();
        $this->notificador->enviar($usuarios, ['app'], 'tramites', $titulo, $cuerpo ?: $titulo, ['pantalla' => 'tramites', 'id' => (int) $t->id]);
    }

    private function avisarBandeja(int $id, string $titulo = 'Nuevo trámite'): void
    {
        $t = DB::table('tramites')->find($id);
        $usuarios = DB::table('membresias as m')->join('roles as r', 'r.id', '=', 'm.rol_id')
            ->whereIn('r.codigo', ['secretaria'])->pluck('m.user_id')->all();
        $this->notificador->enviar($usuarios, ['app'], 'tramites', $titulo, "{$t->numero}: revisa la bandeja de trámites.", ['pantalla' => 'tramites', 'id' => $id]);
    }

    private function consulta()
    {
        return DB::table('tramites as t')->join('tramite_tipos as tt', 'tt.id', '=', 't.tramite_tipo_id')->join('alumnos as a', 'a.id', '=', 't.alumno_id')
            ->leftJoin('cargos as c', 'c.id', '=', 't.cargo_id')->leftJoin('documentos_emitidos as d', 'd.id', '=', 't.documento_emitido_id')
            ->leftJoin('users as u', 'u.id', '=', 't.solicitado_por')
            ->select(['t.id', 't.numero', 't.estado', 't.vence', 't.created_at', 't.updated_at', 't.comentario', 't.alumno_id', 't.tramite_tipo_id', 't.documento_emitido_id',
                'tt.nombre as tipo', 'tt.plantilla_documento_id', DB::raw("a.apellido_paterno || ' ' || coalesce(a.apellido_materno, '') || ', ' || a.nombres as alumno"),
                DB::raw("trim(coalesce(u.nombres, '') || ' ' || coalesce(u.apellidos, '')) as solicitante"),
                'c.estado as cargo_estado', 'c.monto as cargo_monto', 'd.codigo_verificacion as codigo', DB::raw('d.anulado_at is not null as anulado')]);
    }

    private function fila(object $t): array
    {
        return array_merge((array) $t, [
            'alumno' => preg_replace('/\s+/', ' ', (string) $t->alumno), 'cargo_monto' => $t->cargo_monto !== null ? (float) $t->cargo_monto : null,
            'con_plantilla' => (bool) $t->plantilla_documento_id, 'anulado' => (bool) $t->anulado, 'semaforo' => self::semaforo($t->vence, $t->estado),
            'pagado' => $t->cargo_estado === null || in_array($t->cargo_estado, ['pagado', 'exonerado'], true),
        ]);
    }
}
