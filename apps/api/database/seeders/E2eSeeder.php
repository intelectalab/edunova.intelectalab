<?php

namespace Database\Seeders;

use App\Modulos\PAG\Services\PagosEnLinea;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * EDUN-210 · Datos para las pruebas de pantalla de la web de gestión (apps/web/e2e) contra la
 * API real. Se monta sobre el colegio demo (DemoSeeder) y es idempotente: se puede correr antes
 * de cada ejecución. Solo agrega datos propios (marcados «E2E»); no borra nada del demo.
 *
 *   php artisan db:seed --class=E2eSeeder --force
 *
 * Crea:
 * - Un director de pruebas con el segundo factor activo y un secreto TOTP fijo (las pruebas
 *   calculan el código), para no tocar al director demo.
 * - Una familia con usuario (apoderado), un hijo y una cuota emitida y pendiente de 100.00.
 * - La cuenta de pasarela del colegio en pruebas (con PASARELA_SIMULADA es la pasarela simulada)
 *   y las condiciones económicas publicadas del año, si aún no las hay.
 * - La sección E2E-M (sin tope de vacantes) donde la prueba matricula alumnos nuevos.
 * - La sección E2E-N con un curso de Matemática y dos alumnos: sin notas ni cierre en el
 *   I Bimestre (la prueba las registra y cierra) y con notas completas en el II Bimestre (para
 *   la plantilla del SIAGIE), y la plantilla vacía del SIAGIE de esa sección en PLANTILLA_SIAGIE.
 *
 * Todos los nombres, documentos, claves y montos son ficticios. Nunca se carga en producción.
 */
class E2eSeeder extends Seeder
{
    public const PASSWORD = 'E2e.Prueba.2026';

    public const DIRECTOR_EMAIL = 'director@e2e.edunova.test';

    public const DIRECTOR_DNI = '29000101';

    /** Secreto TOTP de prueba (base32); el mismo valor está en apps/web/e2e/datos.ts. */
    public const DIRECTOR_TOTP = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    public const APODERADO_EMAIL = 'apoderado@e2e.edunova.test';

    public const APODERADO_DNI = '29000102';

    public const HIJO_DNI = '79000101';

    public const CUOTA = 'Pensión de prueba E2E';

    public const MONTO = 100.00;

    public const SECCION_MATRICULA = 'E2E-M';

    public const SECCION_NOTAS = 'E2E-N';

    public const DOCENTE_DNI = '41000101';

    /** Alumnos de la sección E2E-N: [DNI, código SIAGIE, nombres]. */
    public const ALUMNOS_NOTAS = [['79000111', '00000987650001', 'Ana'], ['79000112', '00000987650002', 'Bruno']];

    /** Ruta (en storage/) de la plantilla vacía del SIAGIE que sube la prueba. */
    public const PLANTILLA_SIAGIE = 'app/e2e/plantilla-siagie-e2e.xlsx';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) && ! filter_var(env('EDUNOVA_E2E', false), FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException('E2eSeeder solo corre en local o testing (o con EDUNOVA_E2E=true): crea usuarios con claves conocidas.');
        }

        // Dueño de la base, como DemoSeeder: las semillas no pasan por el RLS de la aplicación.
        $db = DB::connection('propietario');
        $inst = (int) $db->table('instituciones')->where('slug', 'smp')->value('id');
        if (! $inst) {
            throw new RuntimeException('Falta el colegio demo: corre antes DemoSeeder.');
        }

        $plantilla = $db->transaction(function () use ($db, $inst) {
            // Las notas de las secciones E2E se reinician aunque su periodo se haya cerrado.
            $db->select("select set_config('app.institucion_id', ?, true), set_config('app.reapertura', 'on', true)", [(string) $inst]);
            $anio = (int) $db->table('anios_escolares')->where('institucion_id', $inst)->where('anio', 2027)->value('id');
            $grado = (int) $db->table('grados')->where('institucion_id', $inst)->where('nivel', 'secundaria')->where('numero', 3)->value('id');

            $this->director($db, $inst);
            $this->familia($db, $inst);
            $this->pasarela($db, $inst);
            $this->condiciones($db, $inst, $anio);
            $this->seccion($db, $inst, $anio, $grado, self::SECCION_MATRICULA);

            return $this->seccionNotas($db, $inst, $anio, $grado);
        });

        $this->plantillaSiagie($plantilla);
    }

    private function usuario(ConnectionInterface $db, string $email, string $dni, string $nombres, string $apellidos, array $extra = []): int
    {
        $datos = ['nombres' => $nombres, 'apellidos' => $apellidos, 'numero_documento' => $dni, 'password' => Hash::make(self::PASSWORD), 'activo' => true] + $extra;
        $id = $db->table('users')->where('email', $email)->value('id');
        if ($id) {
            $db->table('users')->where('id', $id)->update($datos);

            return (int) $id;
        }

        return (int) $db->table('users')->insertGetId($datos + ['email' => $email]);
    }

    private function rol(ConnectionInterface $db, int $inst, int $userId, string $rol): void
    {
        $db->table('membresias')->updateOrInsert(
            ['institucion_id' => $inst, 'user_id' => $userId, 'rol_id' => $db->table('roles')->where('codigo', $rol)->value('id')],
            ['activo' => true, 'hasta' => null],
        );
    }

    private function director(ConnectionInterface $db, int $inst): void
    {
        $id = $this->usuario($db, self::DIRECTOR_EMAIL, self::DIRECTOR_DNI, 'Diana', 'Directora E2E', [
            'dos_pasos_secreto' => Crypt::encryptString(self::DIRECTOR_TOTP), 'dos_pasos_activo_at' => now(),
        ]);
        $this->rol($db, $inst, $id, 'director');
        $this->rol($db, $inst, $id, 'promotor');
    }

    private function familia(ConnectionInterface $db, int $inst): void
    {
        $userId = $this->usuario($db, self::APODERADO_EMAIL, self::APODERADO_DNI, 'Rosa', 'Familia E2E');
        $this->rol($db, $inst, $userId, 'apoderado');

        $db->table('apoderados')->updateOrInsert(['institucion_id' => $inst, 'tipo_documento' => 'DNI', 'numero_documento' => self::APODERADO_DNI],
            ['user_id' => $userId, 'apellidos' => 'Familia E2E', 'nombres' => 'Rosa', 'email' => self::APODERADO_EMAIL]);
        $apoderado = (int) $db->table('apoderados')->where('institucion_id', $inst)->where('numero_documento', self::APODERADO_DNI)->value('id');
        $alumno = $this->alumno($db, $inst, self::HIJO_DNI, null, 'Familia E2E', 'Hugo');
        $db->table('alumno_apoderado')->updateOrInsert(['institucion_id' => $inst, 'alumno_id' => $alumno, 'apoderado_id' => $apoderado],
            ['parentesco' => 'madre', 'es_responsable_pago' => true]);

        // Una cuota emitida y pendiente; tras pagarla en la prueba, la próxima carga crea otra.
        $hoy = now()->toDateString();
        $pendiente = $db->table('cargos')->where('alumno_id', $alumno)->where('descripcion', self::CUOTA)
            ->whereIn('estado', ['pendiente', 'parcial'])->where('emision', '<=', $hoy)->exists();
        if (! $pendiente) {
            $db->table('cargos')->insert([
                'institucion_id' => $inst, 'alumno_id' => $alumno, 'descripcion' => self::CUOTA, 'monto' => self::MONTO,
                'concepto_cobro_id' => $db->table('conceptos_cobro')->where('institucion_id', $inst)->where('codigo', 'PEN')->value('id'),
                'emision' => now()->subDays(5)->toDateString(), 'vencimiento' => now()->addDays(25)->toDateString(),
            ]);
        }
    }

    /** Cuenta de pasarela activa en pruebas, solo si el colegio aún no tiene una. */
    private function pasarela(ConnectionInterface $db, int $inst): void
    {
        $activa = $db->table('pasarela_cuentas')->where('institucion_id', $inst)->where('predeterminada', true)->where('estado', 'activa')->exists();
        if ($activa) {
            return;
        }
        $proveedor = array_key_first(PagosEnLinea::PROVEEDORES);
        $db->table('pasarela_cuentas')->where('institucion_id', $inst)->where('proveedor', '<>', $proveedor)->update(['predeterminada' => false]);
        $existe = $db->table('pasarela_cuentas')->where('institucion_id', $inst)->where('proveedor', $proveedor)->first();
        $fila = [
            'ambiente' => 'pruebas', 'medios' => json_encode(['yape', 'tarjeta']), 'predeterminada' => true, 'estado' => 'activa', 'verificada_en' => now(),
            'credenciales_cifradas' => Crypt::encryptString(json_encode(['llave_secreta' => 'sk_test_e2e'])),
        ];
        if ($existe) {
            $db->table('pasarela_cuentas')->where('id', $existe->id)->update($fila + ($existe->webhook_secreto_cifrado ? [] : ['webhook_secreto_cifrado' => Crypt::encryptString(Str::random(40))]));

            return;
        }
        $db->table('pasarela_cuentas')->insert($fila + [
            'institucion_id' => $inst, 'proveedor' => $proveedor, 'webhook_token' => Str::random(48), 'webhook_secreto_cifrado' => Crypt::encryptString(Str::random(40)),
        ]);
    }

    /** Condiciones económicas publicadas del año (la matrícula en ventanilla las exige). */
    private function condiciones(ConnectionInterface $db, int $inst, int $anio): void
    {
        if ($db->table('condiciones_economicas')->where('anio_escolar_id', $anio)->whereNotNull('publicado_at')->exists()) {
            return;
        }
        $db->table('condiciones_economicas')->insert([
            'institucion_id' => $inst, 'anio_escolar_id' => $anio, 'publicado_at' => now(),
            'version' => (int) $db->table('condiciones_economicas')->where('anio_escolar_id', $anio)->max('version') + 1,
            'contenido' => 'Condiciones económicas de prueba (E2E): texto ficticio para las pruebas de pantalla.',
        ]);
    }

    private function seccion(ConnectionInterface $db, int $inst, int $anio, int $grado, string $nombre): int
    {
        $db->table('secciones')->updateOrInsert(['institucion_id' => $inst, 'anio_escolar_id' => $anio, 'grado_id' => $grado, 'nombre' => $nombre], ['vacantes' => null]);

        return (int) $db->table('secciones')->where('anio_escolar_id', $anio)->where('grado_id', $grado)->where('nombre', $nombre)->value('id');
    }

    private function alumno(ConnectionInterface $db, int $inst, string $dni, ?string $codigo, string $apellido, string $nombres): int
    {
        $db->table('alumnos')->updateOrInsert(['institucion_id' => $inst, 'tipo_documento' => 'DNI', 'numero_documento' => $dni],
            ['codigo_estudiante_siagie' => $codigo, 'apellido_paterno' => $apellido, 'nombres' => $nombres, 'eliminado_at' => null]);

        return (int) $db->table('alumnos')->where('institucion_id', $inst)->where('numero_documento', $dni)->value('id');
    }

    /**
     * Sección E2E-N: curso de Matemática con dos alumnos. I Bimestre sin notas ni cierre;
     * II Bimestre con notas completas.
     *
     * @return list<string> códigos SIAGIE de los alumnos, para la plantilla
     */
    private function seccionNotas(ConnectionInterface $db, int $inst, int $anio, int $grado): array
    {
        $seccion = $this->seccion($db, $inst, $anio, $grado, self::SECCION_NOTAS);
        $db->table('personal')->updateOrInsert(['institucion_id' => $inst, 'tipo_documento' => 'DNI', 'numero_documento' => self::DOCENTE_DNI],
            ['apellidos' => 'Docente E2E', 'nombres' => 'Elena', 'cargo' => 'Docente', 'especialidad' => 'Matemática']);
        $personal = (int) $db->table('personal')->where('institucion_id', $inst)->where('numero_documento', self::DOCENTE_DNI)->value('id');
        $area = (int) $db->table('areas_curriculares')->where('codigo', 'SEC-MAT')->value('id');
        $db->table('cargas_docentes')->updateOrInsert(['institucion_id' => $inst, 'seccion_id' => $seccion, 'area_curricular_id' => $area], ['personal_id' => $personal]);

        $alumnos = [];
        foreach (self::ALUMNOS_NOTAS as [$dni, $codigo, $nombres]) {
            $id = $this->alumno($db, $inst, $dni, $codigo, 'Notas E2E', $nombres);
            $db->table('matriculas')->updateOrInsert(['institucion_id' => $inst, 'alumno_id' => $id, 'anio_escolar_id' => $anio],
                ['seccion_id' => $seccion, 'estado' => 'matriculado', 'tipo' => 'nuevo']);
            $alumnos[] = $id;
        }

        $db->table('cierres_periodo')->where('seccion_id', $seccion)->delete();
        $db->table('calificaciones')->where('seccion_id', $seccion)->delete();
        $periodo2 = (int) $db->table('periodos')->where('anio_escolar_id', $anio)->where('numero', 2)->value('id');
        $competencias = $db->table('competencias')->where('area_curricular_id', $area)->where('vigente', true)->pluck('id');
        foreach ($alumnos as $a) {
            foreach ($competencias as $k) {
                $db->table('calificaciones')->insert(['institucion_id' => $inst, 'alumno_id' => $a, 'seccion_id' => $seccion, 'competencia_id' => $k, 'periodo_id' => $periodo2, 'valor' => 'A']);
            }
        }

        return array_column(self::ALUMNOS_NOTAS, 1);
    }

    /**
     * Plantilla vacía al estilo del SIAGIE (como la de tests/Feature/SiagieTest.php): hoja del
     * área, encabezado en la fila 9 y códigos de los alumnos desde la 11.
     *
     * @param  list<string>  $codigos
     */
    private function plantillaSiagie(array $codigos): void
    {
        $libro = new Spreadsheet;
        $h = $libro->getActiveSheet();
        $h->setTitle('Matemática');
        $h->setCellValue('A2', 'REGISTRO DE EVALUACIÓN · ÁREA: MATEMÁTICA');
        $h->setCellValue('A9', 'N°')->setCellValue('B9', 'Código del Estudiante')->setCellValue('C9', 'DNI')->setCellValue('D9', 'Apellidos y nombres');
        $col = 'E';
        $numeros = DB::connection('propietario')->table('competencias as c')->join('areas_curriculares as a', 'a.id', '=', 'c.area_curricular_id')
            ->where('a.codigo', 'SEC-MAT')->where('c.vigente', true)->orderBy('c.numero')->pluck('c.numero');
        foreach ($numeros as $i => $numero) {
            $h->setCellValue($col.'9', 'C'.$numero);
            $col++;
            if ($i === 0) {
                $h->setCellValue($col.'9', 'Conclusión descriptiva');
                $col++;
            }
        }
        foreach ($codigos as $i => $codigo) {
            $h->setCellValue('A'.(11 + $i), $i + 1);
            $h->setCellValueExplicit('B'.(11 + $i), $codigo, DataType::TYPE_STRING);
        }
        $ruta = storage_path(self::PLANTILLA_SIAGIE);
        if (! is_dir(dirname($ruta))) {
            mkdir(dirname($ruta), 0775, true);
        }
        (new Xlsx($libro))->save($ruta);
    }
}
