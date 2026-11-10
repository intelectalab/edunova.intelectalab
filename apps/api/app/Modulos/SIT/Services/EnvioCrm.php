<?php

namespace App\Modulos\SIT\Services;

use App\Models\User;
use App\Modulos\SIT\Jobs\EnviarLeadAlCrm;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * EDUN-275 / SIT-052 · Envío de leads al CRM con reintentos.
 *
 * Cada lead nuevo deja una fila en cms.leads_pendientes (la bandeja de salida) en la misma
 * transacción en que se registra, y un trabajo en la cola lo envía al webhook del CRM
 * (services.crm.url): POST JSON con token Bearer y la clave de idempotencia «edunova-lead-{id}»,
 * para que un reintento no duplique el lead. Si falla, se reintenta a los 1, 5, 15 y 60 minutos;
 * al quinto intento fallido queda «con error» hasta que alguien lo sincronice desde la bandeja.
 * Sin CRM configurado no se intenta nada: el lead espera en la bandeja de salida.
 */
class EnvioCrm
{
    public const MAX_INTENTOS = 5;

    /** Espera antes de cada reintento, en segundos (también es el backoff del trabajo). */
    public const ESPERAS = [60, 300, 900, 3600];

    public function configurado(): bool
    {
        return filled(config('services.crm.url'));
    }

    /**
     * Encola el lead. Se llama dentro de la transacción que lo registra (rol público: solo inserta).
     *
     * @param  array<string, mixed>  $lead  columnas de cms.leads, con id
     */
    public function encolar(array $lead): void
    {
        DB::table('cms.leads_pendientes')->insert(['lead_id' => $lead['id'], 'payload' => json_encode(self::payload($lead))]);
        EnviarLeadAlCrm::dispatch((int) $lead['id'])->afterCommit();
    }

    /**
     * Un intento de envío. Devuelve el resultado; si el CRM falla y quedan intentos, lanza una
     * excepción para que la cola lo reintente.
     *
     * @return 'enviado'|'sin_crm'|'agotado'|'sin_pendiente'
     */
    public function enviar(int $leadId): string
    {
        if (! $this->configurado()) {
            return 'sin_crm';
        }
        $pendiente = $this->comoSoporte(fn () => DB::table('cms.leads_pendientes')->where('lead_id', $leadId)->first());
        if (! $pendiente) {
            return 'sin_pendiente';
        }
        if ($pendiente->intentos >= self::MAX_INTENTOS) {
            return 'agotado';
        }

        try {
            $r = Http::timeout((int) config('services.crm.timeout', 10))
                ->acceptJson()
                ->withHeaders(['Idempotency-Key' => "edunova-lead-{$leadId}"])
                ->when(filled(config('services.crm.token')), fn ($h) => $h->withToken((string) config('services.crm.token')))
                ->post((string) config('services.crm.url'), json_decode($pendiente->payload, true));
            $r->throw();
        } catch (Throwable $e) {
            $intentos = $pendiente->intentos + 1;
            $error = Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 480);
            $this->comoSoporte(fn () => DB::table('cms.leads_pendientes')->where('lead_id', $leadId)->update([
                'intentos' => $intentos,
                'ultimo_error' => $error,
                'proximo_intento' => CarbonImmutable::now()->addSeconds(self::ESPERAS[min($intentos, count(self::ESPERAS)) - 1]),
            ]));
            if ($intentos >= self::MAX_INTENTOS) {
                Log::error('CRM: el lead no se pudo enviar tras 5 intentos.', ['lead' => $leadId, 'error' => $error]);

                return 'agotado';
            }
            throw new RuntimeException("El CRM no recibió el lead {$leadId} (intento {$intentos}): {$error}", 0, $e);
        }

        $ref = $r->json('id') ?? $r->json('data.id') ?? $r->json('ref');
        $this->comoSoporte(function () use ($leadId, $ref) {
            DB::table('cms.leads')->where('id', $leadId)->update([
                'crm_enviado_en' => now(),
                'crm_ref' => is_scalar($ref) ? Str::limit((string) $ref, 120, '') : null,
            ]);
            DB::table('cms.leads_pendientes')->where('lead_id', $leadId)->delete();
        });

        return 'enviado';
    }

    /**
     * EDUN-282 · «Sincronizar con el CRM» desde la bandeja: vuelve a encolar los leads que no
     * llegaron (los que agotaron sus intentos empiezan de cero) y crea la fila de salida a los
     * que nunca la tuvieron. Corre dentro de la transacción del Panel CMS.
     *
     * @param  list<int>|null  $ids  solo esos leads; null = todos los no enviados
     * @return int leads encolados
     */
    public function sincronizar(?array $ids, User $usuario, ?string $ip): int
    {
        $sinFila = DB::table('cms.leads as l')
            ->whereNull('l.crm_enviado_en')
            ->whereNotExists(fn ($q) => $q->from('cms.leads_pendientes as p')->whereColumn('p.lead_id', 'l.id'))
            ->when($ids !== null, fn ($q) => $q->whereIn('l.id', $ids))
            ->get();
        foreach ($sinFila as $lead) {
            DB::table('cms.leads_pendientes')->insert(['lead_id' => $lead->id, 'payload' => json_encode(self::payload((array) $lead))]);
        }

        $pendientes = DB::table('cms.leads_pendientes as p')->join('cms.leads as l', 'l.id', '=', 'p.lead_id')
            ->whereNull('l.crm_enviado_en')
            ->when($ids !== null, fn ($q) => $q->whereIn('p.lead_id', $ids));
        $lista = (clone $pendientes)->pluck('p.lead_id')->map(fn ($id) => (int) $id)->all();
        if ($lista === []) {
            return 0;
        }

        DB::table('cms.leads_pendientes')->whereIn('lead_id', $lista)->update(['intentos' => 0, 'ultimo_error' => null, 'proximo_intento' => now()]);
        foreach ($lista as $id) {
            EnviarLeadAlCrm::dispatch($id)->afterCommit();
        }

        DB::table('cms.auditoria')->insert([
            'usuario_id' => $usuario->id,
            'usuario_rol' => 'superadmin',
            'seccion' => 'leads',
            'accion' => 'editado',
            'entidad' => 'leads',
            'entidad_id' => $ids !== null && count($ids) === 1 ? (string) $ids[0] : 'crm',
            'campo' => 'crm',
            'detalle' => Str::limit(sprintf('Bandeja de solicitudes · sincronizar con el CRM: %d %s', count($lista), count($lista) === 1 ? 'solicitud' : 'solicitudes'), 250, ''),
            'valor_despues' => json_encode($lista),
            'ip' => $ip,
        ]);

        return count($lista);
    }

    /**
     * Lo que recibe el CRM. Sin IP ni navegador del visitante: eso es prueba del consentimiento
     * y se queda en Edunova.
     *
     * @param  array<string, mixed>  $l
     * @return array<string, mixed>
     */
    public static function payload(array $l): array
    {
        $hora = $l['demo_hora'] ?? null;

        return [
            'id_externo' => 'edunova-lead-'.$l['id'],
            'origen' => $l['source'],
            'nombre' => $l['nombre'] ?? null,
            'cargo' => $l['cargo'] ?? null,
            'email' => $l['email'] ?? null,
            'telefono' => $l['telefono'] ?? null,
            'institucion' => $l['colegio'] ?? null,
            'ciudad' => $l['ciudad'] ?? null,
            'alumnos' => isset($l['alumnos']) ? (int) $l['alumnos'] : null,
            'plan_interes' => $l['plan_interes'] ?? null,
            'demo' => ! empty($l['demo_fecha']) ? ['fecha' => $l['demo_fecha'], 'hora' => $hora ? substr((string) $hora, 0, 5) : null, 'zona_horaria' => 'America/Lima'] : null,
            'mensaje' => $l['mensaje'] ?? null,
            'pagina' => $l['page_url'] ?? null,
            'utm' => array_filter([
                'source' => $l['utm_source'] ?? null, 'medium' => $l['utm_medium'] ?? null, 'campaign' => $l['utm_campaign'] ?? null,
                'content' => $l['utm_content'] ?? null, 'term' => $l['utm_term'] ?? null,
            ]),
            'consentimiento_version' => $l['consentimiento_version'] ?? null,
            'recibido_en' => isset($l['created_at']) ? CarbonImmutable::parse($l['created_at'])->toIso8601String() : CarbonImmutable::now()->toIso8601String(),
        ];
    }

    /** El trabajo de la cola lee y actualiza la bandeja de salida como soporte (RLS). */
    private function comoSoporte(callable $f): mixed
    {
        return DB::transaction(function () use ($f) {
            DB::select("select set_config('app.rol_cms', 'superadmin', true)");

            return $f();
        });
    }
}
