<?php

namespace App\Modulos\SIT\Services;

use App\Models\User;
use App\Modulos\Compartido\Correo\CorreoTransaccional;
use App\Modulos\SEG\Services\AlmacenArchivos;
use App\Modulos\SIT\Correo\RespuestaReclamo;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * EDUN-302 / SIT-031 · Gestión de reclamaciones en el Panel CMS.
 *
 * - Lista con pestañas por estado, filtros (tipo, texto, plazo) e indicadores de plazo legal:
 *   vencidos (sin respuesta pasado vence_en) y por vencer (vencen en 3 días hábiles o menos).
 * - Ficha con los datos del reclamante, los adjuntos (del almacenamiento privado) y el historial.
 * - Cambio de estado recibido → en revisión → respondido → cerrado (la base impide saltos y que
 *   la respuesta cambie después de enviada). Al responder, la respuesta va por correo al
 *   reclamante. Cada cambio queda en cms.auditoria.
 * Corre dentro de la transacción del middleware «cms» (app.rol_cms = superadmin).
 */
class GestionReclamaciones
{
    public const POR_PAGINA = 10;

    public const ESTADOS = ['recibido' => 'Recibido', 'en_revision' => 'En revisión', 'respondido' => 'Respondido', 'cerrado' => 'Cerrado'];

    /** Estados siguientes permitidos (lo mismo que cms.validar_estado_reclamo). */
    public const SIGUIENTES = ['recibido' => ['en_revision', 'respondido'], 'en_revision' => ['respondido'], 'respondido' => ['cerrado'], 'cerrado' => []];

    private const ABIERTOS = ['recibido', 'en_revision'];

    /** Días hábiles para avisar que un expediente está por vencer. */
    public const AVISO_DIAS_HABILES = 3;

    public function __construct(private CorreoTransaccional $correo) {}

    /** @param  array{estado?: ?string, tipo?: ?string, q?: ?string, plazo?: ?string, pagina?: int}  $f */
    public function listar(array $f): array
    {
        $pagina = max(1, (int) ($f['pagina'] ?? 1));
        [$hoy, $limiteAviso] = $this->fechasPlazo();

        $filtrada = $this->consulta($f, $hoy, $limiteAviso);
        $porEstado = (clone $filtrada)->select('r.estado', DB::raw('count(*) as n'))->groupBy('r.estado')->pluck('n', 'estado');
        $contadores = ['todos' => (int) $porEstado->sum()] + collect(array_keys(self::ESTADOS))->mapWithKeys(fn ($e) => [$e => (int) ($porEstado[$e] ?? 0)])->all();

        $conEstado = (clone $filtrada)->when(! empty($f['estado']), fn ($q) => $q->where('r.estado', $f['estado']));
        $total = (clone $conEstado)->count();
        $filas = $conEstado
            ->orderByRaw("case when r.estado in ('recibido','en_revision') then 0 else 1 end")
            ->orderBy('r.vence_en')->orderByDesc('r.id')
            ->offset(($pagina - 1) * self::POR_PAGINA)->limit(self::POR_PAGINA)
            ->get(['r.id', 'r.numero_expediente', 'r.tipo', 'r.nombre_reclamante', 'r.email', 'r.producto_descripcion', 'r.estado', 'r.vence_en', 'r.created_at', 'r.respondido_en'])
            ->map(fn ($r) => [...(array) $r, 'plazo' => $this->plazo($r, $hoy, $limiteAviso)]);

        return [
            'data' => $filas,
            'pagina' => $pagina,
            'por_pagina' => self::POR_PAGINA,
            'total' => $total,
            'paginas' => max(1, (int) ceil($total / self::POR_PAGINA)),
            'contadores' => $contadores,
            'indicadores' => $this->indicadores($hoy, $limiteAviso),
        ];
    }

    public function detalle(int $id): array
    {
        $r = DB::table('cms.reclamaciones')->where('id', $id)->first() ?? abort(404, 'No encontramos ese expediente.');
        [$hoy, $limiteAviso] = $this->fechasPlazo();

        $adjuntos = collect(json_decode($r->adjuntos, true) ?: [])->values()
            ->map(fn ($a, $i) => ['indice' => $i, 'nombre' => $a['nombre'] ?? 'adjunto', 'mime' => $a['mime'] ?? null, 'bytes' => (int) ($a['bytes'] ?? 0)]);

        $historial = DB::table('cms.auditoria as a')->leftJoin('users as u', 'u.id', '=', 'a.usuario_id')
            ->where('a.entidad', 'reclamaciones')->where('a.entidad_id', (string) $id)
            ->orderByDesc('a.created_at')->orderByDesc('a.id')->limit(50)
            ->get(['a.campo', 'a.detalle', 'a.valor_antes', 'a.valor_despues', 'a.created_at', DB::raw("nullif(trim(concat_ws(' ', u.nombres, u.apellidos)), '') as usuario")])
            ->map(fn ($h) => [...(array) $h, 'valor_antes' => json_decode((string) $h->valor_antes, true), 'valor_despues' => json_decode((string) $h->valor_despues, true)]);

        $datos = collect((array) $r)->except(['adjuntos', 'ip'])->all();

        return [
            ...$datos,
            'monto_reclamado' => $r->monto_reclamado,
            'menor_edad' => (bool) $r->menor_edad,
            'plazo' => $this->plazo($r, $hoy, $limiteAviso),
            'siguientes' => self::SIGUIENTES[$r->estado] ?? [],
            'adjuntos' => $adjuntos,
            'historial' => $historial,
        ];
    }

    /**
     * @param  array{estado?: ?string, notas_internas?: ?string, respuesta_cliente?: ?string}  $d
     */
    public function actualizar(int $id, array $d, User $usuario, ?string $ip): array
    {
        $r = DB::table('cms.reclamaciones')->where('id', $id)->lockForUpdate()->first() ?? abort(404, 'No encontramos ese expediente.');

        $cambios = [];
        $nuevoEstado = $d['estado'] ?? null;
        if ($nuevoEstado !== null && $nuevoEstado !== $r->estado) {
            if (! in_array($nuevoEstado, self::SIGUIENTES[$r->estado] ?? [], true)) {
                throw ValidationException::withMessages(['estado' => sprintf('Un expediente «%s» no puede pasar a «%s».', self::ESTADOS[$r->estado], self::ESTADOS[$nuevoEstado])]);
            }
            $cambios['estado'] = $nuevoEstado;
        }

        $respondiendo = ($cambios['estado'] ?? null) === 'respondido';
        if ($respondiendo) {
            $respuesta = trim((string) ($d['respuesta_cliente'] ?? ''));
            if (mb_strlen($respuesta) < 20) {
                throw ValidationException::withMessages(['respuesta_cliente' => 'Escribe la respuesta al cliente (al menos 20 caracteres).']);
            }
            $cambios['respuesta_cliente'] = $respuesta;
            $cambios['respondido_en'] = now();
        } elseif (array_key_exists('respuesta_cliente', $d) && $d['respuesta_cliente'] !== null && $r->respondido_en === null && $nuevoEstado !== 'respondido') {
            // Borrador de respuesta: se guarda sin enviar mientras el expediente sigue abierto.
            $cambios['respuesta_cliente'] = trim((string) $d['respuesta_cliente']) ?: null;
        }

        if (array_key_exists('notas_internas', $d)) {
            $notas = trim((string) ($d['notas_internas'] ?? '')) ?: null;
            if ($notas !== $r->notas_internas) {
                $cambios['notas_internas'] = $notas;
            }
        }

        if ($cambios === []) {
            return $this->detalle($id);
        }

        DB::table('cms.reclamaciones')->where('id', $id)->update($cambios);

        foreach (['estado', 'respuesta_cliente', 'notas_internas'] as $campo) {
            if (! array_key_exists($campo, $cambios)) {
                continue;
            }
            $detalle = match ($campo) {
                'estado' => sprintf('Libro de Reclamaciones · %s: %s → %s', $r->numero_expediente, self::ESTADOS[$r->estado], self::ESTADOS[$cambios['estado']]),
                'respuesta_cliente' => sprintf('Libro de Reclamaciones · %s: %s', $r->numero_expediente, $respondiendo ? 'respuesta enviada al cliente' : 'borrador de respuesta'),
                'notas_internas' => sprintf('Libro de Reclamaciones · %s: notas internas', $r->numero_expediente),
            };
            DB::table('cms.auditoria')->insert([
                'usuario_id' => $usuario->id,
                'usuario_rol' => 'superadmin',
                'seccion' => 'reclamaciones',
                'accion' => 'editado',
                'entidad' => 'reclamaciones',
                'entidad_id' => (string) $id,
                'campo' => $campo,
                'detalle' => Str::limit($detalle, 250, ''),
                'valor_antes' => json_encode($r->{$campo}),
                'valor_despues' => json_encode($cambios[$campo]),
                'ip' => $ip,
            ]);
        }

        if ($respondiendo) {
            $this->enviarRespuesta($r, $cambios['respuesta_cliente']);
        }

        return $this->detalle($id);
    }

    /** Descarga de un adjunto del almacenamiento privado (solo el Panel CMS). */
    public function adjunto(int $id, int $indice): StreamedResponse
    {
        $r = DB::table('cms.reclamaciones')->where('id', $id)->first(['adjuntos']) ?? abort(404, 'No encontramos ese expediente.');
        $a = (json_decode($r->adjuntos, true) ?: [])[$indice] ?? abort(404, 'No encontramos ese adjunto.');
        $disco = Storage::disk(AlmacenArchivos::DISCO);
        if (empty($a['ruta']) || ! $disco->exists($a['ruta'])) {
            abort(404, 'No encontramos ese adjunto.');
        }

        return $disco->download($a['ruta'], $a['nombre'] ?? basename($a['ruta']), [
            'Content-Type' => $a['mime'] ?? 'application/octet-stream',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** La respuesta queda registrada aunque el correo falle (se ve en la bitácora de correos). */
    private function enviarRespuesta(object $r, string $respuesta): void
    {
        try {
            $this->correo->enviar(new RespuestaReclamo([
                'expediente' => $r->numero_expediente,
                'tipo' => $r->tipo,
                'nombre' => $r->nombre_reclamante,
                'respuesta' => $respuesta,
                'url_seguimiento' => rtrim((string) config('services.sitio.url'), '/').'/libro-de-reclamaciones/seguimiento',
            ]), $r->email, 'reclamo_respuesta');
        } catch (Throwable $e) {
            Log::error('Libro de Reclamaciones: no se pudo encolar la respuesta.', ['expediente' => $r->numero_expediente, 'error' => $e->getMessage()]);
        }
    }

    /** @return array{0: string, 1: string} hoy (Lima) y el último día que cuenta como «por vencer» */
    private function fechasPlazo(): array
    {
        $hoy = CarbonImmutable::now('America/Lima')->toDateString();
        $limite = DB::selectOne('select cms.sumar_dias_habiles(?::date, ?) as d', [$hoy, self::AVISO_DIAS_HABILES])->d;

        return [$hoy, $limite];
    }

    /** 'vencido' | 'por_vencer' | 'en_plazo' (abiertos) · 'a_tiempo' | 'fuera_de_plazo' (respondidos). */
    private function plazo(object $r, string $hoy, string $limiteAviso): string
    {
        if (! in_array($r->estado, self::ABIERTOS, true)) {
            if (! $r->respondido_en) {
                return 'a_tiempo';
            }

            return CarbonImmutable::parse($r->respondido_en)->setTimezone('America/Lima')->toDateString() <= $r->vence_en ? 'a_tiempo' : 'fuera_de_plazo';
        }
        if ($r->vence_en < $hoy) {
            return 'vencido';
        }

        return $r->vence_en <= $limiteAviso ? 'por_vencer' : 'en_plazo';
    }

    private function consulta(array $f, string $hoy, string $limiteAviso): Builder
    {
        return DB::table('cms.reclamaciones as r')
            ->when(! empty($f['tipo']), fn ($q) => $q->where('r.tipo', $f['tipo']))
            ->when(($f['plazo'] ?? null) === 'vencidos', fn ($q) => $q->whereIn('r.estado', self::ABIERTOS)->where('r.vence_en', '<', $hoy))
            ->when(($f['plazo'] ?? null) === 'por_vencer', fn ($q) => $q->whereIn('r.estado', self::ABIERTOS)->whereBetween('r.vence_en', [$hoy, $limiteAviso]))
            ->when(! empty($f['q']), function ($q) use ($f) {
                $texto = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($f['q'])).'%';
                $q->where(fn ($w) => $w->where('r.numero_expediente', 'ilike', $texto)->orWhere('r.nombre_reclamante', 'ilike', $texto)
                    ->orWhere('r.numero_doc', 'ilike', $texto)->orWhere('r.email', 'ilike', $texto));
            });
    }

    private function indicadores(string $hoy, string $limiteAviso): array
    {
        $abiertos = DB::table('cms.reclamaciones')->whereIn('estado', self::ABIERTOS);
        $respondidos = DB::table('cms.reclamaciones')->whereNotNull('respondido_en')->where('respondido_en', '>=', now()->subDays(90));
        $nRespondidos = (clone $respondidos)->count();
        $aTiempo = (clone $respondidos)->whereRaw("(respondido_en at time zone 'America/Lima')::date <= vence_en")->count();

        return [
            'abiertos' => (clone $abiertos)->count(),
            'vencidos' => (clone $abiertos)->where('vence_en', '<', $hoy)->count(),
            'por_vencer' => (clone $abiertos)->whereBetween('vence_en', [$hoy, $limiteAviso])->count(),
            'a_tiempo_pct' => $nRespondidos > 0 ? (int) round(100 * $aTiempo / $nRespondidos) : null,
            'respondidos_90' => $nRespondidos,
        ];
    }
}
