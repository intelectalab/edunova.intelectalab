<?php

namespace App\Modulos\COM\Services;

use App\Modulos\COM\Correo\AvisoColegio;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\Compartido\Correo\CorreoTransaccional;
use App\Modulos\SEG\Services\Auditoria;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * COM · Libro de reclamaciones virtual del colegio (Indecopi).
 *
 * - EDUN-66 · Formulario público sin sesión con datos obligatorios, número correlativo por año y
 *   copia al correo del consumidor. El plazo de respuesta es un parámetro legal.
 * - EDUN-67 · Alerta a dirección antes de vencer y respuesta enviada por correo y guardada.
 */
class LibroReclamaciones
{
    public function __construct(private Auditoria $auditoria, private Notificador $notificador) {}

    public static function plazoDias(): int
    {
        return (int) (DB::table('parametros_legales')->where('codigo', 'reclamaciones.plazo_respuesta_dias')->where('vigente_desde', '<=', now()->toDateString())
            ->orderByDesc('vigente_desde')->value('valor') ?? 15);
    }

    /** Lo usa la ruta pública: se reconoce al colegio por su subdominio (slug). */
    public static function colegioPorSlug(string $slug): ?object
    {
        return DB::connection('plataforma')->table('instituciones')->where('slug', $slug)->whereIn('estado', ['activo', 'implementacion'])
            ->first(['id', 'nombre', 'razon_social', 'ruc', 'direccion']);
    }

    public function registrar(array $d): array
    {
        $inst = ContextoInstitucion::requerido();
        $anio = now()->year;

        return DB::transaction(function () use ($d, $inst, $anio) {
            DB::select('select pg_advisory_xact_lock(?, ?)', [$inst, $anio]);
            $n = 1 + (int) DB::table('reclamaciones')->where('numero', 'like', "{$anio}-%")->max(DB::raw("cast(split_part(numero, '-', 2) as int)"));
            $numero = sprintf('%d-%06d', $anio, $n);
            $limite = now()->addDays(self::plazoDias())->toDateString();
            $id = (int) DB::table('reclamaciones')->insertGetId([
                'institucion_id' => $inst, 'numero' => $numero, 'tipo' => $d['tipo'], 'consumidor_nombre' => trim($d['nombre']), 'consumidor_documento' => trim($d['documento']),
                'consumidor_email' => mb_strtolower(trim($d['email'])), 'consumidor_celular' => $d['celular'] ?? null, 'detalle' => trim($d['detalle']), 'pedido' => trim($d['pedido']),
                'monto_reclamado' => $d['monto'] ?? null, 'fecha_limite' => $limite,
            ]);
            $colegio = DB::table('instituciones')->where('id', $inst)->first(['nombre', 'razon_social', 'ruc']);
            $cuerpo = "Registramos su {$d['tipo']} N.° {$numero} en el Libro de Reclamaciones de {$colegio->nombre}".($colegio->ruc ? " (RUC {$colegio->ruc})" : '').".\n\nDetalle: {$d['detalle']}\nPedido: {$d['pedido']}\n\nLe responderemos a este correo a más tardar el ".Carbon::parse($limite)->format('d/m/Y').'.';
            try {
                app(CorreoTransaccional::class)->enviar(new AvisoColegio($colegio->nombre, "Copia de su {$d['tipo']} N.° {$numero}", $cuerpo), $d['email'], 'reclamo_copia', $inst);
            } catch (\Throwable) {
                // La copia por correo no bloquea el registro: queda en la bitácora de correos.
            }
            $directores = DB::table('membresias as m')->join('roles as r', 'r.id', '=', 'm.rol_id')->whereIn('r.codigo', ['director', 'promotor'])->pluck('m.user_id')->all();
            $this->notificador->enviar($directores, ['app'], 'comunicados', "Nuevo {$d['tipo']} N.° {$numero}", 'Plazo de respuesta: '.Carbon::parse($limite)->format('d/m/Y'), ['pantalla' => 'reclamos', 'id' => $id]);

            return ['numero' => $numero, 'fecha_limite' => $limite];
        });
    }

    public function listar(): array
    {
        $hoy = now()->startOfDay();

        return DB::table('reclamaciones as r')->leftJoin('reclamacion_respuestas as x', 'x.reclamacion_id', '=', 'r.id')->orderByRaw("r.estado = 'respondido'")->orderBy('r.fecha_limite')->limit(300)
            ->get(['r.*', 'x.respuesta', 'x.enviado_at'])
            ->map(fn ($r) => array_merge((array) $r, ['dias_restantes' => $r->estado === 'abierto' ? (int) $hoy->diffInDays(Carbon::parse($r->fecha_limite), false) : null]))->all();
    }

    public function responder(int $id, string $respuesta, int $usuarioId): void
    {
        $r = DB::table('reclamaciones')->find($id);
        abort_if(! $r, 404);
        if ($r->estado === 'respondido') {
            throw ValidationException::withMessages(['respuesta' => ['Este reclamo ya fue respondido.']]);
        }
        $colegio = DB::table('instituciones')->where('id', ContextoInstitucion::requerido())->value('nombre');
        DB::transaction(function () use ($r, $respuesta, $usuarioId, $colegio) {
            DB::table('reclamacion_respuestas')->insert(['institucion_id' => $r->institucion_id, 'reclamacion_id' => $r->id, 'respuesta' => trim($respuesta), 'respondido_por' => $usuarioId, 'enviado_at' => now()]);
            DB::table('reclamaciones')->where('id', $r->id)->update(['estado' => 'respondido']);
            $this->auditoria->registrar('reclamo_respondido', 'reclamaciones', (int) $r->id, ['estado' => 'abierto'], ['estado' => 'respondido']);
            try {
                app(CorreoTransaccional::class)->enviar(new AvisoColegio($colegio, "Respuesta a su {$r->tipo} N.° {$r->numero}", trim($respuesta)), $r->consumidor_email, 'reclamo_respuesta', (int) $r->institucion_id);
            } catch (\Throwable) {
            }
        });
    }

    /** Alerta a dirección cuando faltan 3 días o menos (una vez por reclamo). */
    public function alertarPorVencer(): int
    {
        $n = 0;
        $directores = DB::table('membresias as m')->join('roles as r', 'r.id', '=', 'm.rol_id')->whereIn('r.codigo', ['director', 'promotor'])->pluck('m.user_id')->all();
        foreach (DB::table('reclamaciones')->where('estado', 'abierto')->whereNull('alerta_enviada_at')->where('fecha_limite', '<=', now()->addDays(3)->toDateString())->get() as $r) {
            $this->notificador->enviar($directores, ['app', 'correo'], 'comunicados', "Reclamo {$r->numero} por vencer", 'Vence el '.Carbon::parse($r->fecha_limite)->format('d/m/Y').'. Respóndelo dentro del plazo legal.', ['pantalla' => 'reclamos', 'id' => $r->id]);
            DB::table('reclamaciones')->where('id', $r->id)->update(['alerta_enviada_at' => now()]);
            $n++;
        }

        return $n;
    }
}
