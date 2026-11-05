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

export const cms = {
  nombrePanel: 'Panel CMS',
  tituloInicio: 'Panel CMS',
  descripcionInicio: 'Las pantallas del panel llegan con las historias del sitio (EP-SIT).',
  grupos: [
    {
      titulo: 'Estructura del portal',
      enlaces: [
        { href: '/cms/sitio/secciones', etiqueta: 'Hero y Portada', icono: 'view_carousel' },
        { href: '/cms/conversion/planes', etiqueta: 'Planes y Tarifas', icono: 'payments' },
        { href: '/cms/contenido/modulos', etiqueta: 'Módulos Web', icono: 'widgets' },
        { href: '/cms/contenido/testimonios', etiqueta: 'Casos de Éxito', icono: 'reviews' },
      ],
    },
    {
      titulo: 'Gestión',
      enlaces: [
        { href: '/cms/seo/paginas', etiqueta: 'Ajustes SEO', icono: 'travel_explore' },
        { href: '/cms/leads/bandeja', etiqueta: 'Bandeja de solicitudes', icono: 'inbox' },
        { href: '/cms/auditoria', etiqueta: 'Auditoría de cambios', icono: 'history' },
      ],
    },
  ],
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
