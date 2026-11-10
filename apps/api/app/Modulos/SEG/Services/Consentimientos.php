<?php

namespace App\Modulos\SEG\Services;

use App\Modulos\Compartido\ContextoInstitucion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-41 / SEG-H07 · Consentimientos de datos personales e imagen de los alumnos (Ley 29733).
 *
 * - Cada finalidad tiene un texto versionado; el padre acepta (o no) una versión concreta.
 * - Puede revocar en cualquier momento: el consentimiento queda con revocado_at.
 * - Sin consentimiento de imagen vigente, la foto del alumno no se publica (carné, comunicados,
 *   galerías): cualquier módulo pregunta con imagenAutorizada().
 * - El dato de 2027 de los colegios migrados («datos_e_imagen») vale para ambas finalidades.
 */
class Consentimientos
{
    public const FINALIDADES = ['datos' => 'Tratamiento de datos personales', 'imagen' => 'Uso de la imagen del alumno'];

    public function __construct(private Auditoria $auditoria) {}

    /** @return array<string, array{version: string, contenido: string, publicado_at: string}|null> texto vigente por finalidad */
    public function textos(): array
    {
        $r = [];
        foreach (array_keys(self::FINALIDADES) as $f) {
            $t = DB::table('consentimiento_textos')->where('finalidad', $f)->orderByDesc('publicado_at')->orderByDesc('id')->first(['version', 'contenido', 'publicado_at']);
            $r[$f] = $t ? (array) $t : null;
        }

        return $r;
    }

    public function publicarTexto(string $finalidad, string $version, string $contenido, int $usuarioId): array
    {
        if (DB::table('consentimiento_textos')->where('finalidad', $finalidad)->where('version', $version)->exists()) {
            throw ValidationException::withMessages(['version' => ["La versión {$version} ya existe: usa un número nuevo."]]);
        }
        DB::table('consentimiento_textos')->insert([
            'institucion_id' => ContextoInstitucion::requerido(), 'finalidad' => $finalidad, 'version' => $version,
            'contenido' => trim($contenido), 'publicado_por' => $usuarioId,
        ]);
        $this->auditoria->registrar('consentimiento_texto_publicado', 'consentimiento_textos', null, null, ['finalidad' => $finalidad, 'version' => $version]);

        return $this->textos();
    }

    /**
     * Estado de cada finalidad de los alumnos: otorgado | negado | revocado | pendiente,
     * y si lo aceptaron con una versión anterior del texto.
     *
     * @param  list<int>  $alumnos
     * @return array<int, array<string, array{estado: string, version: string|null, fecha: string|null, desactualizado: bool}>>
     */
    public function estado(array $alumnos): array
    {
        $vigentes = array_map(fn ($t) => $t['version'] ?? null, $this->textos());
        $filas = DB::table('consentimientos')->where('titular_tipo', 'alumno')->whereIn('titular_id', $alumnos)
            ->orderBy('fecha')->orderBy('id')->get(['titular_id', 'finalidad', 'otorgado', 'version_texto', 'fecha', 'revocado_at']);
        $r = [];
        foreach ($alumnos as $a) {
            foreach (array_keys(self::FINALIDADES) as $f) {
                $r[$a][$f] = ['estado' => 'pendiente', 'version' => null, 'fecha' => null, 'desactualizado' => false];
            }
        }
        foreach ($filas as $c) {
            $finalidades = $c->finalidad === 'datos_e_imagen' ? ['datos', 'imagen'] : [$c->finalidad];
            foreach ($finalidades as $f) {
                if (! isset(self::FINALIDADES[$f])) {
                    continue;
                }
                // La última decisión manda (las filas vienen en orden).
                $r[$c->titular_id][$f] = [
                    'estado' => $c->revocado_at ? 'revocado' : ($c->otorgado ? 'otorgado' : 'negado'),
                    'version' => $c->version_texto, 'fecha' => (string) ($c->revocado_at ?? $c->fecha),
                    'desactualizado' => ! $c->revocado_at && $c->otorgado && $vigentes[$f] && $vigentes[$f] !== $c->version_texto,
                ];
            }
        }

        return $r;
    }

    public function imagenAutorizada(int $alumnoId): bool
    {
        return ($this->estado([$alumnoId])[$alumnoId]['imagen']['estado'] ?? null) === 'otorgado';
    }

    /**
     * Registra la decisión del padre (desde la app o en secretaría) con la versión vigente del
     * texto. Revocar marca revocado_at en el consentimiento otorgado.
     */
    public function registrar(int $alumnoId, string $finalidad, bool $otorgado, int $usuarioId, string $origen): void
    {
        $texto = $this->textos()[$finalidad] ?? null;
        if (! $texto) {
            throw ValidationException::withMessages(['finalidad' => ['El colegio aún no publica el texto de este consentimiento.']]);
        }
        $institucion = ContextoInstitucion::requerido();
        $antes = $this->estado([$alumnoId])[$alumnoId][$finalidad];

        if (! $otorgado && $antes['estado'] === 'otorgado') {
            DB::table('consentimientos')->where('titular_tipo', 'alumno')->where('titular_id', $alumnoId)
                ->whereIn('finalidad', [$finalidad, 'datos_e_imagen'])->where('otorgado', true)->whereNull('revocado_at')
                ->update(['revocado_at' => now()]);
            // Si venía de «datos_e_imagen», la otra finalidad sigue otorgada.
            if (DB::table('consentimientos')->where('titular_id', $alumnoId)->where('finalidad', 'datos_e_imagen')->whereNotNull('revocado_at')->exists()) {
                $otra = $finalidad === 'datos' ? 'imagen' : 'datos';
                if (! DB::table('consentimientos')->where('titular_id', $alumnoId)->where('finalidad', $otra)->exists()) {
                    DB::table('consentimientos')->insert([
                        'institucion_id' => $institucion, 'titular_tipo' => 'alumno', 'titular_id' => $alumnoId, 'otorgado_por' => $usuarioId,
                        'finalidad' => $otra, 'version_texto' => $antes['version'] ?? $texto['version'], 'otorgado' => true,
                    ]);
                }
            }
        } else {
            DB::table('consentimientos')->insert([
                'institucion_id' => $institucion, 'titular_tipo' => 'alumno', 'titular_id' => $alumnoId, 'otorgado_por' => $usuarioId,
                'finalidad' => $finalidad, 'version_texto' => $texto['version'], 'otorgado' => $otorgado,
            ]);
        }
        $this->auditoria->registrar('consentimiento_registrado', 'consentimientos', $alumnoId, ['estado' => $antes['estado']],
            ['finalidad' => $finalidad, 'otorgado' => $otorgado, 'version' => $texto['version'], 'origen' => $origen]);
    }
}
