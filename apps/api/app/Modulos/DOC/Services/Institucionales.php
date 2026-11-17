<?php

namespace App\Modulos\DOC\Services;

use App\Modulos\COM\Services\Notificador;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Models\Archivo;
use App\Modulos\SEG\Services\AlmacenArchivos;
use App\Modulos\SEG\Services\Auditoria;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-121 / DOC-H09 · Documentos institucionales (reglamento interno, PEI, normas de convivencia).
 *
 * - Versiones: publicar una versión nueva del mismo tipo y nombre deja la anterior como histórica
 *   (vigente = false); las familias ven solo la vigente.
 * - Confirmación de lectura: si el documento la pide, las familias marcan «Leí y acepto» y la
 *   dirección ve quién falta; la confirmación es por versión.
 */
class Institucionales
{
    public const TIPOS = ['reglamento' => 'Reglamento interno', 'pei' => 'Proyecto educativo institucional (PEI)', 'convivencia' => 'Normas de convivencia',
        'calendario' => 'Calendario', 'protocolo' => 'Protocolo', 'otro' => 'Otro'];

    public function __construct(private AlmacenArchivos $almacen, private Auditoria $auditoria, private Notificador $notificador) {}

    public function lista(): array
    {
        $familias = $this->familias()->count();

        return DB::table('documentos_institucionales as d')->join('archivos as a', 'a.id', '=', 'd.archivo_id')->orderByDesc('d.vigente')->orderBy('d.tipo')->orderByDesc('d.id')
            ->get(['d.id', 'd.tipo', 'd.nombre', 'd.version', 'd.publicado_at', 'd.requiere_lectura', 'd.vigente', 'a.nombre_original',
                DB::raw('(select count(*) from documento_lecturas l where l.documento_institucional_id = d.id) as lecturas')])
            ->map(fn ($d) => array_merge((array) $d, ['tipo_nombre' => self::TIPOS[$d->tipo] ?? $d->tipo, 'lecturas' => (int) $d->lecturas, 'familias' => $familias]))->all();
    }

    public function publicar(string $tipo, string $nombre, string $version, UploadedFile $archivo, bool $requiereLectura, int $usuarioId): array
    {
        if (! isset(self::TIPOS[$tipo])) {
            throw ValidationException::withMessages(['tipo' => ['Ese tipo de documento no existe.']]);
        }
        $nombre = trim($nombre);
        if (DB::table('documentos_institucionales')->where('tipo', $tipo)->where('nombre', $nombre)->where('version', trim($version))->exists()) {
            throw ValidationException::withMessages(['version' => ['Esa versión ya está publicada: usa otro número de versión.']]);
        }
        $a = $this->almacen->guardar($archivo, $usuarioId);
        $id = DB::transaction(function () use ($tipo, $nombre, $version, $a, $requiereLectura, $usuarioId) {
            DB::table('documentos_institucionales')->where('tipo', $tipo)->where('nombre', $nombre)->update(['vigente' => false]);

            return (int) DB::table('documentos_institucionales')->insertGetId([
                'institucion_id' => ContextoInstitucion::requerido(), 'tipo' => $tipo, 'nombre' => $nombre, 'version' => mb_substr(trim($version), 0, 20),
                'archivo_id' => $a->id, 'publicado_at' => now(), 'requiere_lectura' => $requiereLectura, 'publicado_por' => $usuarioId, 'vigente' => true,
            ]);
        });
        $this->auditoria->registrar('documento_institucional_publicado', 'documentos_institucionales', $id, null, ['tipo' => $tipo, 'nombre' => $nombre, 'version' => $version]);
        $this->notificador->enviar($this->familias()->all(), ['app'], 'comunicados', "Nuevo documento del colegio: {$nombre}",
            $requiereLectura ? 'Léelo y confirma la lectura en la app.' : "Versión {$version}. Ya puedes leerlo en la app.", ['pantalla' => 'documentos', 'id' => $id]);

        return collect($this->lista())->firstWhere('id', $id);
    }

    /** Quiénes leyeron y quiénes faltan (familias con cuenta). */
    public function lecturas(int $id): array
    {
        abort_if(! DB::table('documentos_institucionales')->where('id', $id)->exists(), 404);
        $leidos = DB::table('documento_lecturas')->where('documento_institucional_id', $id)->pluck('leido_at', 'user_id');
        $personas = DB::table('users')->whereIn('id', $this->familias())->orderBy('apellidos')->get(['id', 'nombres', 'apellidos']);

        return $personas->map(fn ($u) => ['user_id' => (int) $u->id, 'nombre' => trim("{$u->apellidos}, {$u->nombres}"), 'leido_at' => $leidos[$u->id] ?? null])->sortBy(fn ($p) => $p['leido_at'] !== null)->values()->all();
    }

    public function recordar(int $id): int
    {
        $d = DB::table('documentos_institucionales')->find($id);
        abort_if(! $d, 404);
        $faltan = collect($this->lecturas($id))->whereNull('leido_at')->pluck('user_id')->all();

        return $this->notificador->enviar($faltan, ['app'], 'comunicados', "Falta confirmar: {$d->nombre}", 'Léelo y confirma la lectura en la app.', ['pantalla' => 'documentos', 'id' => $id])['app'];
    }

    /** Documentos vigentes para la familia, con su confirmación. */
    public function deFamilia(int $usuarioId): array
    {
        $leidos = DB::table('documento_lecturas')->where('user_id', $usuarioId)->pluck('leido_at', 'documento_institucional_id');

        return DB::table('documentos_institucionales')->where('vigente', true)->whereNotNull('publicado_at')->orderBy('tipo')->get(['id', 'tipo', 'nombre', 'version', 'publicado_at', 'requiere_lectura'])
            ->map(fn ($d) => array_merge((array) $d, ['tipo_nombre' => self::TIPOS[$d->tipo] ?? $d->tipo, 'leido_at' => $leidos[$d->id] ?? null]))->all();
    }

    public function marcarLeido(int $id, int $usuarioId): void
    {
        abort_if(! DB::table('documentos_institucionales')->where('id', $id)->where('vigente', true)->exists(), 404);
        DB::table('documento_lecturas')->insertOrIgnore(['institucion_id' => ContextoInstitucion::requerido(), 'documento_institucional_id' => $id, 'user_id' => $usuarioId]);
    }

    public function url(int $id, bool $soloVigente = false): string
    {
        $d = DB::table('documentos_institucionales')->where('id', $id)->when($soloVigente, fn ($q) => $q->where('vigente', true))->first(['archivo_id']);
        abort_if(! $d, 404);

        return $this->almacen->urlTemporal(Archivo::query()->findOrFail($d->archivo_id));
    }

    /** Usuarios de las familias (apoderados con cuenta) del colegio activo. */
    private function familias()
    {
        return DB::table('apoderados as p')->join('membresias as m', 'm.user_id', '=', 'p.user_id')->join('roles as r', 'r.id', '=', 'm.rol_id')
            ->where('r.codigo', 'apoderado')->whereNotNull('p.user_id')->distinct()->pluck('p.user_id');
    }
}
