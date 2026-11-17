<?php

namespace App\Modulos\DOC\Services;

use App\Modulos\COM\Services\Notificador;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Models\Archivo;
use App\Modulos\SEG\Services\AlmacenArchivos;
use App\Modulos\SEG\Services\Auditoria;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-119 / EDUN-120 · Expediente digital del alumno y del personal.
 *
 * - Carga por categoría con fecha de vencimiento opcional (DNI, colegiatura, contrato…).
 * - Estado del vencimiento: vigente, por vencer (30 días) o vencido; alertas diarias a quien
 *   gestiona (secretaría para alumnos, dirección para personal) a los 30 y 7 días y el mismo día.
 * - El contrato del personal (personal.contrato_fin) también entra en las alertas.
 * - Los archivos son confidenciales: solo se descargan con URL firmada que vence.
 */
class Expedientes
{
    public const CATEGORIAS = [
        'alumno' => ['partida_nacimiento' => 'Partida de nacimiento', 'dni' => 'DNI o carné de extranjería', 'certificado_estudios' => 'Certificado de estudios',
            'ficha_matricula' => 'Ficha de matrícula', 'resolucion_traslado' => 'Resolución de traslado', 'salud' => 'Constancia de salud o vacunas', 'otro' => 'Otro'],
        'personal' => ['titulo' => 'Título profesional', 'colegiatura' => 'Colegiatura', 'contrato' => 'Contrato', 'antecedentes' => 'Antecedentes penales y policiales',
            'dni' => 'DNI', 'cv' => 'Currículum', 'salud' => 'Certificado de salud', 'otro' => 'Otro'],
    ];

    public const DIAS_AVISO = 30;

    public function __construct(private AlmacenArchivos $almacen, private Auditoria $auditoria, private Notificador $notificador) {}

    public function lista(string $tipo, int $titularId): array
    {
        $this->titular($tipo, $titularId);

        return DB::table('expediente_documentos as e')->join('archivos as a', 'a.id', '=', 'e.archivo_id')->leftJoin('users as u', 'u.id', '=', 'e.verificado_por')
            ->where('e.titular_tipo', $tipo)->where('e.titular_id', $titularId)->orderBy('e.categoria')->orderByDesc('e.id')
            ->get(['e.id', 'e.categoria', 'e.nombre', 'e.vence', 'e.created_at', 'a.nombre_original', 'a.mime', DB::raw("trim(coalesce(u.nombres, '') || ' ' || coalesce(u.apellidos, '')) as verificado_por")])
            ->map(fn ($d) => array_merge((array) $d, ['categoria_nombre' => self::CATEGORIAS[$tipo][$d->categoria] ?? $d->categoria, 'vencimiento' => self::estado($d->vence), 'verificado_por' => $d->verificado_por ?: null]))->all();
    }

    public function subir(string $tipo, int $titularId, string $categoria, ?string $nombre, UploadedFile $archivo, ?string $vence, int $usuarioId): array
    {
        $this->titular($tipo, $titularId);
        if (! isset(self::CATEGORIAS[$tipo][$categoria])) {
            throw ValidationException::withMessages(['categoria' => ['Esa categoría no existe.']]);
        }
        $a = $this->almacen->guardar($archivo, $usuarioId, true);
        $id = (int) DB::table('expediente_documentos')->insertGetId([
            'institucion_id' => ContextoInstitucion::requerido(), 'titular_tipo' => $tipo, 'titular_id' => $titularId, 'categoria' => $categoria,
            'nombre' => mb_substr(trim($nombre ?: self::CATEGORIAS[$tipo][$categoria]), 0, 150), 'archivo_id' => $a->id, 'vence' => $vence,
        ]);
        $this->auditoria->registrar('expediente_documento_subido', 'expediente_documentos', $id, null, ['titular' => "{$tipo}:{$titularId}", 'categoria' => $categoria, 'vence' => $vence]);

        return collect($this->lista($tipo, $titularId))->firstWhere('id', $id);
    }

    public function actualizar(int $id, ?string $vence, bool $verificar, int $usuarioId): array
    {
        $d = DB::table('expediente_documentos')->find($id);
        abort_if(! $d, 404);
        DB::table('expediente_documentos')->where('id', $id)->update(['vence' => $vence] + ($verificar ? ['verificado_por' => $usuarioId] : []));
        $this->auditoria->registrar('expediente_documento_actualizado', 'expediente_documentos', $id, ['vence' => $d->vence], ['vence' => $vence, 'verificado' => $verificar]);

        return collect($this->lista($d->titular_tipo, (int) $d->titular_id))->firstWhere('id', $id);
    }

    /** Quita el documento del expediente (queda en la auditoría; el archivo no se borra del almacén). */
    public function quitar(int $id): void
    {
        $d = DB::table('expediente_documentos')->find($id);
        abort_if(! $d, 404);
        DB::table('expediente_documentos')->where('id', $id)->delete();
        $this->auditoria->registrar('expediente_documento_quitado', 'expediente_documentos', $id, ['categoria' => $d->categoria, 'nombre' => $d->nombre, 'archivo_id' => $d->archivo_id], null);
    }

    public function url(int $id): string
    {
        $d = DB::table('expediente_documentos')->find($id, ['archivo_id']);
        abort_if(! $d, 404);

        return $this->almacen->urlTemporal(Archivo::query()->findOrFail($d->archivo_id));
    }

    /**
     * Documentos vencidos o por vencer (y contratos del personal) para la bandeja de alertas.
     *
     * @return list<array<string, mixed>>
     */
    public function porVencer(?string $tipo = null, int $dias = self::DIAS_AVISO): array
    {
        $limite = now()->addDays($dias)->toDateString();
        $docs = DB::table('expediente_documentos as e')
            ->leftJoin('alumnos as a', fn ($j) => $j->on('a.id', '=', 'e.titular_id')->where('e.titular_tipo', '=', 'alumno'))
            ->leftJoin('personal as p', fn ($j) => $j->on('p.id', '=', 'e.titular_id')->where('e.titular_tipo', '=', 'personal'))
            ->whereNotNull('e.vence')->where('e.vence', '<=', $limite)->when($tipo, fn ($q, $t) => $q->where('e.titular_tipo', $t))
            ->orderBy('e.vence')->limit(300)
            ->get(['e.id', 'e.titular_tipo', 'e.titular_id', 'e.categoria', 'e.nombre', 'e.vence',
                DB::raw("coalesce(a.apellido_paterno || ', ' || a.nombres, p.apellidos || ', ' || p.nombres) as titular")])
            ->map(fn ($d) => array_merge((array) $d, ['vencimiento' => self::estado($d->vence)]));
        $contratos = $tipo === 'alumno' ? collect() : DB::table('personal')->where('estado', 'activo')->whereNotNull('contrato_fin')->where('contrato_fin', '<=', $limite)
            ->orderBy('contrato_fin')->get(['id', 'apellidos', 'nombres', 'contrato_fin'])
            ->map(fn ($p) => ['id' => null, 'titular_tipo' => 'personal', 'titular_id' => (int) $p->id, 'categoria' => 'contrato', 'nombre' => 'Fin de contrato', 'vence' => $p->contrato_fin,
                'titular' => "{$p->apellidos}, {$p->nombres}", 'vencimiento' => self::estado($p->contrato_fin)]);

        return $docs->concat($contratos)->sortBy('vence')->values()->all();
    }

    /** Aviso diario (doc:vencimientos): a los 30 y 7 días y el mismo día del vencimiento. */
    public function avisarVencimientos(): int
    {
        $hitos = [now()->toDateString(), now()->addDays(7)->toDateString(), now()->addDays(30)->toDateString()];
        $avisos = collect($this->porVencer())->filter(fn ($d) => in_array(substr((string) $d['vence'], 0, 10), $hitos, true));
        $n = 0;
        foreach (['alumno' => ['secretaria', 'director'], 'personal' => ['director', 'promotor']] as $tipo => $roles) {
            $grupo = $avisos->where('titular_tipo', $tipo);
            if ($grupo->isEmpty()) {
                continue;
            }
            $usuarios = DB::table('membresias as m')->join('roles as r', 'r.id', '=', 'm.rol_id')->whereIn('r.codigo', $roles)->pluck('m.user_id')->unique()->all();
            $detalle = $grupo->take(3)->map(fn ($d) => "{$d['nombre']} de {$d['titular']} ({$d['vencimiento']['texto']})")->implode('; ');
            $this->notificador->enviar($usuarios, ['app'], 'tramites', $tipo === 'alumno' ? 'Documentos de alumnos por vencer' : 'Documentos del personal por vencer',
                $detalle.($grupo->count() > 3 ? ' y '.($grupo->count() - 3).' más.' : '.'), ['pantalla' => 'expedientes']);
            $n += $grupo->count();
        }

        return $n;
    }

    /** @return array{codigo: string, texto: string}|null */
    public static function estado(?string $vence): ?array
    {
        if (! $vence) {
            return null;
        }
        $dias = (int) now()->startOfDay()->diffInDays(Carbon::parse($vence)->startOfDay(), false);

        return $dias < 0 ? ['codigo' => 'vencido', 'texto' => 'vencido hace '.abs($dias).' día(s)']
            : ($dias <= self::DIAS_AVISO ? ['codigo' => 'por_vencer', 'texto' => $dias === 0 ? 'vence hoy' : "vence en {$dias} día(s)"] : ['codigo' => 'vigente', 'texto' => 'vigente']);
    }

    private function titular(string $tipo, int $id): void
    {
        $existe = $tipo === 'alumno' ? DB::table('alumnos')->where('id', $id)->whereNull('eliminado_at')->exists() : DB::table('personal')->where('id', $id)->exists();
        abort_if(! $existe, 404, $tipo === 'alumno' ? 'El alumno no existe.' : 'La persona no está en el personal.');
    }
}
