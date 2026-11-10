<?php

namespace App\Modulos\ADM\Services;

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\Auditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-54 / ADM-H12 · Condiciones económicas del año (Ley 26549): texto versionado.
 * Una versión publicada no se edita: se publica otra. La vigente es la última publicada y es la
 * que el padre acepta antes de matricular (en línea o en ventanilla).
 */
class CondicionesEconomicas
{
    public function __construct(private Auditoria $auditoria) {}

    /** @return list<array{id: int, version: int, contenido: string, publicado_at: string|null, aceptaciones: int}> */
    public function versiones(int $anioId): array
    {
        PlanEstudios::anio($anioId);
        $aceptaciones = DB::table('condiciones_aceptaciones')->groupBy('condiciones_economicas_id')
            ->select('condiciones_economicas_id', DB::raw('count(*) as n'))->pluck('n', 'condiciones_economicas_id');

        return DB::table('condiciones_economicas')->where('anio_escolar_id', $anioId)->orderByDesc('version')
            ->get(['id', 'version', 'contenido', 'publicado_at'])
            ->map(fn ($c) => ['id' => (int) $c->id, 'version' => (int) $c->version, 'contenido' => $c->contenido, 'publicado_at' => $c->publicado_at,
                'aceptaciones' => (int) ($aceptaciones[$c->id] ?? 0)])->all();
    }

    public function vigente(int $anioId): ?object
    {
        return DB::table('condiciones_economicas')->where('anio_escolar_id', $anioId)->whereNotNull('publicado_at')
            ->orderByDesc('version')->first(['id', 'version', 'contenido', 'publicado_at']);
    }

    public function publicar(int $anioId, string $contenido): array
    {
        PlanEstudios::anio($anioId);
        $contenido = trim($contenido);
        $actual = $this->vigente($anioId);
        if ($actual && $actual->contenido === $contenido) {
            throw ValidationException::withMessages(['contenido' => ['El texto es igual a la versión vigente.']]);
        }
        $version = (int) DB::table('condiciones_economicas')->where('anio_escolar_id', $anioId)->max('version') + 1;
        $id = (int) DB::table('condiciones_economicas')->insertGetId([
            'institucion_id' => ContextoInstitucion::requerido(), 'anio_escolar_id' => $anioId, 'version' => $version,
            'contenido' => $contenido, 'publicado_at' => now(),
        ]);
        $this->auditoria->registrar('condiciones_publicadas', 'condiciones_economicas', $id, $actual ? ['version' => $actual->version] : null, ['version' => $version]);

        return $this->versiones($anioId);
    }
}
