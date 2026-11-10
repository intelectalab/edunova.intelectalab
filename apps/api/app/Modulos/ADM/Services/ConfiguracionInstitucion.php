<?php

namespace App\Modulos\ADM\Services;

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Models\Archivo;
use App\Modulos\SEG\Services\AlmacenArchivos;
use App\Modulos\SEG\Services\Auditoria;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-43 / ADM-H01 · Datos MINEDU, de contacto y de marca del colegio activo.
 *
 * - Varios códigos modulares: uno por nivel (servicios_educativos).
 * - Logo y color: la web y la app los leen de /auth/yo y /institucion/logo; las libretas,
 *   constancias y correos los tomarán de aquí cuando se construyan.
 * - El colegio no cambia su estado, slug ni subdominio (lo impiden los privilegios por columna).
 */
class ConfiguracionInstitucion
{
    public const CAMPOS = ['nombre', 'razon_social', 'ruc', 'codigo_local', 'ugel', 'dre', 'ubigeo', 'direccion', 'telefono', 'correo', 'color_primario'];

    public const CAMPOS_EXTRA = ['resolucion_creacion', 'horario_atencion', 'pagina_web', 'director_nombre'];

    public const ORDEN_NIVEL = ['inicial' => 1, 'primaria' => 2, 'secundaria' => 3];

    public function __construct(private Auditoria $auditoria, private AlmacenArchivos $almacen) {}

    public function datos(): array
    {
        $i = DB::table('instituciones')->where('id', ContextoInstitucion::requerido())->first();
        $extra = json_decode($i->configuracion ?? '{}', true) ?: [];

        return [
            ...collect(self::CAMPOS)->mapWithKeys(fn ($c) => [$c => $i->{$c} === null ? null : trim((string) $i->{$c})])->all(),
            ...collect(self::CAMPOS_EXTRA)->mapWithKeys(fn ($c) => [$c => $extra['datos'][$c] ?? null])->all(),
            'codigo_modular' => $i->codigo_modular,
            'nivel_educativo' => $i->nivel_educativo,
            'slug' => $i->slug,
            'subdominio' => ($i->subdominio ?? $i->slug).'.'.config('edunova.dominio_colegios'),
            'estado' => $i->estado,
            'tiene_logo' => $i->logo_archivo_id !== null,
            'servicios' => $this->servicios(),
        ];
    }

    /** @return list<array{id: int, nivel: string, codigo_modular: string, anexo: string, modalidad: string}> */
    public function servicios(): array
    {
        return DB::table('servicios_educativos')->get(['id', 'nivel', 'codigo_modular', 'anexo', 'modalidad'])
            ->sortBy(fn ($s) => self::ORDEN_NIVEL[$s->nivel] ?? 9)->values()
            ->map(fn ($s) => ['id' => (int) $s->id, 'nivel' => $s->nivel, 'codigo_modular' => $s->codigo_modular, 'anexo' => $s->anexo, 'modalidad' => $s->modalidad])->all();
    }

    /** @param  array<string, mixed>  $datos */
    public function guardar(array $datos): void
    {
        $id = ContextoInstitucion::requerido();
        $antes = $this->datos();
        $fila = DB::table('instituciones')->where('id', $id)->first(['configuracion']);
        $config = json_decode($fila->configuracion ?? '{}', true) ?: [];
        $config['datos'] = collect(self::CAMPOS_EXTRA)->mapWithKeys(fn ($c) => [$c => $datos[$c] ?? null])->all();

        $cambios = collect(self::CAMPOS)->mapWithKeys(fn ($c) => [$c => $datos[$c] ?? null])->all();
        if ($cambios['color_primario']) {
            $cambios['color_primario'] = strtolower($cambios['color_primario']);
        }
        try {
            DB::table('instituciones')->where('id', $id)->update($cambios + ['configuracion' => json_encode($config, JSON_UNESCAPED_UNICODE)]);
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw ValidationException::withMessages(['ruc' => ['Ese RUC ya está registrado en otro colegio.']]);
            }
            throw $e;
        }

        $despues = $this->datos();
        [$a, $d] = self::diferencias($antes, $despues, [...self::CAMPOS, ...self::CAMPOS_EXTRA]);
        if ($d !== []) {
            $this->auditoria->registrar('configuracion_editada', 'instituciones', $id, $a, $d);
        }
    }

    /** @param  list<array{nivel: string, codigo_modular: string, anexo?: string|null}>  $servicios */
    public function guardarServicios(array $servicios): void
    {
        $id = ContextoInstitucion::requerido();
        $niveles = array_column($servicios, 'nivel');
        if (count($niveles) !== count(array_unique($niveles))) {
            throw ValidationException::withMessages(['servicios' => ['Cada nivel va una sola vez, con su propio código modular.']]);
        }
        $codigos = array_column($servicios, 'codigo_modular');
        if (count($codigos) !== count(array_unique($codigos))) {
            throw ValidationException::withMessages(['servicios' => ['Cada nivel tiene un código modular distinto.']]);
        }
        $antes = $this->servicios();

        try {
            DB::transaction(function () use ($id, $servicios, $niveles, $antes) {
                foreach ($antes as $s) {
                    if (! in_array($s['nivel'], $niveles, true)) {
                        DB::table('servicios_educativos')->where('id', $s['id'])->delete();
                    }
                }
                foreach ($servicios as $s) {
                    DB::table('servicios_educativos')->updateOrInsert(
                        ['institucion_id' => $id, 'nivel' => $s['nivel'], 'modalidad' => 'EBR'],
                        ['codigo_modular' => $s['codigo_modular'], 'anexo' => $s['anexo'] ?? '0'],
                    );
                }
                $actual = (string) DB::table('instituciones')->where('id', $id)->value('codigo_modular');
                $principal = in_array($actual, array_column($servicios, 'codigo_modular'), true) ? $actual : $this->servicios()[0]['codigo_modular'];
                DB::table('instituciones')->where('id', $id)->update([
                    'codigo_modular' => $principal,
                    'nivel_educativo' => count($servicios) > 1 ? 'multinivel' : $servicios[0]['nivel'],
                ]);
            });
        } catch (QueryException $e) {
            throw match ($e->getCode()) {
                '23505' => ValidationException::withMessages(['servicios' => ['Uno de los códigos modulares ya está registrado en Edunova.']]),
                '23503' => ValidationException::withMessages(['servicios' => ['No puedes quitar un nivel que ya tiene grados, secciones o alumnos.']]),
                default => $e,
            };
        }

        $despues = array_map(fn ($s) => ['nivel' => $s['nivel'], 'codigo_modular' => $s['codigo_modular']], $this->servicios());
        $antesSimple = array_map(fn ($s) => ['nivel' => $s['nivel'], 'codigo_modular' => $s['codigo_modular']], $antes);
        if ($despues !== $antesSimple) {
            $this->auditoria->registrar('servicios_editados', 'servicios_educativos', null, ['servicios' => $antesSimple], ['servicios' => $despues]);
        }
    }

    public function guardarLogo(UploadedFile $logo, int $usuarioId): void
    {
        $id = ContextoInstitucion::requerido();
        $anterior = DB::table('instituciones')->where('id', $id)->value('logo_archivo_id');
        $archivo = $this->almacen->guardar($logo, $usuarioId);
        DB::table('instituciones')->where('id', $id)->update(['logo_archivo_id' => $archivo->id]);
        $this->auditoria->registrar('logo_cambiado', 'instituciones', $id, ['logo_archivo_id' => $anterior], ['logo_archivo_id' => $archivo->id]);
    }

    public function quitarLogo(): void
    {
        $id = ContextoInstitucion::requerido();
        $anterior = DB::table('instituciones')->where('id', $id)->value('logo_archivo_id');
        if ($anterior) {
            DB::table('instituciones')->where('id', $id)->update(['logo_archivo_id' => null]);
            $this->auditoria->registrar('logo_cambiado', 'instituciones', $id, ['logo_archivo_id' => $anterior], ['logo_archivo_id' => null]);
        }
    }

    public function logo(): ?Archivo
    {
        $archivoId = DB::table('instituciones')->where('id', ContextoInstitucion::requerido())->value('logo_archivo_id');

        return $archivoId ? Archivo::query()->find($archivoId) : null;
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} solo los campos que cambiaron */
    private static function diferencias(array $antes, array $despues, array $campos): array
    {
        $a = [];
        $d = [];
        foreach ($campos as $c) {
            if (($antes[$c] ?? null) !== ($despues[$c] ?? null)) {
                $a[$c] = $antes[$c] ?? null;
                $d[$c] = $despues[$c] ?? null;
            }
        }

        return [$a, $d];
    }
}
