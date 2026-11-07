/*
 * Contenido provisional. Regla del sitio: todo texto visible sale del CMS, nada fijo en los
 * componentes. Mientras no existan el esquema cms (EDUN-223) ni GET /api/v1/sitio/landing,
 * los textos viven solo aquí, con la forma que tendrán al venir de la API. Este archivo se
 * reemplaza por la lectura del CMS; los componentes no cambian.
 */

export const sitio = {
  marca: 'Edunova',
  titulo: 'Edunova',
  enConstruccion: 'Sitio público en construcción.',
};

/** EDUN-242 · Textos fijos de la cabecera de la landing (lo editable viene de cms.secciones). */
export const landing = {
  panelCms: 'Panel CMS',
  atencion: 'Atención Lima:',
  pais: 'Perú',
  menuPrincipal: 'Menú principal',
  iniciarSesion: 'Iniciar sesión',
  solicitarDemo: 'Solicitar demo',
  central: 'Central:',
  direccion: 'Dirección:',
  otros: 'Enlaces',
  atencionUsuario: 'Atención al usuario',
  libroReclamaciones: 'Libro de Reclamaciones',
  leyConsumidor: 'Conforme a la Ley N.° 29571',
  horario: 'Horario de soporte:',
  recomendado: 'Recomendado',
  cifras: 'Cifras del producto',
  incluye: 'Incluye:',
  noIncluye: 'No incluye:',
  cerrarVideo: 'Cerrar video',
  porAnio: '/ año',
  estrellas: (n: number) => `${n} de 5 estrellas`,
  mockupDescripcion: 'Ilustración del panel de Edunova con datos de un colegio de ejemplo.',
  mockup: {
    producto: 'Edunova',
    colegio: 'Colegio de ejemplo',
    periodo: 'Ciclo 2027 · II Bimestre',
    saludo: 'Buenos días, María',
    resumen: 'Resumen del día.',
    kpis: [
      { etiqueta: 'Matriculados', valor: '842', icono: 'groups' },
      { etiqueta: 'Asistencia', valor: '94.6%', icono: 'check_circle' },
      { etiqueta: 'Cobranza', valor: '87%', icono: 'payments' },
      { etiqueta: 'Morosidad', valor: '6.2%', icono: 'warning' },
    ],
    grafico: 'Cobranza de pensiones 2027',
    barras: [
      { mes: 'Mar', valor: 98 },
      { mes: 'Abr', valor: 97 },
      { mes: 'May', valor: 96 },
      { mes: 'Jun', valor: 95 },
      { mes: 'Jul', valor: 94 },
      { mes: 'Ago', valor: 93 },
      { mes: 'Set', valor: 91 },
      { mes: 'Oct', valor: 87 },
    ],
  },
};

export type EnlaceCms = { href: string; etiqueta: string; icono: string; descripcion: string; roles: string[] };

const SOLO_SUPERADMIN = ['superadmin'];

export const cms = {
  nombrePanel: 'Panel CMS',
  cerrarSesion: 'Cerrar sesión',
  nombresRol: { superadmin: 'Superadministrador' } as Record<string, string>,
  tituloInicio: 'Panel CMS',
  descripcionInicio: 'Las pantallas del panel llegan con las historias del sitio (EP-SIT).',
  enConstruccion: 'Esta pantalla llega con su historia del sitio (EP-SIT).',
  editor: {
    cambiosSinPublicar: 'Cambios sin publicar',
    ultimaPublicacion: 'Última publicación:',
    por: 'por',
    sinPublicaciones: 'Aún no hay publicaciones desde el panel.',
    borradorGuardado: 'Borrador guardado',
    publicado: 'Cambios publicados. El sitio se actualiza en unos segundos.',
    sinCambios: 'No había cambios por publicar.',
  },
  hero: {
    titulo: 'Hero y Portada',
    descripcion: 'Textos de la portada. Los cambios se guardan como borrador hasta que publiques.',
    campos: {
      insignia: { etiqueta: 'Insignia', max: 45 },
      titulo: { etiqueta: 'Titular (H1)', max: 90 },
      subtitulo: { etiqueta: 'Subtítulo', max: 220 },
      cta_principal: { etiqueta: 'Botón principal', max: 30 },
      cta_secundario: { etiqueta: 'Botón secundario (opcional)', max: 30 },
      sellos: { etiqueta: 'Sellos de confianza (opcional)', max: 120 },
    },
    borradorPendiente: 'Tienes un borrador guardado que aún no está publicado.',
    errorCarga: 'No pudimos cargar la sección. Recarga la página.',
  },
  medios: {
    titulo: 'Biblioteca de medios',
    descripcion: 'Imágenes y documentos del sitio. Súbelos una vez y reutilízalos en módulos, testimonios y SEO.',
    abrirSelector: 'Subir o elegir de la Biblioteca',
    tituloSelector: 'Subir o elegir de la Biblioteca',
    cerrar: 'Cerrar',
    subir: 'Archivo',
    reglas: 'JPG, PNG, WebP, SVG o PDF hasta 5 MB. Los SVG se limpian antes de guardarse.',
    alt: 'Texto alternativo (obligatorio para publicar imágenes)',
    botonSubir: 'Subir',
    subiendo: 'Subiendo…',
    elegirArchivo: 'Elige un archivo.',
    superaLimite: 'El archivo supera 5 MB.',
    biblioteca: 'Archivos de la Biblioteca',
    cargando: 'Cargando…',
    vacia: 'Todavía no hay archivos.',
    vistaPrevia: 'Vista previa',
    listo: 'Archivo listo',
    sinAlt: 'Falta el texto alternativo',
  },
  barra: {
    verSitio: 'Ver sitio público',
    guardar: 'Guardar borrador',
    publicar: 'Publicar',
    sinCambios: 'Disponible cuando haya cambios en una pantalla de edición.',
    abrirMenu: 'Abrir menú',
    cerrarMenu: 'Cerrar menú',
    navegacionRapida: 'Navegación rápida',
    migas: 'Ubicación',
    estadoSitio: 'Sitio público:',
    enLinea: 'En línea',
    sinDominio: 'Sin dominio publicado',
  },
  grupos: [
    {
      titulo: 'Estructura del portal',
      enlaces: [
        { href: '/cms/sitio/secciones', etiqueta: 'Hero y Portada', icono: 'view_carousel', descripcion: 'Insignia, titular, subtítulo y botón principal de la portada.', roles: SOLO_SUPERADMIN },
        { href: '/cms/conversion/planes', etiqueta: 'Planes y Tarifas', icono: 'payments', descripcion: 'Planes, precios en soles, complementos y nota legal.', roles: SOLO_SUPERADMIN },
        { href: '/cms/contenido/modulos', etiqueta: 'Módulos Web', icono: 'widgets', descripcion: 'Los 18 módulos, su orden y los 6 del grid principal.', roles: SOLO_SUPERADMIN },
        { href: '/cms/contenido/testimonios', etiqueta: 'Casos de Éxito', icono: 'reviews', descripcion: 'Testimonios con consentimiento y logos de clientes.', roles: SOLO_SUPERADMIN },
      ] as EnlaceCms[],
    },
    {
      titulo: 'Gestión',
      enlaces: [
        { href: '/cms/seo/paginas', etiqueta: 'Ajustes SEO', icono: 'travel_explore', descripcion: 'Title, meta descripción, canonical y Open Graph.', roles: SOLO_SUPERADMIN },
        { href: '/cms/leads/bandeja', etiqueta: 'Bandeja de solicitudes', icono: 'inbox', descripcion: 'Solicitudes de demo y su estado.', roles: SOLO_SUPERADMIN },
        { href: '/cms/medios', etiqueta: 'Biblioteca de medios', icono: 'perm_media', descripcion: 'Imágenes y documentos del sitio.', roles: SOLO_SUPERADMIN },
        { href: '/cms/auditoria', etiqueta: 'Auditoría de cambios', icono: 'history', descripcion: 'Quién cambió qué y cuándo, con opción de restaurar.', roles: SOLO_SUPERADMIN },
      ] as EnlaceCms[],
    },
  ],
};

/** Ítems del menú que puede abrir un rol (EDUN-230: el menú muestra solo esos). */
export function enlacesPara(rol: string) {
  return cms.grupos
    .map((g) => ({ ...g, enlaces: g.enlaces.filter((e) => e.roles.includes(rol)) }))
    .filter((g) => g.enlaces.length > 0);
}

export function enlacePorRuta(ruta: string): EnlaceCms | undefined {
  return cms.grupos.flatMap((g) => g.enlaces).find((e) => e.href === ruta);
}

/** EDUN-226 · Ingreso al Panel CMS. */
export const cmsIngreso = {
  titulo: 'Ingresa al Panel CMS',
  subtitulo: 'Panel CMS',
  usuario: 'Correo o DNI',
  password: 'Contraseña',
  boton: 'Ingresar',
  enviando: 'Ingresando…',
  errorFaltanDatos: 'Escribe tu correo o DNI y tu contraseña.',
  errorSinAcceso: 'Tu usuario no tiene acceso al Panel CMS. Los colegios administran su información en la web de gestión.',
  errorConexion: 'No pudimos conectar con Edunova. Inténtalo de nuevo en unos minutos.',
};

/** EDUN-226 · Pantalla 403 del Panel CMS. */
export const cmsSinAcceso = {
  titulo: 'No tienes acceso al Panel CMS',
  detalle: 'Solo el equipo de Edunova administra el sitio web. Los colegios administran su información en la web de gestión.',
  otraCuenta: 'Ingresar con otra cuenta',
  irAlSitio: 'Ir al sitio público',
};

/** Textos de la página de muestra de componentes (/muestra). Son datos de demostración. */
export const muestra = {
  titulo: 'Componentes base del sitio',
  descripcion: 'Página de muestra para revisar los componentes contra el prototipo en escritorio y a 390 px.',
  botones: { titulo: 'Botones', accion: 'Publicar cambios', institucional: 'Solicitar demo', secundario: 'Guardar borrador', fantasma: 'Cancelar' },
  interruptor: { titulo: 'Interruptor de visibilidad', etiqueta: 'Visible en el sitio' },
  chips: {
    titulo: 'Navegación rápida',
    lista: [
      { href: '/muestra', etiqueta: 'Hero', icono: 'view_carousel', activo: true },
      { href: '/muestra#planes', etiqueta: 'Planes', icono: 'payments' },
      { href: '/muestra#modulos', etiqueta: 'Módulos', icono: 'widgets' },
    ],
  },
  insignias: { titulo: 'Insignias de estado', exito: 'Publicado', aviso: 'Cambios sin publicar', error: 'Sin consentimiento', info: 'Recomendado', neutro: 'Oculto' },
  tarjeta: { titulo: 'Tarjeta de sección', icono: 'view_carousel', descripcion: 'Ícono, título, descripción e insignia.' },
  tabla: {
    titulo: 'Tabla de datos',
    vacio: 'No hay registros.',
    columnas: { autor: 'Autor', calificacion: 'Calificación', estado: 'Estado' },
    filas: [
      { id: 't1', autor: 'Directora de colegio (ejemplo)', calificacion: '5 / 5', estado: 'Publicado' },
      { id: 't2', autor: 'Promotor de colegio (ejemplo)', calificacion: '4 / 5', estado: 'Oculto' },
    ],
  },
  linea: {
    titulo: 'Línea de tiempo',
    eventos: [
      { id: 'e1', titulo: 'Plan Integral actualizado', fecha: '04/11/2026 10:42', detalle: 'Cambio de ejemplo.', destacado: true },
      { id: 'e2', titulo: 'Testimonio ocultado', fecha: '03/11/2026 16:05', detalle: 'Cambio de ejemplo.' },
    ],
  },
  modal: { titulo: 'Modal', abrir: 'Abrir modal', tituloModal: 'Solicitar demo gratuita', cuerpo: 'Contenido de ejemplo del modal.', cerrar: 'Cerrar' },
  toast: { titulo: 'Aviso flotante', mostrar: 'Mostrar aviso', texto: 'Borrador guardado' },
};

/** EDUN-267 / SIT-002 · Ventana «Solicitar demo gratuita» con calendario (prototipo #demo). */
export const demo = {
  titulo: 'Solicitar demo gratuita',
  cerrar: 'Cerrar',
  pasoContacto: '1. Datos de contacto',
  pasoCalendario: '2. Elige fecha y hora de la demo',
  nombre: 'Nombre completo *',
  cargo: 'Cargo *',
  telefono: 'Teléfono / WhatsApp *',
  telefonoEjemplo: '9XX XXX XXX',
  email: 'Correo electrónico *',
  colegio: 'Nombre del colegio *',
  alumnos: 'N.° de alumnos *',
  plan: 'Plan de interés',
  elegir: 'Elegir…',
  aunNoSe: 'Aún no sé',
  cargos: ['Promotor(a)', 'Director(a)', 'Administrador(a)', 'Coordinador(a) académico', 'Secretaría / Tesorería', 'Otro'],
  rangosAlumnos: [
    { valor: '150', etiqueta: 'Hasta 150' },
    { valor: '300', etiqueta: '151 a 300' },
    { valor: '500', etiqueta: '301 a 500' },
    { valor: '800', etiqueta: '501 a 800' },
    { valor: '801', etiqueta: 'Más de 800' },
  ],
  planes: [
    { valor: 'inicial', etiqueta: 'Inicial' },
    { valor: 'crecimiento', etiqueta: 'Crecimiento' },
    { valor: 'integral', etiqueta: 'Integral' },
    { valor: 'institucional', etiqueta: 'Institucional' },
  ],
  mesAnterior: 'Mes anterior',
  mesSiguiente: 'Mes siguiente',
  diasSemana: ['Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sá', 'Do'],
  meses: ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'setiembre', 'octubre', 'noviembre', 'diciembre'],
  diasLargos: ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'],
  noDisponible: 'no disponible',
  horas: [
    { valor: '09:00', etiqueta: '9:00 a. m.' },
    { valor: '10:30', etiqueta: '10:30 a. m.' },
    { valor: '12:00', etiqueta: '12:00 m.' },
    { valor: '15:00', etiqueta: '3:00 p. m.' },
    { valor: '16:30', etiqueta: '4:30 p. m.' },
    { valor: '18:00', etiqueta: '6:00 p. m.' },
  ],
  horasDisponibles: 'Horas disponibles (hora de Lima)',
  resumenInicial: 'Demo por videollamada · 30 minutos · hora de Lima',
  seleccionado: (fecha: string, hora: string | null) => `Seleccionado: ${fecha} · ${hora ?? 'elige una hora'}`,
  consentimientoAntes: 'He leído y acepto la ',
  consentimientoEnlace: 'Política de Privacidad',
  consentimientoDespues: ' y autorizo el uso de mis datos para coordinar la demo.',
  trampa: 'Sitio web (déjalo vacío)',
  agendar: 'Agendar mi demo',
  enviando: 'Enviando…',
  errorIncompleto: 'Completa los campos marcados con * y acepta la Política de Privacidad.',
  errorCalendario: 'Elige el día y la hora de la demo en el calendario.',
  errorConexion: 'No pudimos registrar tu solicitud. Inténtalo de nuevo en unos minutos.',
  listoTitulo: 'Solicitud registrada',
  listo: (nombre: string, fecha: string, hora: string, email: string) =>
    `Gracias, ${nombre}. Un asesor confirmará tu demo del ${fecha} a las ${hora} en ${email}.`,
  cerrarListo: 'Cerrar',
};

/** EDUN-287 / SIT-053 · Ventana y páginas de políticas. */
export const politicas = {
  cerrar: 'Cerrar',
  cargando: 'Cargando…',
  error: 'No pudimos cargar la política. Inténtalo de nuevo en unos minutos.',
  version: (version: string, desde: string) => `Versión ${version} · vigente desde ${desde}`,
  borrador: 'Borrador pendiente de revisión legal',
  borradorDetalle: 'Este texto es provisional y puede cambiar antes de su versión definitiva.',
  noPublicada: 'Esta política aún no está publicada.',
  titulos: {
    privacidad: 'Política de Privacidad',
    terminos: 'Términos y Condiciones del servicio',
    seguridad: 'Seguridad de Datos (LPDP)',
    cookies: 'Política de Cookies',
  } as Record<string, string>,
};

/** EDUN-306 / SIT-055 · Banner de cookies (sin diseño en el prototipo: estilo de la landing). */
export const cookies = {
  titulo: 'Tu privacidad en Edunova',
  texto: 'Usamos cookies esenciales para que el sitio funcione. Con tu permiso, también usamos cookies de analítica, marketing y personalización.',
  verPolitica: 'Política de Cookies',
  aceptarTodo: 'Aceptar todo',
  soloEsenciales: 'Solo esenciales',
  configurar: 'Configurar',
  guardar: 'Guardar mis preferencias',
  tituloConfigurar: 'Preferencias de cookies',
  cerrar: 'Cerrar',
  preferencias: 'Preferencias de cookies',
  siempreActivas: 'Siempre activas',
  categorias: [
    { clave: 'esenciales', titulo: 'Esenciales', descripcion: 'Necesarias para el funcionamiento y la seguridad del sitio, y para recordar tu elección.' },
    { clave: 'analitica', titulo: 'Analítica', descripcion: 'Nos dicen qué páginas se visitan para mejorar el sitio (Google Analytics).' },
    { clave: 'marketing', titulo: 'Marketing', descripcion: 'Miden campañas y muestran anuncios de Edunova en otros sitios.' },
    { clave: 'personalizacion', titulo: 'Personalización', descripcion: 'Recuerdan preferencias para adaptar el contenido.' },
  ],
};
