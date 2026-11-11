<?php

namespace Database\Seeders;

use App\Modulos\COM\Services\Comunicados;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\PAG\Services\Cobros;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Datos de prueba para recorrer el sistema en el servidor de pruebas: SOLO sobre el colegio
 * ficticio «smp» (IEP San Martín de Porres). Idempotente: cada bloque se salta si ya tiene datos.
 *
 * - Asistencia de los últimos 15 días hábiles (mayoría presentes, algunas tardanzas y faltas).
 * - Dos justificaciones pendientes de familias con cuenta (EDUN-96), para que el tutor las vea.
 * - Pagos en efectivo de la mitad de los cargos ya vencidos (estado de cuenta y reportes).
 * - Dos comunicados enviados, uno con confirmación de lectura.
 * - Sesiones del chatbot de ejemplo para el historial del CMS.
 *
 * Uso: php artisan db:seed --class=DatosPruebaSeeder --force (en producción exige
 * EDUNOVA_DATOS_PRUEBA=true en el entorno del comando, para no correrlo por error).
 */
class DatosPruebaSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production') && ! filter_var(env('EDUNOVA_DATOS_PRUEBA', false), FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException('En producción solo con EDUNOVA_DATOS_PRUEBA=true (servidor de pruebas).');
        }
        $db = DB::connection('propietario');
        $inst = (int) $db->table('instituciones')->where('slug', 'smp')->value('id');
        if (! $inst) {
            throw new RuntimeException('No está el colegio ficticio «smp».');
        }
        $alumnos = $db->table('matriculas')->where('institucion_id', $inst)->where('estado', 'matriculado')->get(['alumno_id', 'seccion_id']);
        $hecho = [];

        // Asistencia de 15 días hábiles (determinista: siempre los mismos estados).
        if (! $db->table('asistencias')->where('institucion_id', $inst)->exists() && $alumnos->isNotEmpty()) {
            $usuario = (int) $db->table('membresias')->where('institucion_id', $inst)->value('user_id');
            $dias = collect(range(1, 25))->map(fn ($n) => now('America/Lima')->subDays($n))->filter(fn (Carbon $d) => $d->isWeekday())->take(15);
            $filas = [];
            foreach ($dias as $d) {
                foreach ($alumnos as $k => $a) {
                    $r = crc32($a->alumno_id.$d->toDateString()) % 100;
                    $estado = $r < 6 ? 'F' : ($r < 14 ? 'T' : 'P');
                    $hora = $estado === 'F' ? null : ($estado === 'T' ? '08:'.str_pad((string) (12 + $r % 30), 2, '0', STR_PAD_LEFT).':00' : '07:'.str_pad((string) (30 + $r % 25), 2, '0', STR_PAD_LEFT).':00');
                    $filas[] = ['institucion_id' => $inst, 'alumno_id' => $a->alumno_id, 'seccion_id' => $a->seccion_id, 'fecha' => $d->toDateString(), 'estado' => $estado,
                        'hora_registro' => $hora, 'origen' => $k % 3 === 0 ? 'qr' : 'web', 'registrado_por' => $usuario, 'registrado_at' => $d->copy()->setTime(8, 30)];
                }
            }
            $db->table('asistencias')->insert($filas);
            $hecho[] = count($filas).' registros de asistencia';
        }

        // Justificaciones pendientes de familias con cuenta.
        if (! $db->table('justificaciones_inasistencia')->where('institucion_id', $inst)->exists()) {
            $conCuenta = fn () => $db->table('asistencias as x')->join('alumno_apoderado as aa', 'aa.alumno_id', '=', 'x.alumno_id')->join('apoderados as p', 'p.id', '=', 'aa.apoderado_id')
                ->where('x.institucion_id', $inst)->whereNotNull('p.user_id')->where('x.fecha', '<', now()->toDateString());
            $faltas = $conCuenta()->where('x.estado', 'F')->orderByDesc('x.fecha')->limit(2)->get(['x.alumno_id', 'x.fecha', 'p.user_id']);
            if ($faltas->count() < 2) {
                // Las familias con cuenta no tienen faltas: se marcan dos días recientes como falta para poder justificarlos.
                $ids = $conCuenta()->where('x.estado', '<>', 'F')->orderByDesc('x.fecha')->limit(2 - $faltas->count())->pluck('x.id');
                $db->table('asistencias')->whereIn('id', $ids)->update(['estado' => 'F', 'hora_registro' => null]);
                $faltas = $conCuenta()->where('x.estado', 'F')->orderByDesc('x.fecha')->limit(2)->get(['x.alumno_id', 'x.fecha', 'p.user_id']);
            }
            foreach ($faltas as $i => $f) {
                $db->table('justificaciones_inasistencia')->insert(['institucion_id' => $inst, 'alumno_id' => $f->alumno_id, 'fecha_desde' => $f->fecha, 'fecha_hasta' => $f->fecha,
                    'motivo' => ['Tuvo fiebre; adjunto la receta en la próxima visita.', 'Cita médica con el pediatra.'][$i % 2], 'solicitado_por' => $f->user_id]);
            }
            $hecho[] = count($faltas).' justificaciones pendientes';
        }

        // Pagos de la mitad de los cargos vencidos.
        if (! $db->table('pagos')->where('institucion_id', $inst)->exists()) {
            $porPagar = fn () => $db->table('cargos')->where('institucion_id', $inst)->whereIn('estado', ['pendiente', 'parcial'])->orderBy('vencimiento')->orderBy('id');
            $cargos = $porPagar()->where('vencimiento', '<', now()->toDateString())->get(['id', 'monto', 'descuento', 'mora', 'pagado']);
            if ($cargos->isEmpty()) {
                // Sin cargos vencidos (año escolar futuro): se pagan los próximos a vencer.
                $cargos = $porPagar()->get(['id', 'monto', 'descuento', 'mora', 'pagado']);
            }
            $pagados = 0;
            DB::transaction(function () use ($inst, $cargos, &$pagados) {
                ContextoInstitucion::fijar($inst);
                foreach ($cargos as $k => $c) {
                    if ($k % 2 === 1) {
                        continue;
                    }
                    $saldo = round((float) $c->monto - (float) $c->descuento + (float) $c->mora - (float) $c->pagado, 2);
                    if ($saldo > 0) {
                        app(Cobros::class)->registrar([['id' => (int) $c->id, 'monto' => $saldo]], [], ['medio' => $k % 4 === 0 ? 'efectivo' : 'yape', 'referencia' => 'Datos de prueba'], null);
                        $pagados++;
                    }
                }
            });
            $hecho[] = "{$pagados} pagos";
        }

        // Comunicados enviados.
        if ($db->table('comunicados')->where('institucion_id', $inst)->where('estado', 'enviado')->count() < 2) {
            $autor = (int) $db->table('membresias as m')->join('roles as r', 'r.id', '=', 'm.rol_id')->where('m.institucion_id', $inst)->where('r.codigo', 'director')->value('m.user_id')
                ?: (int) $db->table('membresias')->where('institucion_id', $inst)->value('user_id');
            DB::transaction(function () use ($inst, $autor) {
                ContextoInstitucion::fijar($inst);
                foreach ([['Reunión de padres de familia', 'Los esperamos el viernes a las 6:00 p. m. en el auditorio para presentar el avance del bimestre.', true],
                    ['Feriado del lunes', 'El lunes no habrá clases por feriado nacional. Las clases se reanudan el martes en el horario habitual.', false]] as [$titulo, $texto, $confirmar]) {
                    $id = (int) DB::table('comunicados')->insertGetId(['institucion_id' => $inst, 'titulo' => $titulo, 'contenido' => $texto, 'alcance' => json_encode(['tipo' => 'colegio']),
                        'canales' => 'app', 'requiere_confirmacion' => $confirmar, 'autor_id' => $autor, 'estado' => 'borrador']);
                    app(Comunicados::class)->enviar($id);
                }
            });
            $hecho[] = '2 comunicados';
        }

        // Sesiones del chatbot (historial del CMS).
        if (! $db->table('cms.chatbot_sesiones')->exists()) {
            foreach ([['whatsapp', '/', ['Edunova: ¿Qué necesitas?', 'Tú: Hablar con un asesor']], ['url', '/planes', ['Edunova: ¿Qué necesitas?', 'Tú: Ver precios', 'Edunova: Aquí están los planes.']],
                ['fin', '/funcionalidades', ['Edunova: ¿Qué necesitas?', 'Tú: Solo estoy mirando', 'Edunova: ¡Gracias por visitarnos!']], ['abandono', '/', ['Edunova: ¿Qué necesitas?']]] as $k => [$resultado, $pagina, $recorrido]) {
                $sesion = (int) $db->table('cms.chatbot_sesiones')->insertGetId(['sesion_uuid' => (string) Str::uuid(), 'nodos_visitados' => json_encode($recorrido, JSON_UNESCAPED_UNICODE),
                    'resultado' => $resultado, 'pagina' => $pagina, 'created_at' => now()->subDays($k + 1)]);
                foreach ($recorrido as $paso) {
                    $db->table('cms.chatbot_mensajes')->insert(['sesion_id' => $sesion, 'rol' => str_starts_with($paso, 'Tú:') ? 'visitante' : 'bot', 'contenido' => $paso, 'created_at' => now()->subDays($k + 1)]);
                }
            }
            $hecho[] = '4 sesiones del chatbot';
        }

        $this->command?->info($hecho ? 'Datos de prueba: '.implode(', ', $hecho).'.' : 'Datos de prueba: ya estaban cargados.');
    }
}
