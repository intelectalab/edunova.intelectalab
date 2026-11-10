<?php

namespace App\Modulos\PLA\Services;

use App\Modulos\SEG\Services\Auditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-212 · Lista de control para dar de alta un colegio (equipo de implementación).
 *
 * Recorre el alta, módulos y plan, usuarios, importación desde Excel y pasarela; cada paso dice
 * quién lo hace y cómo se verifica. Los pasos que el sistema puede comprobar se marcan solos;
 * los demás los marca el equipo. La migración desde el sistema anterior del colegio queda
 * pendiente mientras siga abierta la decisión D-07 (formato de exportación).
 */
class ChecklistImplementacion
{
    /** Pasos que se marcan a mano (los demás los comprueba el sistema). */
    public const MANUALES = ['pasarela', 'migracion', 'capacitacion'];

    public function __construct(private Auditoria $auditoria) {}

    private function db()
    {
        return DB::connection(ConsolaInstituciones::CONEXION);
    }

    /** @return list<array{clave: string, paso: string, quien: string, verificacion: string, historia: string, hecho: bool, manual: bool, nota: string|null}> */
    public function estado(int $id): array
    {
        $db = $this->db();
        $i = $db->table('instituciones')->find($id);
        abort_if(! $i, 404);
        $manual = json_decode($i->configuracion ?? '{}', true)['checklist'] ?? [];
        $cuenta = fn (string $tabla) => $db->table($tabla)->where('institucion_id', $id)->count();
        $anio = $db->table('anios_escolares')->where('institucion_id', $id)->orderByRaw("estado = 'abierto' desc")->orderByDesc('anio')->value('id');
        $sensibles = $db->table('membresias as m')->join('roles as r', 'r.id', '=', 'm.rol_id')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.institucion_id', $id)->whereIn('r.codigo', ['promotor', 'director'])->where('m.activo', true);
        $suscripcion = ModulosColegio::suscripcion(ConsolaInstituciones::CONEXION, $id);

        $pasos = [
            ['alta', 'Alta con códigos modulares, subdominio y promotor', 'Edunova (superadmin)', 'Cada nivel tiene su código modular y el colegio está creado.', 'PLA-H01',
                $cuenta('servicios_educativos') > 0],
            ['modulos', 'Plan, módulos y opción de aula', 'Edunova (comercial)', 'Suscripción vigente con plan, módulos extra y aula elegida.', 'PLA-H02 / PLA-H03',
                $suscripcion !== null],
            ['promotor', 'El promotor acepta la invitación y activa el segundo factor', 'Promotor', 'El promotor tiene contraseña y segundo factor activos.', 'SEG-H02 / SEG-H04',
                (clone $sensibles)->where('r.codigo', 'promotor')->whereNotNull('u.password')->whereNotNull('u.dos_pasos_activo_at')->exists()],
            ['datos', 'Datos MINEDU, contacto, logo y color', 'Director', 'Hay correo o teléfono del colegio y su logo o color.', 'ADM-H01',
                ($i->correo || $i->telefono) && ($i->logo_archivo_id || $i->color_primario)],
            ['anio', 'Año escolar con periodos y feriados', 'Director', 'Existe el año escolar con sus periodos.', 'ADM-H02',
                $anio !== null && $db->table('periodos')->where('anio_escolar_id', $anio)->exists()],
            ['plan', 'Plan de estudios y secciones', 'Coordinador', 'Los grados tienen áreas y el año tiene secciones.', 'ADM-H03 / ADM-H04',
                $anio !== null && $db->table('area_curricular_grado')->where('anio_escolar_id', $anio)->exists() && $db->table('secciones')->where('anio_escolar_id', $anio)->exists()],
            ['usuarios', 'Personal y usuarios invitados por rol', 'Director', 'Hay personal registrado y usuarios del colegio además del promotor.', 'ADM-H05 / SEG-H02',
                $cuenta('personal') > 0 && $db->table('membresias as m')->join('roles as r', 'r.id', '=', 'm.rol_id')->where('m.institucion_id', $id)->whereNotIn('r.codigo', ['promotor', 'apoderado', 'alumno'])->exists()],
            ['carga', 'Carga docente y horarios', 'Coordinador', 'Las secciones tienen docentes asignados.', 'ADM-H06 / ADM-H07',
                $anio !== null && $db->table('cargas_docentes as c')->join('secciones as s', 's.id', '=', 'c.seccion_id')->where('s.anio_escolar_id', $anio)->exists()],
            ['alumnos', 'Importación de alumnos y apoderados desde Excel', 'Secretaría', 'Hay alumnos matriculados en el año (y sus apoderados invitados).', 'ADM-H09 / SEG-H03',
                $anio !== null && $db->table('matriculas')->where('anio_escolar_id', $anio)->exists()],
            ['condiciones', 'Condiciones económicas publicadas', 'Director', 'El año tiene una versión publicada de las condiciones económicas.', 'ADM-H12',
                $anio !== null && $db->table('condiciones_economicas')->where('anio_escolar_id', $anio)->whereNotNull('publicado_at')->exists()],
            ['pasarela', 'Pasarela de pagos configurada', 'Tesorería con Edunova', 'Prueba de cobro aprobada en la pasarela (se marca a mano).', 'PAG-H21', false],
            ['migracion', 'Migración desde el sistema anterior del colegio', 'Equipo de implementación', 'Deudas y datos del sistema anterior cargados y conciliados (se marca a mano).', 'D-07', false],
            ['capacitacion', 'Capacitación por rol', 'Equipo de implementación', 'Cada rol recibió su capacitación (se marca a mano).', 'Implementación', false],
        ];

        return array_map(function ($p) use ($manual) {
            [$clave, $paso, $quien, $verificacion, $historia, $auto] = $p;
            $esManual = in_array($clave, self::MANUALES, true);
            $nota = match ($clave) {
                'pasarela' => 'El módulo de pasarela llega con Pagos (PAG-H21): mientras tanto queda pendiente.',
                'migracion' => 'Pendiente mientras siga abierta la decisión D-07 sobre el formato de exportación del sistema anterior.',
                default => null,
            };

            return ['clave' => $clave, 'paso' => $paso, 'quien' => $quien, 'verificacion' => $verificacion, 'historia' => $historia,
                'hecho' => $esManual ? (bool) ($manual[$clave] ?? false) : (bool) $auto, 'manual' => $esManual, 'nota' => $nota];
        }, $pasos);
    }

    public function marcar(int $id, string $clave, bool $hecho): array
    {
        if (! in_array($clave, self::MANUALES, true)) {
            throw ValidationException::withMessages(['paso' => ['Ese paso lo comprueba el sistema.']]);
        }
        $cfg = json_decode((string) $this->db()->table('instituciones')->where('id', $id)->value('configuracion'), true) ?: [];
        $cfg['checklist'][$clave] = $hecho;
        $this->db()->table('instituciones')->where('id', $id)->update(['configuracion' => json_encode($cfg, JSON_UNESCAPED_UNICODE)]);
        $this->auditoria->registrar('checklist_marcado', 'instituciones', $id, null, ['paso' => $clave, 'hecho' => $hecho], $id, ConsolaInstituciones::CONEXION);

        return $this->estado($id);
    }

    /** Pasar de implementación a activo cuando la lista lo permite. */
    public function activar(int $id): void
    {
        $i = $this->db()->table('instituciones')->find($id);
        abort_if(! $i, 404);
        if ($i->estado !== 'implementacion') {
            throw ValidationException::withMessages(['estado' => ['Solo se activa un colegio en implementación.']]);
        }
        $faltan = array_filter($this->estado($id), fn ($p) => ! $p['hecho'] && in_array($p['clave'], ['alta', 'modulos', 'promotor', 'anio', 'alumnos'], true));
        if ($faltan) {
            throw ValidationException::withMessages(['estado' => ['Antes de activar falta: '.implode(', ', array_column($faltan, 'paso')).'.']]);
        }
        $this->db()->table('instituciones')->where('id', $id)->update(['estado' => 'activo']);
        $this->auditoria->registrar('colegio_activado', 'instituciones', $id, ['estado' => 'implementacion'], ['estado' => 'activo'], $id, ConsolaInstituciones::CONEXION);
    }
}
