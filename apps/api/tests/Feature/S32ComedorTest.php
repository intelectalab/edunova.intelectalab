<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

/*
| S32 · Comedor: EDUN-168 menú semanal · 169 inscripción con cargos · 170 consumo diario · 171 pase
| con QR · 172 información nutricional · 173 aprobación de nutrición · 174 restricciones · 175
| seguimiento nutricional con campaña autorizada.
*/

beforeEach(function () {
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok']]])]);
    $db = dueno();
    $sus = $db->table('suscripciones')->where('institucion_id', demoId())->value('id');
    foreach (['COD', 'SAL'] as $codigo) {
        $m = $db->table('modulos_catalogo')->where('codigo', $codigo)->value('id');
        if (! $db->table('suscripcion_modulos')->where('suscripcion_id', $sus)->where('modulo_id', $m)->exists()) {
            $db->table('suscripcion_modulos')->insert(['institucion_id' => demoId(), 'suscripcion_id' => $sus, 'modulo_id' => $m]);
        }
        $db->table('suscripcion_modulos')->where('suscripcion_id', $sus)->where('modulo_id', $m)->update(['activo' => true]);
    }
});

function alumnoComedor(): array
{
    $db = dueno();
    $inst = demoId();
    $anio = (int) $db->table('anios_escolares')->where('institucion_id', $inst)->where('anio', 2027)->value('id');
    $seccion = (int) $db->table('secciones')->where('anio_escolar_id', $anio)->orderBy('id')->value('id');
    $carne = 'EN'.strtoupper(bin2hex(random_bytes(5)));
    $alumno = $db->table('alumnos')->insertGetId(['institucion_id' => $inst, 'apellido_paterno' => 'Comedor'.uniqid(), 'nombres' => 'Ema', 'numero_documento' => (string) random_int(60000000, 69999999), 'codigo_carne' => $carne]);
    $db->table('matriculas')->insert(['institucion_id' => $inst, 'alumno_id' => $alumno, 'anio_escolar_id' => $anio, 'seccion_id' => $seccion, 'origen' => 'manual']);
    $u = $db->table('users')->insertGetId(['nombres' => 'Papá', 'apellidos' => 'Comedor '.uniqid(), 'numero_documento' => (string) random_int(20000000, 29999999), 'email' => 'pc.'.uniqid().'@familia.prueba', 'password' => Hash::make('x')]);
    $db->table('membresias')->insert(['institucion_id' => $inst, 'user_id' => $u, 'rol_id' => $db->table('roles')->where('codigo', 'apoderado')->value('id')]);
    $apo = $db->table('apoderados')->insertGetId(['institucion_id' => $inst, 'user_id' => $u, 'numero_documento' => (string) random_int(40000000, 49999999), 'apellidos' => 'Comedor', 'nombres' => 'Papá']);
    $db->table('alumno_apoderado')->insert(['institucion_id' => $inst, 'alumno_id' => $alumno, 'apoderado_id' => $apo, 'parentesco' => 'padre']);

    return ['alumno' => $alumno, 'carne' => $carne, 'seccion' => $seccion, 'familia' => User::query()->findOrFail($u)];
}

function conceptoComedor(float $monto): int
{
    return (int) dueno()->table('conceptos_cobro')->insertGetId(['institucion_id' => demoId(), 'codigo' => 'CO'.random_int(1000, 99999), 'nombre' => 'Comedor', 'tipo' => 'servicio', 'monto' => $monto]);
}

it('EDUN-168 / 172 / 173 · el menú con nutrientes pasa por nutrición antes de publicarse', function () {
    $comedor = personaDemo('comedor');
    $nutri = personaDemo('nutricionista');
    $fecha = now()->addDays(3)->toDateString();
    $m = enDemo($comedor, 'PUT', '/comedor/menus', ['fecha' => $fecha, 'entrada' => 'Ensalada', 'fondo' => 'Arroz con pollo', 'bebida' => 'Chicha', 'postre' => 'Fruta', 'kcal' => 650, 'proteinas_g' => 28, 'alergenos' => 'Ninguno'])->assertOk();
    $id = $m->json('id');
    enDemo($comedor, 'POST', "/comedor/menus/{$id}/estado", ['accion' => 'publicar'])->assertUnprocessable();
    enDemo($comedor, 'POST', "/comedor/menus/{$id}/estado", ['accion' => 'proponer'])->assertOk()->assertJsonPath('estado', 'propuesto');
    // El comedor no aprueba si el colegio tiene nutricionista.
    enDemo($comedor, 'POST', "/comedor/menus/{$id}/estado", ['accion' => 'aprobar'])->assertForbidden();
    enDemo($nutri, 'POST', "/comedor/menus/{$id}/estado", ['accion' => 'devolver'])->assertUnprocessable();
    enDemo($nutri, 'POST', "/comedor/menus/{$id}/estado", ['accion' => 'aprobar', 'porciones' => ['primaria' => ['porcion' => '250 g', 'kcal' => 600]]])->assertOk()->assertJsonPath('estado', 'aprobado');
    enDemo($comedor, 'POST', "/comedor/menus/{$id}/estado", ['accion' => 'publicar'])->assertOk()->assertJsonPath('estado', 'publicado');
    $f = alumnoComedor();
    $menu = collect(enDemo($f['familia'], 'GET', '/familia/comedor')->assertOk()->json('menu'))->firstWhere('fecha', $fecha);
    // La familia ve el menú de la semana en curso (si la fecha cae en ella).
    if (now()->startOfWeek()->addDays(6)->toDateString() >= $fecha) {
        expect($menu['kcal'])->toBe(650)->and($menu['validado_por'])->not->toBeNull();
    }
});

it('EDUN-169 / 170 / 171 / 174 · inscripción con cargos, pase con QR que avisa y lista del día', function () {
    $comedor = personaDemo('comedor');
    $plan = enDemo($comedor, 'POST', '/comedor/planes', ['nombre' => 'Diario', 'modalidad' => 'diario', 'concepto_cobro_id' => conceptoComedor(8)])->assertCreated()->json('id');
    $mensual = enDemo($comedor, 'POST', '/comedor/planes', ['nombre' => 'Mensual', 'modalidad' => 'mensual', 'concepto_cobro_id' => conceptoComedor(150)])->assertCreated()->json('id');
    $a = alumnoComedor();
    $b = alumnoComedor();
    enDemo($comedor, 'POST', '/comedor/inscripciones', ['alumno_id' => $a['alumno'], 'com_plan_id' => $plan, 'desde' => now()->toDateString()])->assertCreated()->assertJsonPath('cargos', 0);
    enDemo($comedor, 'POST', '/comedor/inscripciones', ['alumno_id' => $a['alumno'], 'com_plan_id' => $mensual, 'desde' => now()->toDateString()])->assertUnprocessable();
    $m = enDemo($comedor, 'POST', '/comedor/inscripciones', ['alumno_id' => $b['alumno'], 'com_plan_id' => $mensual, 'desde' => now()->startOfMonth()->toDateString(), 'hasta' => now()->startOfMonth()->addMonths(2)->endOfMonth()->toDateString()])->assertCreated();
    expect($m->json('cargos'))->toBe(3)->and(dueno()->table('cargos')->where('alumno_id', $b['alumno'])->count())->toBe(3);

    // Restricción: el pase avisa sin mostrar el detalle.
    enDemo(personaDemo('nutricionista'), 'POST', '/comedor/restricciones', ['alumno_id' => $a['alumno'], 'tipo' => 'alergia', 'detalle' => 'Maní', 'reemplazo' => 'Sin maní'])->assertCreated();
    $s = enDemo($comedor, 'POST', '/comedor/servidos', ['codigo' => $a['carne'], 'origen' => 'qr'])->assertOk();
    expect($s->json('ok'))->toBeTrue()->and($s->json('dieta_especial'))->toBeTrue()->and(json_encode($s->json()))->not->toContain('Maní');
    // Plan diario: un cargo por el día servido.
    expect(dueno()->table('com_consumos')->where('alumno_id', $a['alumno'])->value('cargo_id'))->not->toBeNull();
    expect(enDemo($comedor, 'POST', '/comedor/servidos', ['codigo' => $a['carne'], 'origen' => 'qr'])->json('alerta'))->toBe('Ya comió hoy.');
    $otro = alumnoComedor();
    expect(enDemo($comedor, 'POST', '/comedor/servidos', ['codigo' => $otro['carne'], 'origen' => 'qr'])->json('alerta'))->toBe('No está inscrito en el comedor.');
    expect(enDemo($comedor, 'POST', '/comedor/servidos', ['codigo' => 'ENNOEXISTE1', 'origen' => 'qr'])->json('ok'))->toBeFalse();

    enDemo($comedor, 'POST', '/comedor/servidos', ['alumno_id' => $b['alumno'], 'origen' => 'lista'])->assertOk()->assertJsonPath('ok', true);
    $lista = collect(enDemo($comedor, 'GET', '/comedor/lista')->assertOk()->json('data'))->keyBy('alumno_id');
    expect($lista[$a['alumno']]['servido'])->toBeTrue()->and($lista[$b['alumno']]['servido'])->toBeTrue()->and($lista[$a['alumno']]['dieta_especial'])->toBeTrue();
    enDemo($comedor, 'GET', '/comedor/restricciones')->assertForbidden();
    expect(collect(enDemo($a['familia'], 'GET', '/familia/comedor')->json('consumos'))->pluck('alumno'))->toContain('Ema');
});

it('EDUN-175 · peso y talla solo con la campaña autorizada; indicadores por nivel y orientación', function () {
    $a = alumnoComedor();
    $enfermeria = personaDemo('enfermeria');
    $c = enDemo($enfermeria, 'POST', '/salud/campanas', ['nombre' => 'Peso y talla', 'fecha' => now()->addDay()->toDateString(), 'descripcion' => 'Medición de peso y talla del primer trimestre.', 'secciones' => [$a['seccion']]])->assertCreated()->json('id');
    $nutri = personaDemo('nutricionista');
    enDemo($nutri, 'POST', '/comedor/mediciones', ['alumno_id' => $a['alumno'], 'campana_id' => $c, 'peso_kg' => 30, 'talla_cm' => 130])->assertUnprocessable();
    $aut = collect(enDemo($a['familia'], 'GET', '/familia/autorizaciones')->json('data'))->firstWhere('alumno_id', $a['alumno']);
    enDemo($a['familia'], 'POST', "/familia/autorizaciones/{$aut['id']}/firma", ['alumno_id' => $a['alumno'], 'acepta' => true])->assertOk();
    expect(enDemo($nutri, 'POST', '/comedor/mediciones', ['alumno_id' => $a['alumno'], 'campana_id' => $c, 'peso_kg' => 30, 'talla_cm' => 130])->assertCreated()->json('imc'))->toEqual(17.8);
    $ind = collect(enDemo($nutri, 'GET', '/comedor/indicadores')->assertOk()->json('data'));
    expect($ind->sum('mediciones'))->toBeGreaterThanOrEqual(1);
    dueno()->table('dispositivos_moviles')->insert(['user_id' => $a['familia']->id, 'plataforma' => 'android', 'token_push' => 'ExponentPushToken['.bin2hex(random_bytes(6)).']']);
    expect(enDemo($nutri, 'POST', "/comedor/orientacion/{$a['alumno']}", ['mensaje' => 'Su hijo tiene un peso adecuado. Siga con loncheras saludables.'])->assertOk()->json('enviados'))->toBe(1);
});
