<?php

namespace App\Modulos\MDL\Services;

use App\Models\User;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\Auditoria;
use App\Modulos\SEG\Services\Autorizacion;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * EDUN-143 a 149 · Moodle conectado. Edunova manda: usuarios, cursos, retiros y traslados viajan a
 * Moodle; Moodle no crea usuarios. Las notas vuelven como pendientes y el docente las aprueba.
 *
 * - Probar la conexión (versión y nombre del sitio) y estado activo, pausado o error.
 * - Inicio de sesión único: enlace firmado (HMAC con el secreto del colegio) válido por un minuto
 *   para el plugin auth_edunova (infra/moodle/auth_edunova).
 * - Cursos del año: uno por carga docente, en la categoría del año y del grado.
 * - Usuarios y matrículas: alumnos y docentes con cuenta; el retiro o traslado suspende (no borra).
 * - Notas: ítems de calificación por alumno, convertidos a AD/A/B/C con la tabla del colegio.
 * - Bitácora de cada sincronización con sus errores.
 */
class ConectorMoodle
{
    public const ROL_ALUMNO = 5;

    public const ROL_DOCENTE = 3;

    /** Tabla de conversión por defecto (porcentaje mínimo de la nota máxima). */
    public const CONVERSION = [['desde' => 90, 'valor' => 'AD'], ['desde' => 70, 'valor' => 'A'], ['desde' => 50, 'valor' => 'B'], ['desde' => 0, 'valor' => 'C']];

    public function __construct(private Auditoria $auditoria, private Autorizacion $autorizacion) {}

    public function conexion(): ?object
    {
        return DB::table('moodle_conexiones')->first();
    }

    public function cliente(?object $c = null): ClienteMoodle
    {
        $c ??= $this->conexion();
        if (! $c) {
            throw ValidationException::withMessages(['moodle' => ['Configura primero el conector de Moodle (dirección y token).']]);
        }

        return new ClienteMoodle($c->url, Crypt::decryptString($c->token_cifrado));
    }

    // ───────────── EDUN-143 · Probar la conexión

    public function probar(): array
    {
        $c = $this->conexion();
        try {
            $info = $this->cliente($c)->llamar('core_webservice_get_site_info');
            DB::table('moodle_conexiones')->where('id', $c->id)->update(['version' => mb_substr((string) ($info['release'] ?? ''), 0, 20), 'estado' => 'activo']);

            return ['ok' => true, 'sitio' => $info['sitename'] ?? null, 'version' => $info['release'] ?? null, 'simulado' => ClienteMoodle::simulado()];
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            DB::table('moodle_conexiones')->where('id', $c->id)->update(['estado' => 'error']);

            return ['ok' => false, 'mensaje' => $e->getMessage(), 'simulado' => ClienteMoodle::simulado()];
        }
    }

    public function pausar(bool $pausado): void
    {
        $c = $this->conexion();
        abort_if(! $c, 404);
        DB::table('moodle_conexiones')->where('id', $c->id)->update(['estado' => $pausado ? 'pausado' : 'activo']);
        $this->auditoria->registrar($pausado ? 'moodle_pausado' : 'moodle_reanudado', 'moodle_conexiones', (int) $c->id, null, null);
    }

    // ───────────── EDUN-144 · Inicio de sesión único

    /** Genera (o regenera) el secreto que se copia una sola vez en el plugin de Moodle. */
    public function regenerarSecreto(): string
    {
        $c = $this->conexion();
        abort_if(! $c, 404);
        $secreto = Str::random(48);
        DB::table('moodle_conexiones')->where('id', $c->id)->update(['sso_secreto_cifrado' => Crypt::encryptString($secreto)]);
        $this->auditoria->registrar('moodle_sso_secreto', 'moodle_conexiones', (int) $c->id, null, ['regenerado' => true]);

        return $secreto;
    }

    /** Enlace «Entrar a Moodle» sin contraseña: válido por un minuto, firmado con el secreto. */
    public function enlaceSso(User $usuario, ?int $cursoMoodle = null): string
    {
        $c = $this->conexion();
        if (! $c || ! $c->sso_activo || ! $c->sso_secreto_cifrado || $c->estado !== 'activo') {
            throw ValidationException::withMessages(['moodle' => ['El ingreso directo a Moodle no está disponible. Pídelo a dirección.']]);
        }
        $mu = DB::table('moodle_usuarios')->where('moodle_conexion_id', $c->id)->where('user_id', $usuario->id)->where('suspendido', false)->first();
        if (! $mu) {
            throw ValidationException::withMessages(['moodle' => ['Tu cuenta todavía no está en Moodle: se crea en la próxima sincronización.']]);
        }
        $vence = now()->addMinute()->timestamp;
        $firma = hash_hmac('sha256', "{$mu->username}|{$vence}", Crypt::decryptString($c->sso_secreto_cifrado));

        return $c->url.'/auth/edunova/login.php?'.http_build_query(array_filter(['u' => $mu->username, 't' => $vence, 's' => $firma, 'curso' => $cursoMoodle]));
    }

    // ───────────── EDUN-145 · Cursos del año

    public function crearCursos(): array
    {
        return $this->registrar('cursos', function (ClienteMoodle $m, object $c, array &$errores) {
            $pendientes = DB::table('moodle_cursos as mc')->join('cargas_docentes as cd', 'cd.id', '=', 'mc.carga_docente_id')->join('secciones as s', 's.id', '=', 'cd.seccion_id')
                ->join('grados as g', 'g.id', '=', 's.grado_id')->join('areas_curriculares as a', 'a.id', '=', 'cd.area_curricular_id')->join('anios_escolares as y', 'y.id', '=', 's.anio_escolar_id')
                ->where('mc.moodle_conexion_id', $c->id)->where('mc.estado', '!=', 'creado')
                ->get(['mc.id', 'cd.id as carga', 'a.nombre as area', 'g.nombre as grado', 's.nombre as seccion', 'y.anio']);
            $categorias = [];
            $n = 0;
            foreach ($pendientes as $p) {
                try {
                    $clave = "{$p->anio} · {$p->grado}";
                    $categorias[$clave] ??= (int) ($m->llamar('core_course_create_categories', ['categories' => [['name' => $clave, 'parent' => (int) ($c->categoria_raiz_id ?? 0)]]])[0]['id'] ?? 0);
                    $corto = Str::upper(Str::slug("{$p->anio}-{$p->grado}-{$p->seccion}-{$p->area}", '-'));
                    $curso = $m->llamar('core_course_create_courses', ['courses' => [['fullname' => "{$p->area} · {$p->grado} {$p->seccion} ({$p->anio})", 'shortname' => mb_substr($corto, 0, 100), 'categoryid' => $categorias[$clave]]]])[0];
                    DB::table('moodle_cursos')->where('id', $p->id)->update(['moodle_course_id' => (int) $curso['id'], 'shortname' => mb_substr($corto, 0, 100), 'estado' => 'creado']);
                    $n++;
                } catch (Throwable $e) {
                    DB::table('moodle_cursos')->where('id', $p->id)->update(['estado' => 'error']);
                    $errores[] = "{$p->area} {$p->grado} {$p->seccion}: {$e->getMessage()}";
                }
            }

            return $n;
        });
    }

    /** Crea las filas pendientes para las cargas del año que aún no tienen curso en Moodle. */
    public function prepararCursos(): int
    {
        $c = $this->conexion();
        abort_if(! $c, 404);
        $faltan = DB::table('cargas_docentes as cd')->join('secciones as s', 's.id', '=', 'cd.seccion_id')->join('anios_escolares as y', 'y.id', '=', 's.anio_escolar_id')
            ->leftJoin('moodle_cursos as mc', 'mc.carga_docente_id', '=', 'cd.id')->where('y.estado', 'abierto')->whereNull('mc.id')->pluck('cd.id');
        foreach ($faltan as $carga) {
            DB::table('moodle_cursos')->insertOrIgnore(['institucion_id' => ContextoInstitucion::requerido(), 'moodle_conexion_id' => $c->id, 'carga_docente_id' => $carga, 'estado' => 'pendiente']);
        }

        return $faltan->count();
    }

    // ───────────── EDUN-146 · Usuarios y matrículas

    public function sincronizarUsuarios(): array
    {
        return $this->registrar('usuarios', function (ClienteMoodle $m, object $c, array &$errores) {
            $n = 0;
            $cursos = DB::table('moodle_cursos')->where('moodle_conexion_id', $c->id)->where('estado', 'creado')->get()->keyBy('carga_docente_id');
            // Docentes de las cargas con curso creado.
            $docentes = DB::table('cargas_docentes as cd')->join('personal as p', 'p.id', '=', 'cd.personal_id')->join('users as u', 'u.id', '=', 'p.user_id')
                ->whereIn('cd.id', $cursos->keys())->get(['cd.id as carga', 'u.id as user_id', 'u.nombres', 'u.apellidos', 'u.email']);
            // Alumnos con cuenta matriculados en esas secciones.
            $alumnos = DB::table('cargas_docentes as cd')->join('matriculas as mt', 'mt.seccion_id', '=', 'cd.seccion_id')->join('alumnos as a', 'a.id', '=', 'mt.alumno_id')
                ->join('users as u', 'u.id', '=', 'a.user_id')->whereIn('cd.id', $cursos->keys())
                ->get(['cd.id as carga', 'u.id as user_id', 'u.nombres', 'u.apellidos', 'u.email', 'mt.estado']);
            foreach ([[$docentes, self::ROL_DOCENTE], [$alumnos, self::ROL_ALUMNO]] as [$lista, $rol]) {
                foreach ($lista as $x) {
                    try {
                        $mu = $this->asegurarUsuario($m, $c, $x);
                        $activo = ! isset($x->estado) || $x->estado === 'matriculado';
                        if ($activo) {
                            $m->llamar('enrol_manual_enrol_users', ['enrolments' => [['roleid' => $rol, 'userid' => $mu->moodle_user_id, 'courseid' => (int) $cursos[$x->carga]->moodle_course_id]]]);
                            $n++;
                        }
                    } catch (Throwable $e) {
                        $errores[] = "{$x->nombres} {$x->apellidos}: {$e->getMessage()}";
                    }
                }
            }
            // Retiro o traslado: se suspende en Moodle (no se borra).
            $suspender = DB::table('moodle_usuarios as mu')->join('alumnos as a', 'a.user_id', '=', 'mu.user_id')->where('mu.moodle_conexion_id', $c->id)->where('mu.suspendido', false)
                ->whereNotExists(fn ($q) => $q->from('matriculas as mt')->whereColumn('mt.alumno_id', 'a.id')->where('mt.estado', 'matriculado'))->get(['mu.id', 'mu.moodle_user_id']);
            foreach ($suspender as $s) {
                try {
                    $m->llamar('core_user_update_users', ['users' => [['id' => (int) $s->moodle_user_id, 'suspended' => 1]]]);
                    DB::table('moodle_usuarios')->where('id', $s->id)->update(['suspendido' => true]);
                    $n++;
                } catch (Throwable $e) {
                    $errores[] = "Suspender usuario {$s->moodle_user_id}: {$e->getMessage()}";
                }
            }

            return $n;
        });
    }

    // ───────────── EDUN-147 · Traer notas

    public function traerNotas(): array
    {
        return $this->registrar('notas', function (ClienteMoodle $m, object $c, array &$errores) {
            $n = 0;
            $usuarios = DB::table('moodle_usuarios as mu')->join('alumnos as a', 'a.user_id', '=', 'mu.user_id')->where('mu.moodle_conexion_id', $c->id)->pluck('a.id', 'mu.moodle_user_id');
            foreach (DB::table('moodle_cursos')->where('moodle_conexion_id', $c->id)->where('estado', 'creado')->get() as $curso) {
                try {
                    $r = $m->llamar('gradereport_user_get_grade_items', ['courseid' => (int) $curso->moodle_course_id]);
                    foreach ($r['usergrades'] ?? [] as $ug) {
                        $alumno = $usuarios[$ug['userid']] ?? null;
                        if (! $alumno) {
                            continue;
                        }
                        foreach ($ug['gradeitems'] ?? [] as $it) {
                            if (($it['itemtype'] ?? '') === 'course' || ! isset($it['graderaw']) || $it['graderaw'] === null) {
                                continue;
                            }
                            $existe = DB::table('moodle_notas_recibidas')->where('moodle_curso_id', $curso->id)->where('moodle_item_id', $it['id'])->where('alumno_id', $alumno)->first();
                            if ($existe && $existe->estado !== 'pendiente') {
                                continue;
                            }
                            $fila = ['item_nombre' => mb_substr((string) ($it['itemname'] ?? 'Ítem'), 0, 200), 'nota_numerica' => (float) $it['graderaw'],
                                'valor_convertido' => $this->convertir((float) $it['graderaw'], (float) ($it['grademax'] ?? 20))];
                            if ($existe) {
                                DB::table('moodle_notas_recibidas')->where('id', $existe->id)->update($fila);
                            } else {
                                DB::table('moodle_notas_recibidas')->insert(['institucion_id' => ContextoInstitucion::requerido(), 'moodle_curso_id' => $curso->id, 'moodle_item_id' => (int) $it['id'], 'alumno_id' => $alumno] + $fila);
                            }
                            $n++;
                        }
                    }
                } catch (Throwable $e) {
                    $errores[] = "Curso {$curso->shortname}: {$e->getMessage()}";
                }
            }

            return $n;
        });
    }

    /** Convierte la nota de Moodle a la escala del colegio con la tabla predeterminada. */
    public function convertir(float $nota, float $maximo): string
    {
        $pct = $maximo > 0 ? $nota * 100 / $maximo : 0;
        $tabla = DB::table('moodle_tablas_conversion')->where('predeterminada', true)->value('rangos');
        $rangos = collect($tabla ? json_decode($tabla, true) : self::CONVERSION)->sortByDesc('desde');
        foreach ($rangos as $r) {
            if ($pct >= (float) $r['desde']) {
                return (string) $r['valor'];
            }
        }

        return 'C';
    }

    public function guardarConversion(array $rangos): void
    {
        $valores = collect($rangos)->pluck('valor')->all();
        if (array_diff($valores, ['AD', 'A', 'B', 'C']) || ! collect($rangos)->contains(fn ($r) => (float) $r['desde'] === 0.0)) {
            throw ValidationException::withMessages(['rangos' => ['Usa AD, A, B o C, y que un rango empiece en 0 %.']]);
        }
        DB::table('moodle_tablas_conversion')->update(['predeterminada' => false]);
        DB::table('moodle_tablas_conversion')->insert(['institucion_id' => ContextoInstitucion::requerido(), 'nombre' => 'Tabla del colegio '.now()->format('Y-m-d'), 'rangos' => json_encode(array_values($rangos)), 'predeterminada' => true]);
    }

    // ───────────── EDUN-148 · Aprobar notas

    /** Notas recibidas de los cursos del docente (o de todos para coordinación). */
    public function notasPendientes(User $usuario, ?string $estado = 'pendiente'): array
    {
        $personal = DB::table('personal')->where('user_id', $usuario->id)->value('id');
        $todo = $this->autorizacion->tieneRol($usuario, 'coordinador', 'director', 'subdirector', 'promotor');

        return DB::table('moodle_notas_recibidas as n')->join('moodle_cursos as mc', 'mc.id', '=', 'n.moodle_curso_id')->join('cargas_docentes as cd', 'cd.id', '=', 'mc.carga_docente_id')
            ->join('alumnos as a', 'a.id', '=', 'n.alumno_id')->join('areas_curriculares as ar', 'ar.id', '=', 'cd.area_curricular_id')
            ->join('secciones as s', 's.id', '=', 'cd.seccion_id')->join('grados as g', 'g.id', '=', 's.grado_id')
            ->when(! $todo, fn ($q) => $q->where('cd.personal_id', $personal ?? 0))->when($estado, fn ($q, $e) => $q->where('n.estado', $e))
            ->orderBy('ar.nombre')->orderBy('n.item_nombre')->orderBy('a.apellido_paterno')->limit(500)
            ->get(['n.id', 'n.item_nombre', 'n.nota_numerica', 'n.valor_convertido', 'n.estado', 'n.competencia_id', 'n.periodo_id', 'cd.area_curricular_id', 'cd.seccion_id',
                DB::raw("ar.nombre || ' · ' || g.nombre || ' ' || s.nombre as curso"), DB::raw("a.apellido_paterno || ', ' || a.nombres as alumno")])
            ->map(fn ($n) => array_merge((array) $n, ['nota_numerica' => (float) $n->nota_numerica]))->all();
    }

    /**
     * El docente aprueba (con su competencia y periodo destino) o descarta notas recibidas. Las
     * aprobadas pasan al registro oficial (origen «moodle»); un periodo cerrado no las acepta.
     *
     * @param  list<int>  $ids
     */
    public function resolverNotas(array $ids, bool $aprobar, ?int $competencia, ?int $periodo, User $usuario): array
    {
        $notas = collect($this->notasPendientes($usuario))->whereIn('id', $ids);
        if ($notas->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['ids' => ['Alguna nota no está pendiente o no es de tus cursos.']]);
        }
        $ok = 0;
        $errores = [];
        foreach ($notas as $n) {
            if (! $aprobar) {
                DB::table('moodle_notas_recibidas')->where('id', $n['id'])->update(['estado' => 'descartada', 'aprobado_por' => $usuario->id]);
                $ok++;

                continue;
            }
            if (! $competencia || ! DB::table('competencias')->where('id', $competencia)->where('area_curricular_id', $n['area_curricular_id'])->exists()) {
                throw ValidationException::withMessages(['competencia_id' => ['Elige una competencia del área del curso.']]);
            }
            if (! $periodo || ! DB::table('periodos')->where('id', $periodo)->exists()) {
                throw ValidationException::withMessages(['periodo_id' => ['Elige el periodo.']]);
            }
            try {
                DB::transaction(function () use ($n, $competencia, $periodo, $usuario) {
                    DB::table('calificaciones')->upsert([[
                        'institucion_id' => ContextoInstitucion::requerido(), 'alumno_id' => DB::table('moodle_notas_recibidas')->where('id', $n['id'])->value('alumno_id'), 'seccion_id' => $n['seccion_id'],
                        'competencia_id' => $competencia, 'periodo_id' => $periodo, 'valor' => $n['valor_convertido'], 'registrado_por' => $usuario->id, 'origen' => 'moodle',
                    ]], ['alumno_id', 'competencia_id', 'periodo_id'], ['valor', 'registrado_por', 'origen']);
                    DB::table('moodle_notas_recibidas')->where('id', $n['id'])->update(['estado' => 'aprobada', 'aprobado_por' => $usuario->id, 'competencia_id' => $competencia, 'periodo_id' => $periodo]);
                });
                $ok++;
            } catch (Throwable $e) {
                $errores[] = "{$n['alumno']}: ".(str_contains($e->getMessage(), 'cerrado') ? 'el periodo está cerrado.' : 'no se pudo registrar.');
            }
        }
        if ($ok) {
            $this->auditoria->registrar($aprobar ? 'moodle_notas_aprobadas' : 'moodle_notas_descartadas', 'moodle_notas_recibidas', null, null, ['cantidad' => $ok, 'competencia_id' => $competencia, 'periodo_id' => $periodo]);
        }

        return ['procesadas' => $ok, 'errores' => $errores];
    }

    // ───────────── EDUN-149 · Imagen del colegio

    /** SCSS del tema Boost con el color del colegio y la dirección del logo (se pega en Moodle). */
    public function imagen(): array
    {
        $i = DB::table('instituciones')->where('id', ContextoInstitucion::requerido())->first(['nombre', 'color_primario', 'logo_archivo_id']);
        $color = $i->color_primario ?: '#27496f';

        return [
            'color' => $color, 'nombre' => $i->nombre, 'tiene_logo' => (bool) $i->logo_archivo_id,
            'scss_previo' => "\$primary: {$color};\n\$brand-primary: {$color};",
            'scss' => ".navbar { border-bottom: 4px solid {$color}; }\n.btn-primary { background-color: {$color}; border-color: {$color}; }\n.page-header-headings h1 { color: {$color}; }",
            'pasos' => [
                'En Moodle entra a Administración del sitio → Apariencia → Temas → Boost.',
                'En «SCSS inicial» pega el bloque de variables y en «SCSS sin procesar» el bloque de estilos.',
                'En Apariencia → Logos sube el logo del colegio (descárgalo de Configuración del colegio).',
                'Guarda los cambios y purga las cachés (Desarrollo → Purgar todas las cachés).',
            ],
        ];
    }

    // ───────────── Sincronización completa y bitácora

    public function sincronizarTodo(): array
    {
        $this->prepararCursos();
        $r = ['cursos' => $this->crearCursos(), 'usuarios' => $this->sincronizarUsuarios(), 'notas' => $this->traerNotas()];
        DB::table('moodle_conexiones')->update(['ultima_sincronizacion_at' => now()]);

        return $r;
    }

    public function bitacora(): array
    {
        return DB::table('moodle_sincronizaciones')->orderByDesc('id')->limit(50)->get(['id', 'tipo', 'iniciada_at', 'finalizada_at', 'registros', 'errores', 'detalle'])
            ->map(fn ($s) => array_merge((array) $s, ['detalle' => json_decode((string) $s->detalle, true)]))->all();
    }

    public function resumen(): array
    {
        $c = $this->conexion();

        return [
            'cursos' => DB::table('moodle_cursos')->selectRaw("count(*) filter (where estado = 'creado') as creados, count(*) filter (where estado = 'pendiente') as pendientes, count(*) filter (where estado = 'error') as con_error")->first(),
            'usuarios' => $c ? DB::table('moodle_usuarios')->where('moodle_conexion_id', $c->id)->selectRaw('count(*) filter (where not suspendido) as activos, count(*) filter (where suspendido) as suspendidos')->first() : null,
            'notas_pendientes' => DB::table('moodle_notas_recibidas')->where('estado', 'pendiente')->count(),
            'tiene_secreto_sso' => (bool) ($c?->sso_secreto_cifrado),
            'simulado' => ClienteMoodle::simulado(),
            'alojado_por_edunova' => (bool) ($c?->alojado_por_edunova),
        ];
    }

    private function asegurarUsuario(ClienteMoodle $m, object $c, object $x): object
    {
        $mu = DB::table('moodle_usuarios')->where('moodle_conexion_id', $c->id)->where('user_id', $x->user_id)->first();
        if ($mu) {
            if ($mu->suspendido) {
                $m->llamar('core_user_update_users', ['users' => [['id' => (int) $mu->moodle_user_id, 'suspended' => 0]]]);
                DB::table('moodle_usuarios')->where('id', $mu->id)->update(['suspendido' => false]);
            }

            return $mu;
        }
        $usuario = 'edu'.ContextoInstitucion::requerido().'-'.$x->user_id;
        $existente = $m->llamar('core_user_get_users_by_field', ['field' => 'username', 'values' => [$usuario]])[0] ?? null;
        $id = $existente['id'] ?? $m->llamar('core_user_create_users', ['users' => [[
            'username' => $usuario, 'auth' => 'edunova', 'firstname' => $x->nombres, 'lastname' => $x->apellidos,
            'email' => $x->email ?: "{$usuario}@sin-correo.edunova.invalid", 'createpassword' => 0, 'password' => Str::password(24),
        ]]])[0]['id'];
        DB::table('moodle_usuarios')->insert(['institucion_id' => ContextoInstitucion::requerido(), 'moodle_conexion_id' => $c->id, 'user_id' => $x->user_id, 'moodle_user_id' => (int) $id, 'username' => $usuario]);

        return DB::table('moodle_usuarios')->where('moodle_conexion_id', $c->id)->where('user_id', $x->user_id)->first();
    }

    /** Ejecuta un paso de la sincronización y lo deja en la bitácora. */
    private function registrar(string $tipo, callable $paso): array
    {
        $c = $this->conexion();
        if (! $c) {
            throw ValidationException::withMessages(['moodle' => ['Configura primero el conector de Moodle.']]);
        }
        if ($c->estado === 'pausado') {
            throw ValidationException::withMessages(['moodle' => ['La conexión con Moodle está en pausa.']]);
        }
        $id = (int) DB::table('moodle_sincronizaciones')->insertGetId(['institucion_id' => ContextoInstitucion::requerido(), 'moodle_conexion_id' => $c->id, 'tipo' => $tipo]);
        $errores = [];
        try {
            $n = $paso($this->cliente($c), $c, $errores);
        } catch (Throwable $e) {
            $n = 0;
            $errores[] = $e->getMessage();
        }
        DB::table('moodle_sincronizaciones')->where('id', $id)->update(['finalizada_at' => now(), 'registros' => $n, 'errores' => count($errores),
            'detalle' => json_encode(['errores' => array_slice($errores, 0, 50)], JSON_UNESCAPED_UNICODE)]);
        if ($errores && $n === 0) {
            DB::table('moodle_conexiones')->where('id', $c->id)->update(['estado' => 'error']);
        }

        return ['registros' => $n, 'errores' => $errores];
    }
}
