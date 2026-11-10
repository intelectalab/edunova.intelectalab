<?php

namespace App\Modulos\SIT\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-291 / SIT-054 y EDUN-306 / SIT-055 · Prueba de cada consentimiento (Ley N.° 29733):
 * tipo, versión vigente de la política al momento del envío, lead, IP, navegador y fecha.
 *
 * Cada lead queda además en la bandeja de salida al CRM (EDUN-275).
 *
 * El público solo inserta (RLS): no puede leer leads ni consentimientos, por eso el id del lead
 * se toma de la secuencia antes de insertar en vez de usar RETURNING.
 */
class RegistroConsentimientos
{
    public const CATEGORIAS_COOKIES = ['esenciales', 'analitica', 'marketing', 'personalizacion'];

    public function __construct(private Politicas $politicas, private EnvioCrm $crm) {}

    /**
     * Guarda el lead y su consentimiento en la misma transacción.
     *
     * @param  array<string, mixed>  $lead  columnas de cms.leads (sin id ni consentimiento_version)
     * @return array{id: int, consentimiento_version: string}
     */
    public function registrarLead(array $lead, ?string $ip, ?string $agente): array
    {
        return DB::transaction(function () use ($lead, $ip, $agente) {
            DB::select("select set_config('app.rol_cms', 'publico', true)");

            $version = $this->versionVigente('privacidad', 'consentimiento');
            $id = (int) DB::selectOne("select nextval('cms.leads_id_seq') as id")->id;

            DB::table('cms.leads')->insert([...$lead, 'id' => $id, 'consentimiento_version' => $version]);
            DB::table('cms.consentimientos')->insert([
                'tipo' => 'formulario',
                'version_politica' => $version,
                'lead_id' => $id,
                'ip' => $ip,
                'user_agent' => $agente,
            ]);
            // EDUN-275 · Bandeja de salida al CRM, en la misma transacción.
            $this->crm->encolar([...$lead, 'id' => $id, 'consentimiento_version' => $version]);

            return ['id' => $id, 'consentimiento_version' => $version];
        });
    }

    /**
     * Preferencias del banner de cookies. «esenciales» siempre va incluida.
     *
     * @param  list<string>  $categorias
     */
    public function registrarCookies(array $categorias, ?string $ip, ?string $agente): string
    {
        $categorias = array_values(array_intersect(self::CATEGORIAS_COOKIES, ['esenciales', ...$categorias]));

        return DB::transaction(function () use ($categorias, $ip, $agente) {
            DB::select("select set_config('app.rol_cms', 'publico', true)");

            $version = $this->versionVigente('cookies', 'categorias');
            DB::table('cms.consentimientos')->insert([
                'tipo' => 'cookies',
                'version_politica' => $version,
                'categorias' => '{'.implode(',', $categorias).'}',
                'ip' => $ip,
                'user_agent' => $agente,
            ]);

            return $version;
        });
    }

    private function versionVigente(string $tipo, string $campo): string
    {
        return $this->politicas->claveVigente($tipo)
            ?? throw ValidationException::withMessages([$campo => 'La política aún no está publicada. Inténtalo más tarde.']);
    }
}
