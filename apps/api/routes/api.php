<?php

use App\Http\Controllers\SaludController;
use App\Modulos\ADM\Http\Controllers\AdmisionController;
use App\Modulos\ADM\Http\Controllers\AlumnosController;
use App\Modulos\ADM\Http\Controllers\AniosEscolaresController;
use App\Modulos\ADM\Http\Controllers\HorariosController;
use App\Modulos\ADM\Http\Controllers\InstitucionController;
use App\Modulos\ADM\Http\Controllers\MatriculasController;
use App\Modulos\ADM\Http\Controllers\PersonalController;
use App\Modulos\ADM\Http\Controllers\PlanEstudiosController;
use App\Modulos\CAL\Http\Controllers\NotasController;
use App\Modulos\CAL\Http\Controllers\SeguimientoController;
use App\Modulos\COM\Http\Controllers\ComunicacionController;
use App\Modulos\Compartido\Correo\EventosCorreoController;
use App\Modulos\MDL\Http\Controllers\ConectorMoodleController;
use App\Modulos\MOV\Http\Controllers\AppPersonalController;
use App\Modulos\PAG\Http\Controllers\CobranzaController;
use App\Modulos\PAG\Http\Controllers\ConfiguracionPagosController;
use App\Modulos\PAG\Http\Controllers\FinanzasController;
use App\Modulos\PLA\Http\Controllers\InstitucionesPlataformaController;
use App\Modulos\PLA\Http\Controllers\OperacionController;
use App\Modulos\PLA\Http\Controllers\SoporteController;
use App\Modulos\SEG\Http\Controllers\ArchivosController;
use App\Modulos\SEG\Http\Controllers\AuditoriaController;
use App\Modulos\SEG\Http\Controllers\DispositivosController;
use App\Modulos\SEG\Http\Controllers\DosPasosController;
use App\Modulos\SEG\Http\Controllers\FamiliaController;
use App\Modulos\SEG\Http\Controllers\InvitacionesController;
use App\Modulos\SEG\Http\Controllers\SesionController;
use App\Modulos\SEG\Http\Controllers\UsuariosController;
use App\Modulos\SIA\Http\Controllers\SiagieController;
use App\Modulos\SIT\Http\Controllers\BlogSeoCmsController;
use App\Modulos\SIT\Http\Controllers\EditorCmsController;
use App\Modulos\SIT\Http\Controllers\LandingController;
use App\Modulos\SIT\Http\Controllers\LeadsCmsController;
use App\Modulos\SIT\Http\Controllers\MediosCmsController;
use App\Modulos\SIT\Http\Controllers\PanelCmsController;
use App\Modulos\SIT\Http\Controllers\ReclamacionesCmsController;
use App\Modulos\SIT\Http\Controllers\ReclamacionesController;
use App\Modulos\SIT\Http\Controllers\SesionCmsController;
use App\Modulos\SIT\Http\Controllers\SitioCanalesController;
use App\Modulos\SIT\Http\Controllers\SitioLegalController;
use Illuminate\Support\Facades\Route;

// Todas las rutas quedan bajo /api/v1 (bootstrap/app.php, apiPrefix).

Route::get('/salud', SaludController::class);

// EDUN-208 · Rebotes y quejas del proveedor de correo (firmado con HMAC).
Route::post('/correo/eventos', EventosCorreoController::class)->middleware('throttle:60,1');

// EDUN-35 · Sesión: ingreso con DNI o correo, elección de colegio, quién soy y salir.
Route::prefix('auth')->group(function () {
    Route::post('/ingresar', [SesionController::class, 'ingresar'])->middleware('throttle:ingreso');
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/colegio', [SesionController::class, 'elegirColegio']);
        Route::get('/yo', [SesionController::class, 'yo']);
        Route::post('/salir', [SesionController::class, 'salir']);
        // EDUN-38 · Segundo factor (TOTP + códigos de respaldo).
        Route::middleware('throttle:30,1')->prefix('dos-pasos')->group(function () {
            Route::post('/iniciar', [DosPasosController::class, 'iniciar']);
            Route::post('/activar', [DosPasosController::class, 'activar']);
            Route::post('/verificar', [DosPasosController::class, 'verificar']);
            Route::post('/respaldos', [DosPasosController::class, 'respaldos']);
        });
        // EDUN-195 / EDUN-196 · App móvil: token de push del dispositivo y preferencias por tipo.
        Route::middleware('throttle:30,1')->group(function () {
            Route::post('/dispositivos', [DispositivosController::class, 'registrar']);
            Route::delete('/dispositivos', [DispositivosController::class, 'quitar']);
            Route::get('/notificaciones/preferencias', [DispositivosController::class, 'preferencias']);
            Route::put('/notificaciones/preferencias', [DispositivosController::class, 'guardarPreferencias']);
        });
    });
});

// EDUN-74 · Aviso de la pasarela del colegio (sin sesión: firma HMAC y token de la cuenta en la URL).
Route::post('/pasarela/webhook/{token}', [CobranzaController::class, 'webhook'])->where('token', '[A-Za-z0-9]{20,64}')->middleware('throttle:120,1');

// EDUN-66 · Libro de reclamaciones del colegio (público, sin sesión; el colegio por su subdominio).
Route::get('/colegios/{slug}/libro-reclamaciones', [ComunicacionController::class, 'colegioPublico'])->where('slug', '[a-z0-9-]{2,60}')->middleware('throttle:60,1');
Route::post('/colegios/{slug}/libro-reclamaciones', [ComunicacionController::class, 'reclamoPublico'])->where('slug', '[a-z0-9-]{2,60}')->middleware('throttle:5,1');

// EDUN-36 · Enlace de invitación (sin sesión, un solo uso).
Route::prefix('invitaciones/{token}')->where(['token' => '[A-Za-z0-9]{48}'])->middleware('throttle:ingreso')->group(function () {
    Route::get('/', [InvitacionesController::class, 'mostrar']);
    Route::post('/aceptar', [InvitacionesController::class, 'aceptar']);
});

// Consola de la plataforma (EDUN-24 a EDUN-26): staff de Edunova con segundo factor.
Route::middleware(['auth:sanctum', 'plataforma'])->prefix('plataforma')->group(function () {
    Route::get('/catalogos', [InstitucionesPlataformaController::class, 'catalogos']);
    Route::get('/instituciones', [InstitucionesPlataformaController::class, 'index']);
    Route::get('/instituciones/{institucion}', [InstitucionesPlataformaController::class, 'show'])->whereNumber('institucion');
    // EDUN-29 / EDUN-31 / EDUN-32 · Mesa de ayuda con SLA.
    Route::get('/tickets', [SoporteController::class, 'index']);
    Route::get('/tickets/{ticket}', [SoporteController::class, 'show'])->whereNumber('ticket');
    Route::post('/tickets/{ticket}/mensajes', [SoporteController::class, 'responder'])->whereNumber('ticket');
    Route::patch('/tickets/{ticket}', [SoporteController::class, 'actualizar'])->whereNumber('ticket');
    Route::get('/sla/cumplimiento', [SoporteController::class, 'cumplimiento']);
    // EDUN-30 · Parámetros legales (lectura para el staff; cambios, superadmin).
    Route::get('/parametros-legales', [OperacionController::class, 'parametros']);
    // EDUN-212 · Lista de implementación.
    Route::get('/instituciones/{institucion}/checklist', [OperacionController::class, 'checklist'])->whereNumber('institucion');
    Route::middleware('plataforma:superadmin')->group(function () {
        // EDUN-27 · Cobro mensual de Edunova a los colegios (solo superadmin).
        Route::get('/cobro-mensual', [OperacionController::class, 'cobroMensual']);
        Route::post('/cobro-mensual', [OperacionController::class, 'emitirCobroMensual'])->middleware('throttle:5,1');
        Route::patch('/facturas-saas/{factura}', [OperacionController::class, 'marcarFacturaSaas'])->whereNumber('factura');
        Route::post('/instituciones', [InstitucionesPlataformaController::class, 'store'])->middleware('throttle:20,1');
        Route::put('/instituciones/{institucion}/modulos/{modulo}', [InstitucionesPlataformaController::class, 'modulo'])->whereNumber('institucion')->where('modulo', '[A-Za-z]{2,10}');
        Route::put('/instituciones/{institucion}/aula', [InstitucionesPlataformaController::class, 'aula'])->whereNumber('institucion');
        Route::post('/parametros-legales', [OperacionController::class, 'guardarParametro']);
        Route::patch('/parametros-legales/{parametro}', [OperacionController::class, 'confirmarParametro'])->whereNumber('parametro');
        Route::put('/instituciones/{institucion}/checklist/{paso}', [OperacionController::class, 'marcarPaso'])->whereNumber('institucion')->where('paso', '[a-z_]+');
        // EDUN-28 · Suspensión por falta de pago y reactivación (y activar al terminar la implementación).
        Route::put('/instituciones/{institucion}/estado', [OperacionController::class, 'cambiarEstado'])->whereNumber('institucion');
        Route::post('/instituciones/{institucion}/suspension', [OperacionController::class, 'programarSuspension'])->whereNumber('institucion');
        Route::delete('/instituciones/{institucion}/suspension', [OperacionController::class, 'cancelarSuspension'])->whereNumber('institucion');
        // EDUN-34 · Exportación completa.
        Route::get('/instituciones/{institucion}/exportaciones', [OperacionController::class, 'exportaciones'])->whereNumber('institucion');
        Route::post('/instituciones/{institucion}/exportaciones', [OperacionController::class, 'exportar'])->whereNumber('institucion')->middleware('throttle:5,1');
        Route::get('/instituciones/{institucion}/exportaciones/{exportacion}/enlace', [OperacionController::class, 'descargarExportacion'])->whereNumber(['institucion', 'exportacion']);
        Route::post('/instituciones/{institucion}/promotores/{usuario}/invitacion', [InstitucionesPlataformaController::class, 'reinvitar'])->whereNumber(['institucion', 'usuario'])->middleware('throttle:10,1');
    });
});

// Rutas del colegio: sesión, colegio activo (RLS) y permiso por ruta (EDUN-39).
Route::middleware(['auth:sanctum', 'colegio'])->group(function () {
    Route::get('/alumnos', [AlumnosController::class, 'index'])->middleware('permiso:adm.alumnos.ver');
    // Sin permiso de ruta: la policy deja pasar al personal con adm.alumnos.ver y a la familia por propiedad.
    Route::get('/alumnos/{alumno}', [AlumnosController::class, 'show'])->whereNumber('alumno');

    // Sprint 13 · Estructura académica del año y alumnos (ADM).
    Route::middleware('modulo:ADM')->group(function () {
        // EDUN-45 / EDUN-46 · Plan de estudios, secciones y aulas.
        Route::get('/plan-estudios', [PlanEstudiosController::class, 'show'])->middleware('permiso:adm.plan_estudios.ver');
        Route::get('/secciones', [PlanEstudiosController::class, 'secciones'])->middleware('permiso:adm.plan_estudios.ver|adm.horarios.ver|adm.alumnos.ver');
        Route::middleware('permiso:adm.plan_estudios.editar')->group(function () {
            Route::post('/plan-estudios/grados', [PlanEstudiosController::class, 'generarGrados']);
            Route::put('/plan-estudios/grados/{grado}', [PlanEstudiosController::class, 'guardarGrado'])->whereNumber('grado');
            Route::post('/plan-estudios/copiar', [PlanEstudiosController::class, 'copiar']);
            Route::post('/secciones', [PlanEstudiosController::class, 'guardarSeccion']);
            Route::put('/secciones/{seccion}', [PlanEstudiosController::class, 'guardarSeccion'])->whereNumber('seccion');
            Route::delete('/secciones/{seccion}', [PlanEstudiosController::class, 'eliminarSeccion'])->whereNumber('seccion');
            Route::post('/secciones/copiar', [PlanEstudiosController::class, 'copiarSecciones']);
            Route::post('/ambientes', [PlanEstudiosController::class, 'crearAmbiente']);
        });

        // EDUN-47 · Personal.
        Route::get('/personal', [PersonalController::class, 'index'])->middleware('permiso:adm.personal.ver');
        Route::post('/personal', [PersonalController::class, 'store'])->middleware('permiso:adm.personal.crear');
        Route::put('/personal/{persona}', [PersonalController::class, 'update'])->whereNumber('persona')->middleware('permiso:adm.personal.editar');
        Route::post('/personal/{persona}/invitacion', [PersonalController::class, 'invitar'])->whereNumber('persona')->middleware(['permiso:adm.personal.crear', 'permiso:seg.usuarios.crear', 'throttle:10,1']);

        // EDUN-48 / EDUN-49 · Carga docente y horario.
        Route::get('/cargas', [HorariosController::class, 'cargas'])->middleware('permiso:adm.horarios.ver');
        Route::put('/cargas', [HorariosController::class, 'asignarCarga'])->middleware('permiso:adm.horarios.editar');
        Route::get('/bloques-horarios', [HorariosController::class, 'bloques'])->middleware('permiso:adm.horarios.ver');
        Route::put('/bloques-horarios', [HorariosController::class, 'guardarBloques'])->middleware('permiso:adm.horarios.editar');
        Route::get('/horarios', [HorariosController::class, 'ver'])->middleware('permiso:adm.horarios.ver');
        Route::put('/horarios/celda', [HorariosController::class, 'asignarCelda'])->middleware('permiso:adm.horarios.editar');
        Route::get('/horarios/pdf', [HorariosController::class, 'pdf'])->middleware(['permiso:adm.horarios.ver', 'throttle:20,1']);

        // EDUN-50 / EDUN-51 · Registro de alumnos e importación desde Excel.
        Route::get('/alumnos/{alumno}/ficha', [AlumnosController::class, 'ficha'])->whereNumber('alumno')->middleware('permiso:adm.alumnos.ver');
        Route::post('/alumnos', [AlumnosController::class, 'store'])->middleware('permiso:adm.alumnos.crear');
        Route::put('/alumnos/{alumno}', [AlumnosController::class, 'update'])->whereNumber('alumno')->middleware('permiso:adm.alumnos.editar');
        // EDUN-53 · Admisión por etapas.
        Route::get('/admision', [AdmisionController::class, 'tablero'])->middleware('permiso:adm.admision.ver');
        Route::get('/admision/postulantes/{postulante}/historia', [AdmisionController::class, 'historia'])->whereNumber('postulante')->middleware('permiso:adm.admision.ver');
        Route::post('/admision/postulantes', [AdmisionController::class, 'store'])->middleware('permiso:adm.admision.crear');
        Route::middleware('permiso:adm.admision.editar')->group(function () {
            Route::post('/admision/procesos', [AdmisionController::class, 'proceso']);
            Route::put('/admision/vacantes', [AdmisionController::class, 'vacantes']);
            Route::patch('/admision/postulantes/{postulante}/etapa', [AdmisionController::class, 'etapa'])->whereNumber('postulante');
            Route::post('/admision/postulantes/{postulante}/entrevistas', [AdmisionController::class, 'entrevista'])->whereNumber('postulante');
        });
        Route::post('/admision/postulantes/{postulante}/convertir', [AdmisionController::class, 'convertir'])->whereNumber('postulante')->middleware(['permiso:adm.admision.editar', 'permiso:adm.alumnos.crear']);

        // Sprint 14 · Ficha completa, matrícula, movimientos, carné, consentimientos y familias.
        Route::get('/alumnos/{alumno}/expediente', [MatriculasController::class, 'expediente'])->whereNumber('alumno')->middleware('permiso:adm.alumnos.ver');
        Route::post('/alumnos/{alumno}/apoderados/{apoderado}/invitacion', [MatriculasController::class, 'invitarApoderado'])->whereNumber(['alumno', 'apoderado'])->middleware(['permiso:adm.alumnos.editar', 'throttle:20,1']);
        Route::post('/alumnos/{alumno}/foto', [MatriculasController::class, 'foto'])->whereNumber('alumno')->middleware('permiso:adm.alumnos.editar');
        Route::get('/alumnos/{alumno}/carne', [MatriculasController::class, 'carne'])->whereNumber('alumno')->middleware('permiso:adm.alumnos.ver');
        Route::post('/alumnos/{alumno}/carne', [MatriculasController::class, 'regenerarCarne'])->whereNumber('alumno')->middleware('permiso:adm.alumnos.editar');
        Route::get('/carnes/pdf', [MatriculasController::class, 'carnesPdf'])->middleware(['permiso:adm.alumnos.ver', 'throttle:20,1']);
        Route::get('/carnes/{codigo}', [MatriculasController::class, 'buscarCarne'])->where('codigo', '[A-Za-z0-9]{6,24}')
            ->middleware('permiso:adm.alumnos.ver|cal.asistencia.registrar|por.ingresos.registrar|cod.comedor.gestionar');
        Route::get('/condiciones-economicas', [MatriculasController::class, 'condiciones'])->middleware('permiso:adm.matricula.ver');
        Route::post('/condiciones-economicas', [MatriculasController::class, 'publicarCondiciones'])->middleware('permiso:adm.matricula.editar');
        Route::post('/matriculas', [MatriculasController::class, 'store'])->middleware('permiso:adm.matricula.crear');
        Route::get('/matriculas/{matricula}/constancia', [MatriculasController::class, 'constancia'])->whereNumber('matricula')->middleware(['permiso:adm.matricula.ver', 'throttle:30,1']);
        Route::post('/matriculas/{matricula}/retiro', [MatriculasController::class, 'retiro'])->whereNumber('matricula')->middleware('permiso:adm.matricula.retirar');
        Route::post('/matriculas/{matricula}/cambio-seccion', [MatriculasController::class, 'cambioSeccion'])->whereNumber('matricula')->middleware('permiso:adm.matricula.editar');
        Route::get('/siagie/pendientes', [MatriculasController::class, 'pendientesSiagie'])->middleware('permiso:sia.nomina.importar|sia.plantillas.validar');
        Route::post('/siagie/pendientes/{pendiente}/atendido', [MatriculasController::class, 'atenderPendiente'])->whereNumber('pendiente')->middleware('permiso:sia.nomina.importar|sia.plantillas.validar');
        Route::get('/consentimientos', [MatriculasController::class, 'consentimientos'])->middleware('permiso:seg.consentimientos.ver');
        Route::post('/consentimientos', [MatriculasController::class, 'registrarConsentimiento'])->middleware('permiso:seg.consentimientos.registrar');
        Route::post('/consentimientos/textos', [MatriculasController::class, 'publicarTexto'])->middleware('permiso:seg.consentimientos.registrar');

        Route::middleware(['permiso:adm.alumnos.crear', 'permiso:adm.matricula.crear'])->group(function () {
            Route::get('/alumnos/importar/plantilla', [AlumnosController::class, 'plantilla']);
            Route::post('/alumnos/importar/vista-previa', [AlumnosController::class, 'vistaPrevia'])->middleware('throttle:20,1');
            Route::post('/alumnos/importar/confirmar', [AlumnosController::class, 'confirmarImportacion'])->middleware('throttle:10,1');
        });
    });

    // EDUN-33 · Soporte desde el colegio (director y promotor).
    Route::middleware('permiso:pla.tickets.ver')->prefix('soporte/tickets')->group(function () {
        Route::get('/', [SoporteController::class, 'misTickets']);
        Route::get('/{ticket}', [SoporteController::class, 'miTicket'])->whereNumber('ticket');
        Route::middleware('permiso:pla.tickets.crear')->group(function () {
            Route::post('/', [SoporteController::class, 'crear'])->middleware('throttle:20,1');
            Route::post('/{ticket}/mensajes', [SoporteController::class, 'responderColegio'])->whereNumber('ticket');
            Route::post('/{ticket}/calificacion', [SoporteController::class, 'calificar'])->whereNumber('ticket');
        });
    });

    // Familias y alumnos en la app (propiedad: solo sus hijos o él mismo).
    Route::prefix('me')->group(function () {
        Route::get('/familia', [FamiliaController::class, 'familia']);
        Route::post('/consentimientos', [FamiliaController::class, 'consentir'])->middleware('throttle:30,1');
        Route::get('/carne/{alumno}', [FamiliaController::class, 'carne'])->whereNumber('alumno');
    });

    // EDUN-36 · Usuarios del colegio (SEG-02).
    Route::get('/usuarios', [UsuariosController::class, 'index'])->middleware('permiso:seg.usuarios.ver');
    Route::post('/usuarios', [UsuariosController::class, 'store'])->middleware(['permiso:seg.usuarios.crear', 'permiso:seg.roles.asignar', 'throttle:30,1']);
    Route::put('/usuarios/{usuario}/roles', [UsuariosController::class, 'roles'])->whereNumber('usuario')->middleware('permiso:seg.roles.asignar');
    Route::patch('/usuarios/{usuario}/estado', [UsuariosController::class, 'estado'])->whereNumber('usuario')->middleware('permiso:seg.usuarios.desactivar');
    Route::post('/usuarios/{usuario}/invitacion', [UsuariosController::class, 'invitacion'])->whereNumber('usuario')->middleware(['permiso:seg.usuarios.crear', 'throttle:10,1']);

    // EDUN-40 · Auditoría (SEG-03): solo lectura y exportación.
    Route::middleware('permiso:seg.auditoria.ver')->group(function () {
        Route::get('/auditoria', [AuditoriaController::class, 'index']);
        Route::get('/auditoria/opciones', [AuditoriaController::class, 'opciones']);
        Route::get('/auditoria/exportar', [AuditoriaController::class, 'exportar'])->middleware('throttle:20,1');
    });

    // EDUN-42 · Archivos con enlace firmado de 5 minutos.
    Route::post('/archivos', [ArchivosController::class, 'store'])->middleware('throttle:60,1');
    Route::get('/archivos/{archivo}/enlace', [ArchivosController::class, 'enlace'])->whereNumber('archivo');

    // EDUN-43 · Configuración de la institución (ADM-01). El logo lo ve todo el colegio.
    Route::get('/institucion/logo', [InstitucionController::class, 'logo']);
    Route::middleware('modulo:ADM')->group(function () {
        Route::get('/institucion', [InstitucionController::class, 'show'])->middleware('permiso:adm.configuracion.ver');
        Route::middleware('permiso:adm.configuracion.editar')->group(function () {
            Route::put('/institucion', [InstitucionController::class, 'update']);
            Route::put('/institucion/servicios', [InstitucionController::class, 'servicios']);
            Route::post('/institucion/logo', [InstitucionController::class, 'subirLogo'])->middleware('throttle:20,1');
            Route::delete('/institucion/logo', [InstitucionController::class, 'quitarLogo']);
        });

        // EDUN-44 · Año escolar y periodos (ADM-02).
        Route::get('/anios-escolares', [AniosEscolaresController::class, 'index'])->middleware('permiso:adm.configuracion.ver|adm.plan_estudios.ver|adm.horarios.ver|adm.alumnos.ver');
        Route::get('/anios-escolares/{anio}', [AniosEscolaresController::class, 'show'])->whereNumber('anio')->middleware('permiso:adm.configuracion.ver');
        Route::post('/anios-escolares', [AniosEscolaresController::class, 'store'])->middleware('permiso:adm.configuracion.editar');
        Route::put('/anios-escolares/{anio}', [AniosEscolaresController::class, 'update'])->whereNumber('anio')->middleware('permiso:adm.configuracion.editar');
    });

    Route::post('/notificaciones/prueba', [DispositivosController::class, 'probar'])->middleware('throttle:30,1');

    // Sprint 16 · Configuración de pagos (PAG-02/03 y facturación).
    Route::middleware('modulo:PAG')->group(function () {
        // EDUN-69 / EDUN-70 · Conceptos y cronogramas.
        Route::get('/pagos/conceptos', [ConfiguracionPagosController::class, 'conceptos'])->middleware('permiso:pag.conceptos.ver');
        Route::get('/pagos/cronogramas', [ConfiguracionPagosController::class, 'cronogramas'])->middleware('permiso:pag.conceptos.ver');
        Route::middleware('permiso:pag.conceptos.editar')->group(function () {
            Route::post('/pagos/conceptos', [ConfiguracionPagosController::class, 'guardarConcepto']);
            Route::put('/pagos/conceptos/{concepto}', [ConfiguracionPagosController::class, 'guardarConcepto'])->whereNumber('concepto');
            Route::post('/pagos/cronogramas', [ConfiguracionPagosController::class, 'guardarCronograma']);
            Route::put('/pagos/cronogramas/{cronograma}', [ConfiguracionPagosController::class, 'guardarCronograma'])->whereNumber('cronograma');
            Route::delete('/pagos/cronogramas/{cronograma}', [ConfiguracionPagosController::class, 'eliminarCronograma'])->whereNumber('cronograma');
            Route::post('/pagos/cronogramas/copiar', [ConfiguracionPagosController::class, 'copiarCronogramas']);
            Route::post('/pagos/beneficios', [ConfiguracionPagosController::class, 'guardarTipoBeneficio']);
            Route::put('/pagos/beneficios/{beneficio}', [ConfiguracionPagosController::class, 'guardarTipoBeneficio'])->whereNumber('beneficio');
        });

        // EDUN-72 · Descuentos y becas: tesorería propone con sustento, dirección aprueba.
        Route::get('/pagos/beneficios', [ConfiguracionPagosController::class, 'beneficios'])->middleware('permiso:pag.conceptos.ver|pag.beneficios.aprobar');
        Route::post('/pagos/beneficios/asignaciones', [ConfiguracionPagosController::class, 'asignarBeneficio'])->middleware('permiso:pag.conceptos.editar|pag.beneficios.aprobar');
        Route::patch('/pagos/beneficios/asignaciones/{asignacion}', [ConfiguracionPagosController::class, 'resolverBeneficio'])->whereNumber('asignacion')->middleware('permiso:pag.beneficios.aprobar');
        Route::delete('/pagos/beneficios/asignaciones/{asignacion}', [ConfiguracionPagosController::class, 'quitarBeneficio'])->whereNumber('asignacion')->middleware('permiso:pag.beneficios.aprobar');

        // EDUN-81 · Morosidad.
        Route::get('/pagos/morosidad', [CobranzaController::class, 'morosidad'])->middleware('permiso:pag.cobranza.ver');
        Route::get('/pagos/morosidad/{apoderado}', [CobranzaController::class, 'historialCobranza'])->whereNumber('apoderado')->middleware('permiso:pag.cobranza.ver');
        Route::post('/pagos/morosidad/{apoderado}/gestiones', [CobranzaController::class, 'gestionCobranza'])->whereNumber('apoderado')->middleware('permiso:pag.cobranza.gestionar');
        Route::post('/pagos/morosidad/recordatorio', [CobranzaController::class, 'recordatorioMasivo'])->middleware(['permiso:pag.cobranza.gestionar', 'throttle:5,1']);

        // Sprint 22 · Convenios (EDUN-84/85/86/87).
        Route::get('/pagos/convenios', [FinanzasController::class, 'convenios'])->middleware('permiso:pag.convenios.ver');
        Route::get('/pagos/convenios/elegibles/{apoderado}', [FinanzasController::class, 'elegibles'])->whereNumber('apoderado')->middleware('permiso:pag.convenios.crear');
        Route::post('/pagos/convenios/simulacion', [FinanzasController::class, 'simular'])->middleware('permiso:pag.convenios.crear');
        Route::post('/pagos/convenios', [FinanzasController::class, 'crear'])->middleware('permiso:pag.convenios.crear');
        Route::post('/pagos/convenios/{convenio}/firma', [FinanzasController::class, 'firmaPapel'])->whereNumber('convenio')->middleware('permiso:pag.convenios.crear');
        Route::post('/pagos/convenios/{convenio}/aprobacion', [FinanzasController::class, 'aprobar'])->whereNumber('convenio')->middleware('permiso:pag.convenios.aprobar');
        Route::post('/pagos/convenios/{convenio}/anulacion', [FinanzasController::class, 'anular'])->whereNumber('convenio')->middleware('permiso:pag.convenios.anular');
        Route::get('/pagos/convenios/{convenio}/acta', [FinanzasController::class, 'acta'])->whereNumber('convenio')->middleware('permiso:pag.convenios.ver');
        Route::get('/familia/convenios', [FinanzasController::class, 'misConvenios']);
        Route::post('/familia/convenios/{convenio}/firma', [FinanzasController::class, 'firmarApp'])->whereNumber('convenio')->middleware('throttle:10,1');
        Route::post('/familia/convenios/{convenio}/cuotas/{cuota}/pago', [FinanzasController::class, 'pagarCuota'])->whereNumber('convenio')->whereNumber('cuota')->middleware('throttle:20,1');

        // Recaudación bancaria, conciliación en línea y reportes (EDUN-79/80/89/83).
        Route::get('/pagos/recaudacion', [FinanzasController::class, 'recaudacion'])->middleware('permiso:pag.recaudacion.ver');
        Route::post('/pagos/recaudacion/deudas', [FinanzasController::class, 'archivoDeudas'])->middleware(['permiso:pag.recaudacion.procesar', 'throttle:10,1']);
        Route::post('/pagos/recaudacion/pagos', [FinanzasController::class, 'archivoPagos'])->middleware(['permiso:pag.recaudacion.procesar', 'throttle:10,1']);
        Route::get('/pagos/recaudacion/{archivo}', [FinanzasController::class, 'lineas'])->whereNumber('archivo')->middleware('permiso:pag.recaudacion.ver');
        Route::get('/pagos/conciliacion', [FinanzasController::class, 'conciliacion'])->middleware('permiso:pag.pasarela.conciliar|pag.pasarela.ver');
        Route::post('/pagos/conciliacion', [FinanzasController::class, 'conciliar'])->middleware(['permiso:pag.pasarela.conciliar', 'throttle:10,1']);
        Route::get('/pagos/reportes', [FinanzasController::class, 'reportes'])->middleware('permiso:pag.reportes.ver');
        Route::get('/pagos/reportes/excel', [FinanzasController::class, 'reportesExcel'])->middleware(['permiso:pag.reportes.exportar', 'throttle:20,1']);

        // EDUN-82 · Estado de cuenta de la familia.
        Route::get('/familia/estado-cuenta', [CobranzaController::class, 'estadoCuenta']);

        // EDUN-75 / EDUN-76 · Caja.
        Route::get('/pagos/caja', [CobranzaController::class, 'caja'])->middleware('permiso:pag.caja.ver|pag.caja.abrir');
        Route::post('/pagos/cajas', [CobranzaController::class, 'crearCaja'])->middleware('permiso:pag.conceptos.editar');
        Route::post('/pagos/caja/abrir', [CobranzaController::class, 'abrir'])->middleware('permiso:pag.caja.abrir');
        Route::get('/pagos/caja/buscar', [CobranzaController::class, 'buscar'])->middleware('permiso:pag.pagos.registrar');
        Route::post('/pagos/caja/cobros', [CobranzaController::class, 'cobrar'])->middleware(['permiso:pag.pagos.registrar', 'throttle:60,1']);
        Route::post('/pagos/caja/cerrar', [CobranzaController::class, 'cerrar'])->middleware('permiso:pag.caja.cerrar');
        Route::get('/pagos/caja/sesiones/{sesion}/pdf', [CobranzaController::class, 'reporteCaja'])->whereNumber('sesion')->middleware('permiso:pag.caja.ver|pag.caja.cerrar');

        // EDUN-77 / EDUN-78 · Comprobantes electrónicos y notas de crédito.
        Route::get('/pagos/comprobantes', [CobranzaController::class, 'comprobantes'])->middleware('permiso:pag.comprobantes.ver');
        Route::get('/pagos/comprobantes/{comprobante}/{formato}', [CobranzaController::class, 'archivoComprobante'])->whereNumber('comprobante')->whereIn('formato', ['pdf', 'xml'])->middleware('permiso:pag.comprobantes.ver');
        Route::post('/pagos/comprobantes/{comprobante}/reenvio', [CobranzaController::class, 'reenviar'])->whereNumber('comprobante')->middleware('permiso:pag.comprobantes.reenviar');
        Route::post('/pagos/comprobantes/{comprobante}/correo', [CobranzaController::class, 'enviarCorreo'])->whereNumber('comprobante')->middleware(['permiso:pag.comprobantes.reenviar', 'throttle:20,1']);
        Route::post('/pagos/comprobantes/{comprobante}/nota-credito', [CobranzaController::class, 'notaCredito'])->whereNumber('comprobante')->middleware('permiso:pag.comprobantes.anular');
        Route::post('/pagos/comprobantes/{comprobante}/anulacion', [CobranzaController::class, 'anular'])->whereNumber('comprobante')->middleware('permiso:pag.comprobantes.anular');

        // EDUN-88 · Cuenta propia del colegio en la pasarela.
        Route::get('/pagos/pasarela', [CobranzaController::class, 'pasarela'])->middleware('permiso:pag.pasarela.ver');
        Route::post('/pagos/pasarela', [CobranzaController::class, 'guardarPasarela'])->middleware('permiso:pag.pasarela.configurar');
        Route::put('/pagos/pasarela/{cuenta}', [CobranzaController::class, 'guardarPasarela'])->whereNumber('cuenta')->middleware('permiso:pag.pasarela.configurar');
        Route::post('/pagos/pasarela/{cuenta}/prueba', [CobranzaController::class, 'probarPasarela'])->whereNumber('cuenta')->middleware(['permiso:pag.pasarela.configurar', 'throttle:10,1']);

        // EDUN-73 · La familia paga en línea (por propiedad: solo cargos de sus hijos).
        Route::get('/familia/pagos', [CobranzaController::class, 'misPagos']);
        Route::post('/familia/pagos/ordenes', [CobranzaController::class, 'crearOrden'])->middleware('throttle:20,1');
        Route::get('/familia/pagos/ordenes/{orden}', [CobranzaController::class, 'orden'])->where('orden', '[A-Za-z0-9-]{8,60}');
        Route::post('/familia/pagos/ordenes/{orden}/simulacion', [CobranzaController::class, 'simular'])->where('orden', '[A-Za-z0-9-]{8,60}')->middleware('throttle:20,1');
        Route::get('/familia/comprobantes/{comprobante}', [CobranzaController::class, 'miComprobante'])->whereNumber('comprobante');

        // EDUN-90 · Proveedores de facturación electrónica.
        Route::get('/pagos/proveedores-facturacion', [ConfiguracionPagosController::class, 'proveedores'])->middleware('permiso:pag.comprobantes.ver');
        Route::post('/pagos/proveedores-facturacion', [ConfiguracionPagosController::class, 'guardarProveedor'])->middleware('permiso:pag.comprobantes.emitir');
        Route::put('/pagos/proveedores-facturacion/{proveedor}', [ConfiguracionPagosController::class, 'guardarProveedor'])->whereNumber('proveedor')->middleware('permiso:pag.comprobantes.emitir');
    });

    // EDUN-55 · Ratificación de matrícula desde la app (por propiedad).
    Route::middleware('modulo:ADM')->group(function () {
        Route::get('/familia/ratificacion', [FamiliaController::class, 'ratificacion']);
        Route::post('/familia/ratificacion', [FamiliaController::class, 'ratificar'])->middleware('throttle:20,1');
        Route::get('/familia/constancias/{matricula}', [FamiliaController::class, 'constancia'])->whereNumber('matricula')->middleware('throttle:20,1');
    });

    // Sprint 18 · CAL: notas por competencia, actividades, cierre y reapertura.
    Route::middleware('modulo:CAL')->group(function () {
        Route::get('/notas/cargas', [NotasController::class, 'cargas'])->middleware('permiso:cal.notas.ver|cal.notas.registrar');
        Route::get('/notas/cargas/{carga}', [NotasController::class, 'grilla'])->whereNumber('carga')->middleware('permiso:cal.notas.ver|cal.notas.registrar');
        Route::put('/notas/cargas/{carga}/celda', [NotasController::class, 'guardar'])->whereNumber('carga')->middleware(['permiso:cal.notas.registrar', 'throttle:300,1']);
        Route::get('/notas/cargas/{carga}/actividades', [NotasController::class, 'actividades'])->whereNumber('carga')->middleware('permiso:cal.notas.ver|cal.notas.registrar');
        Route::post('/notas/cargas/{carga}/actividades', [NotasController::class, 'crearActividad'])->whereNumber('carga')->middleware('permiso:cal.notas.registrar');
        Route::put('/notas/actividades/{actividad}/notas', [NotasController::class, 'notasActividad'])->whereNumber('actividad')->middleware('permiso:cal.notas.registrar');
        Route::delete('/notas/actividades/{actividad}', [NotasController::class, 'eliminarActividad'])->whereNumber('actividad')->middleware('permiso:cal.notas.registrar');
        Route::get('/notas/periodos/{periodo}/avance', [NotasController::class, 'avance'])->whereNumber('periodo')->middleware('permiso:cal.notas.revisar');
        Route::post('/notas/periodos/{periodo}/recordatorio', [NotasController::class, 'recordar'])->whereNumber('periodo')->middleware(['permiso:cal.notas.revisar', 'throttle:5,1']);
        Route::post('/notas/periodos/{periodo}/cierre', [NotasController::class, 'cerrar'])->whereNumber('periodo')->middleware('permiso:cal.notas.cerrar');
        Route::post('/notas/periodos/{periodo}/reapertura', [NotasController::class, 'reabrir'])->whereNumber('periodo')->middleware('permiso:cal.notas.reabrir');

        // Sprint 19 · Asistencia (EDUN-94/95).
        Route::get('/asistencia/secciones', [SeguimientoController::class, 'secciones'])->middleware('permiso:cal.asistencia.registrar|cal.asistencia.ver');
        Route::get('/asistencia/secciones/{seccion}', [SeguimientoController::class, 'lista'])->whereNumber('seccion')->middleware('permiso:cal.asistencia.registrar|cal.asistencia.ver');
        Route::put('/asistencia/secciones/{seccion}', [SeguimientoController::class, 'guardar'])->whereNumber('seccion')->middleware(['permiso:cal.asistencia.registrar', 'throttle:120,1']);
        Route::post('/asistencia/qr', [SeguimientoController::class, 'qr'])->middleware(['permiso:cal.asistencia.registrar', 'throttle:600,1']);
        Route::post('/asistencias/lote', [SeguimientoController::class, 'loteQr'])->middleware(['permiso:cal.asistencia.registrar', 'throttle:120,1']);

        // Conducta, tutoría y alertas (EDUN-101/102/103).
        Route::get('/tutoria/alumnos/{alumno}', [SeguimientoController::class, 'historial'])->whereNumber('alumno')->middleware('permiso:cal.conducta.ver|cal.conducta.registrar');
        Route::post('/tutoria/alumnos/{alumno}/conducta', [SeguimientoController::class, 'conducta'])->whereNumber('alumno')->middleware('permiso:cal.conducta.registrar');
        Route::post('/tutoria/alumnos/{alumno}/entrevistas', [SeguimientoController::class, 'entrevista'])->whereNumber('alumno')->middleware('permiso:cal.conducta.registrar');
        Route::get('/alertas', [SeguimientoController::class, 'alertas'])->middleware('permiso:cal.alertas.ver');
        Route::post('/alertas/evaluacion', [SeguimientoController::class, 'evaluarAlertas'])->middleware(['permiso:cal.alertas.gestionar', 'throttle:10,1']);
        Route::patch('/alertas/{alerta}', [SeguimientoController::class, 'seguirAlerta'])->whereNumber('alerta')->middleware('permiso:cal.alertas.gestionar');

        // Libretas, acta, méritos y recuperación (EDUN-99/104/105).
        Route::get('/libretas', [SeguimientoController::class, 'libretas'])->middleware('permiso:cal.libretas.ver');
        Route::post('/libretas', [SeguimientoController::class, 'generarLibretas'])->middleware(['permiso:cal.libretas.generar', 'throttle:10,1']);
        Route::post('/libretas/publicacion', [SeguimientoController::class, 'publicarLibretas'])->middleware('permiso:cal.libretas.publicar');
        Route::get('/actas/{seccion}', [SeguimientoController::class, 'acta'])->whereNumber('seccion')->middleware('permiso:cal.libretas.ver');
        Route::get('/actas/{seccion}/{formato}', [SeguimientoController::class, 'actaArchivo'])->whereNumber('seccion')->whereIn('formato', ['pdf', 'xlsx'])->middleware(['permiso:cal.libretas.ver', 'throttle:20,1']);
        Route::put('/recuperacion', [SeguimientoController::class, 'recuperacion'])->middleware('permiso:cal.notas.aprobar');

        // La familia ve notas y libretas publicadas, sin bloqueo por deuda (EDUN-100).
        Route::get('/familia/notas', [SeguimientoController::class, 'notasFamilia']);
        Route::get('/familia/libretas/{libreta}', [SeguimientoController::class, 'libretaFamilia'])->whereNumber('libreta');
    });

    // Sprint 20 · Integración SIAGIE por archivos (EDUN-106 a 111).
    Route::middleware('modulo:SIA')->prefix('siagie')->group(function () {
        Route::get('/procesos', [SiagieController::class, 'procesos'])->middleware('permiso:sia.nomina.importar|sia.plantillas.descargar');
        Route::get('/procesos/{proceso}', [SiagieController::class, 'proceso'])->whereNumber('proceso')->middleware('permiso:sia.nomina.importar|sia.plantillas.descargar');
        Route::get('/procesos/{proceso}/{cual}', [SiagieController::class, 'archivo'])->whereNumber('proceso')->whereIn('cual', ['original', 'resultado'])->middleware('permiso:sia.plantillas.descargar|sia.nomina.importar');
        Route::post('/nominas', [SiagieController::class, 'subirNomina'])->middleware(['permiso:sia.nomina.importar', 'throttle:20,1']);
        Route::get('/validacion', [SiagieController::class, 'validar'])->middleware('permiso:sia.plantillas.validar');
        Route::post('/plantillas', [SiagieController::class, 'generar'])->middleware(['permiso:sia.plantillas.generar', 'throttle:30,1']);
        Route::post('/plantillas/lote', [SiagieController::class, 'lote'])->middleware(['permiso:sia.plantillas.generar', 'throttle:5,1']);
        Route::get('/mapeos/{grado}', [SiagieController::class, 'mapeo'])->whereNumber('grado')->middleware('permiso:sia.plantillas.validar');
        Route::put('/mapeos/{grado}', [SiagieController::class, 'guardarMapeo'])->whereNumber('grado')->middleware('permiso:sia.plantillas.validar');
    });

    // Sprint 21 · COM: comunicados, mensajería, agenda, citas, reclamos y bitácora.
    Route::middleware('modulo:COM')->group(function () {
        Route::get('/comunicados', [ComunicacionController::class, 'comunicados'])->middleware('permiso:com.comunicados.ver');
        Route::post('/comunicados', [ComunicacionController::class, 'crearComunicado'])->middleware(['permiso:com.comunicados.enviar', 'throttle:20,1']);
        Route::post('/comunicados/vista-previa', [ComunicacionController::class, 'vistaPrevia'])->middleware('permiso:com.comunicados.crear|com.comunicados.enviar');
        Route::post('/comunicados/{comunicado}/anulacion', [ComunicacionController::class, 'anularComunicado'])->whereNumber('comunicado')->middleware('permiso:com.comunicados.enviar');
        Route::get('/comunicados/{comunicado}/lectura', [ComunicacionController::class, 'lectura'])->whereNumber('comunicado')->middleware('permiso:com.comunicados.ver');
        Route::post('/comunicados/{comunicado}/recordatorio', [ComunicacionController::class, 'recordarComunicado'])->whereNumber('comunicado')->middleware(['permiso:com.comunicados.enviar', 'throttle:5,1']);
        Route::get('/familia/comunicados', [ComunicacionController::class, 'comunicadosFamilia']);
        Route::post('/familia/comunicados/{destinatario}', [ComunicacionController::class, 'marcarComunicado'])->whereNumber('destinatario');

        Route::get('/conversaciones', [ComunicacionController::class, 'conversaciones']);
        Route::post('/conversaciones', [ComunicacionController::class, 'iniciarConversacion'])->middleware('throttle:20,1');
        Route::get('/conversaciones/{conversacion}', [ComunicacionController::class, 'conversacion'])->whereNumber('conversacion');
        Route::post('/conversaciones/{conversacion}/mensajes', [ComunicacionController::class, 'enviarMensaje'])->whereNumber('conversacion')->middleware('throttle:60,1');

        Route::get('/agenda', [ComunicacionController::class, 'eventos']);
        Route::post('/agenda', [ComunicacionController::class, 'guardarEvento'])->middleware('permiso:com.agenda.editar');
        Route::put('/agenda/{evento}', [ComunicacionController::class, 'guardarEvento'])->whereNumber('evento')->middleware('permiso:com.agenda.editar');
        Route::delete('/agenda/{evento}', [ComunicacionController::class, 'eliminarEvento'])->whereNumber('evento')->middleware('permiso:com.agenda.editar');

        Route::get('/citas', [ComunicacionController::class, 'citas']);
        Route::post('/citas', [ComunicacionController::class, 'solicitarCita'])->middleware('throttle:10,1');
        Route::patch('/citas/{cita}', [ComunicacionController::class, 'gestionarCita'])->whereNumber('cita');

        Route::get('/reclamos', [ComunicacionController::class, 'reclamos'])->middleware('permiso:com.reclamaciones.ver');
        Route::post('/reclamos/{reclamo}/respuesta', [ComunicacionController::class, 'responderReclamo'])->whereNumber('reclamo')->middleware('permiso:com.reclamaciones.responder');
        Route::get('/notificaciones/bitacora', [ComunicacionController::class, 'bitacora'])->middleware('permiso:com.comunicados.ver');
    });

    // EDUN-199 · App del personal: movilidad y enfermería.
    Route::middleware('modulo:MOV')->group(function () {
        Route::get('/movilidad/mis-rutas', [AppPersonalController::class, 'rutas'])->middleware('permiso:mov.movilidad.registrar|mov.seguimiento.transmitir');
        Route::post('/movilidad/rutas/{ruta}/recorridos', [AppPersonalController::class, 'iniciar'])->whereNumber('ruta')->middleware('permiso:mov.seguimiento.transmitir');
        Route::post('/movilidad/recorridos/{recorrido}/posiciones', [AppPersonalController::class, 'posiciones'])->whereNumber('recorrido')->middleware(['permiso:mov.seguimiento.transmitir', 'throttle:240,1']);
        Route::post('/movilidad/recorridos/{recorrido}/eventos', [AppPersonalController::class, 'evento'])->whereNumber('recorrido')->middleware('permiso:mov.movilidad.registrar');
        Route::post('/movilidad/recorridos/{recorrido}/fin', [AppPersonalController::class, 'finalizar'])->whereNumber('recorrido')->middleware('permiso:mov.seguimiento.transmitir');
    });
    Route::get('/salud/alumnos', [AppPersonalController::class, 'buscarAlumno'])->middleware('permiso:sal.salud.registrar');
    Route::post('/salud/atenciones', [AppPersonalController::class, 'atender'])->middleware(['permiso:sal.salud.registrar', 'throttle:60,1']);

    // EDUN-26 · Conector de Moodle: solo si el colegio eligió Moodle o ambas.
    Route::middleware('modulo:MDL')->group(function () {
        Route::get('/moodle/conexion', [ConectorMoodleController::class, 'show'])->middleware('permiso:mdl.conector.ver');
        Route::put('/moodle/conexion', [ConectorMoodleController::class, 'update'])->middleware('permiso:mdl.conector.configurar');
    });
});

// EDUN-226 · Panel CMS: solo el superadmin del SaaS. Los colegios no tienen CMS.
Route::middleware(['auth:sanctum', 'cms'])->prefix('cms')->group(function () {
    Route::get('/sesion', SesionCmsController::class);
    // EDUN-233 · Borrador y publicación.
    Route::get('/estado', [EditorCmsController::class, 'estado']);
    Route::get('/secciones/{clave}', [EditorCmsController::class, 'mostrar']);
    Route::put('/secciones/{clave}', [EditorCmsController::class, 'guardar']);
    Route::post('/publicar', [EditorCmsController::class, 'publicar']);
    // EDUN-237 · Biblioteca de medios.
    Route::get('/medios', [MediosCmsController::class, 'index']);
    Route::post('/medios', [MediosCmsController::class, 'store'])->middleware('throttle:30,1');
    Route::patch('/medios/{medio}', [MediosCmsController::class, 'update'])->whereNumber('medio');
    // EDUN-278 · Bandeja de solicitudes.
    Route::get('/leads', [LeadsCmsController::class, 'index']);
    Route::patch('/leads/{lead}', [LeadsCmsController::class, 'update'])->whereNumber('lead');
    // EDUN-282 · Exportar (CSV) y sincronizar con el CRM.
    Route::get('/leads/exportar', [LeadsCmsController::class, 'exportar'])->middleware('throttle:30,1');
    Route::post('/leads/sincronizar', [LeadsCmsController::class, 'sincronizar'])->middleware('throttle:10,1');

    // Sprint 10 · Dashboard, auditoría y restauración, usuarios, SEO y políticas.
    Route::get('/dashboard', [PanelCmsController::class, 'dashboard']);
    Route::get('/auditoria', [PanelCmsController::class, 'auditoria']);
    Route::get('/auditoria/exportar', [PanelCmsController::class, 'exportarAuditoria'])->middleware('throttle:30,1');
    Route::post('/auditoria/{registro}/restaurar', [PanelCmsController::class, 'restaurar'])->whereNumber('registro');
    Route::get('/usuarios', [PanelCmsController::class, 'usuarios']);
    Route::get('/seo', [PanelCmsController::class, 'paginasSeo']);
    Route::get('/seo/{slug}', [PanelCmsController::class, 'seo'])->where('slug', '[a-z0-9][a-z0-9-]{0,119}');
    Route::put('/seo/{slug}', [PanelCmsController::class, 'guardarSeo'])->where('slug', '[a-z0-9][a-z0-9-]{0,119}');
    Route::get('/politicas', [PanelCmsController::class, 'politicas']);
    // EDUN-403 · Tracking e integraciones (las claves nunca se devuelven).
    Route::get('/integraciones', [PanelCmsController::class, 'integraciones']);
    Route::put('/integraciones', [PanelCmsController::class, 'guardarIntegraciones']);
    Route::post('/politicas/{tipo}', [PanelCmsController::class, 'publicarPolitica'])->whereIn('tipo', ['privacidad', 'terminos', 'seguridad', 'cookies']);

    // EDUN-368 · Blog de WordPress: lista, vista previa, mostrar u ocultar y sincronizar.
    Route::get('/blog', [BlogSeoCmsController::class, 'blog']);
    Route::post('/blog/sincronizar', [BlogSeoCmsController::class, 'sincronizarBlog'])->middleware('throttle:10,1');
    Route::get('/blog/{slug}', [BlogSeoCmsController::class, 'vistaPrevia'])->where('slug', '[a-z0-9][a-z0-9-]{0,199}');
    Route::patch('/blog/{slug}', [BlogSeoCmsController::class, 'visibilidad'])->where('slug', '[a-z0-9][a-z0-9-]{0,199}');
    // EDUN-412 · Asistente SEO con IA (proveedor configurable).
    Route::get('/seo-asistente', [BlogSeoCmsController::class, 'configuracionAsistente']);
    Route::put('/seo-asistente', [BlogSeoCmsController::class, 'guardarAsistente']);
    Route::post('/seo/{slug}/sugerencias', [BlogSeoCmsController::class, 'sugerir'])->where('slug', '[a-z0-9][a-z0-9-]{0,119}')->middleware('throttle:20,1');
    Route::post('/seo/{slug}/analisis', [BlogSeoCmsController::class, 'analizar'])->where('slug', '[a-z0-9][a-z0-9-]{0,119}');

    // EDUN-302 · Gestión de reclamaciones.
    Route::get('/reclamaciones', [ReclamacionesCmsController::class, 'index']);
    Route::get('/reclamaciones/{reclamacion}', [ReclamacionesCmsController::class, 'show'])->whereNumber('reclamacion');
    Route::patch('/reclamaciones/{reclamacion}', [ReclamacionesCmsController::class, 'update'])->whereNumber('reclamacion');
    Route::get('/reclamaciones/{reclamacion}/adjuntos/{indice}', [ReclamacionesCmsController::class, 'adjunto'])->whereNumber(['reclamacion', 'indice']);
});

// Sitio público (sin sesión): solo contenido publicado.
Route::get('/sitio/landing', LandingController::class)->middleware('throttle:publico');

// Sprint 6 · Políticas vigentes (EDUN-287), demo con consentimiento (EDUN-267, EDUN-291) y
// preferencias de cookies (EDUN-306). El público solo inserta: no puede leer leads ni consentimientos.
Route::prefix('sitio')->group(function () {
    Route::get('/politicas/{tipo}', [SitioLegalController::class, 'politica'])
        ->whereIn('tipo', ['privacidad', 'terminos', 'seguridad', 'cookies'])->middleware('throttle:publico');
    Route::middleware(['visitante', 'throttle:formularios'])->group(function () {
        Route::post('/leads', [SitioLegalController::class, 'lead']);
        // EDUN-271 · Popup de salida.
        Route::post('/leads/popup', [SitioLegalController::class, 'leadPopup']);
        Route::post('/consentimientos/cookies', [SitioLegalController::class, 'cookies']);
        // EDUN-375 · Contacto dejado en el chatbot.
        Route::post('/chatbot/lead', [SitioCanalesController::class, 'leadChatbot']);
        // EDUN-294 · Libro de Reclamaciones virtual.
        Route::post('/reclamaciones', [ReclamacionesController::class, 'store']);
    });
    // EDUN-363 · Blog desde WordPress (ya limpio y guardado por la API).
    Route::get('/blog', [SitioCanalesController::class, 'blog'])->middleware('throttle:publico');
    Route::get('/blog/{slug}', [SitioCanalesController::class, 'articulo'])->where('slug', '[a-z0-9][a-z0-9-]{0,199}')->middleware('throttle:publico');
    // EDUN-298 · Seguimiento: el límite de consultas fallidas lo aplica el controlador.
    Route::get('/reclamaciones/seguimiento', [ReclamacionesController::class, 'seguimiento'])->middleware(['visitante', 'throttle:publico']);
});
