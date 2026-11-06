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
