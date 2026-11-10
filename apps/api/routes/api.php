<?php

use App\Http\Controllers\SaludController;
use App\Modulos\ADM\Http\Controllers\AlumnosController;
use App\Modulos\ADM\Http\Controllers\AniosEscolaresController;
use App\Modulos\ADM\Http\Controllers\InstitucionController;
use App\Modulos\Compartido\Correo\EventosCorreoController;
use App\Modulos\MDL\Http\Controllers\ConectorMoodleController;
use App\Modulos\PLA\Http\Controllers\InstitucionesPlataformaController;
use App\Modulos\SEG\Http\Controllers\ArchivosController;
use App\Modulos\SEG\Http\Controllers\AuditoriaController;
use App\Modulos\SEG\Http\Controllers\DosPasosController;
use App\Modulos\SEG\Http\Controllers\InvitacionesController;
use App\Modulos\SEG\Http\Controllers\SesionController;
use App\Modulos\SEG\Http\Controllers\UsuariosController;
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
    });
});

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
    Route::middleware('plataforma:superadmin')->group(function () {
        Route::post('/instituciones', [InstitucionesPlataformaController::class, 'store'])->middleware('throttle:20,1');
        Route::put('/instituciones/{institucion}/modulos/{modulo}', [InstitucionesPlataformaController::class, 'modulo'])->whereNumber('institucion')->where('modulo', '[A-Za-z]{2,10}');
        Route::put('/instituciones/{institucion}/aula', [InstitucionesPlataformaController::class, 'aula'])->whereNumber('institucion');
        Route::post('/instituciones/{institucion}/promotores/{usuario}/invitacion', [InstitucionesPlataformaController::class, 'reinvitar'])->whereNumber(['institucion', 'usuario'])->middleware('throttle:10,1');
    });
});

// Rutas del colegio: sesión, colegio activo (RLS) y permiso por ruta (EDUN-39).
Route::middleware(['auth:sanctum', 'colegio'])->group(function () {
    Route::get('/alumnos', [AlumnosController::class, 'index'])->middleware('permiso:adm.alumnos.ver');
    // Sin permiso de ruta: la policy deja pasar al personal con adm.alumnos.ver y a la familia por propiedad.
    Route::get('/alumnos/{alumno}', [AlumnosController::class, 'show'])->whereNumber('alumno');

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
        Route::get('/anios-escolares', [AniosEscolaresController::class, 'index'])->middleware('permiso:adm.configuracion.ver');
        Route::get('/anios-escolares/{anio}', [AniosEscolaresController::class, 'show'])->whereNumber('anio')->middleware('permiso:adm.configuracion.ver');
        Route::post('/anios-escolares', [AniosEscolaresController::class, 'store'])->middleware('permiso:adm.configuracion.editar');
        Route::put('/anios-escolares/{anio}', [AniosEscolaresController::class, 'update'])->whereNumber('anio')->middleware('permiso:adm.configuracion.editar');
    });

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
