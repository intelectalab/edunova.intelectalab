// Ayuda por pantalla del Panel CMS (botón «Ayuda»): para qué sirve, qué se puede y qué no.

export type AyudaPantalla = { titulo: string; para: string; puedes: string[]; noPuedes?: string[]; consejo?: string };

const BORRADOR = 'Los cambios quedan como borrador hasta que pulsas «Publicar»: el sitio no cambia antes.';

export const ayudaCms: Record<string, AyudaPantalla> = {
  '/cms': {
    titulo: 'Panel principal',
    para: 'Resumen del sitio: solicitudes de demo, reclamos por vencer y borradores sin publicar.',
    puedes: ['Ir directo a lo pendiente con los accesos de cada tarjeta.', 'Buscar cualquier pantalla en la navegación rápida.'],
  },
  '/cms/sitio/secciones': {
    titulo: 'Hero y portada',
    para: 'Lo primero que ve un visitante: insignia, titular, subtítulo y botón principal.',
    puedes: ['Escribir y ver el cambio en vivo en la vista previa (escritorio y celular).', 'Guardar borrador y publicar.'],
    noPuedes: ['Publicar textos que superen el largo máximo: el contador se pone en rojo.'],
    consejo: BORRADOR,
  },
  '/cms/conversion/planes': {
    titulo: 'Planes y tarifas',
    para: 'Planes, precios en soles, complementos y nota legal de la página de planes.',
    puedes: ['Cambiar precios, características y el plan recomendado.'],
    noPuedes: ['Dejar un plan sin precio o con un precio negativo.'],
    consejo: BORRADOR,
  },
  '/cms/contenido/modulos': {
    titulo: 'Módulos web',
    para: 'Los 18 módulos del sistema y cuáles salen en el grid de la portada.',
    puedes: ['Ordenar, editar textos y elegir los 6 del grid.'],
    noPuedes: ['Poner más de 6 módulos en el grid.'],
    consejo: BORRADOR,
  },
  '/cms/contenido/testimonios': {
    titulo: 'Casos de éxito',
    para: 'Testimonios de clientes.',
    puedes: ['Agregar testimonios con foto y cargo.'],
    noPuedes: ['Publicar un testimonio sin marcar el consentimiento de la persona.'],
    consejo: BORRADOR,
  },
  '/cms/contenido/logos': {
    titulo: 'Logos de clientes',
    para: 'Logos de «Confían en Edunova».',
    puedes: ['Subir logos y ordenarlos.'],
    noPuedes: ['Mostrar un logo sin autorización de uso del cliente.'],
  },
  '/cms/sitio/estructura': {
    titulo: 'Editor de secciones',
    para: 'Orden y visibilidad de las secciones de la portada.',
    puedes: ['Subir, bajar u ocultar secciones.'],
    noPuedes: ['Ocultar el encabezado ni el pie de página.'],
    consejo: BORRADOR,
  },
  '/cms/conversion/popup': {
    titulo: 'Popup de salida',
    para: 'Mensaje que aparece cuando el visitante va a salir del sitio.',
    puedes: ['Cambiar texto, imagen y reglas (en qué páginas y cada cuánto).'],
    consejo: BORRADOR,
  },
  '/cms/conversion/chatbot': {
    titulo: 'Chatbot',
    para: 'El chat del sitio: un flujo de pasos o el paso directo a WhatsApp, con su horario.',
    puedes: [
      'Armar el flujo en el lienzo: agregar pasos, arrastrarlos y unirlos.',
      'Ver avisos de pasos a los que no se llega o donde la conversación se queda detenida.',
      'Ver el historial de conversaciones.',
    ],
    noPuedes: ['Usar el modo WhatsApp sin cargar el número en Configuración general.', 'Tener más de 40 pasos o más de 6 opciones por pregunta.'],
    consejo: BORRADOR,
  },
  '/cms/conversion/chatbot/sesiones': {
    titulo: 'Historial del chatbot',
    para: 'Conversaciones del sitio y cómo terminaron (contacto, WhatsApp, enlace, fin o abandono).',
    puedes: ['Filtrar por fecha, resultado, contacto y texto.', 'Leer la conversación completa.'],
    noPuedes: ['Ver datos personales aquí: los contactos están en la Bandeja de solicitudes.'],
  },
  '/cms/contenido/faq': {
    titulo: 'Preguntas frecuentes',
    para: 'Preguntas y respuestas de la página de ayuda del sitio.',
    puedes: ['Agregar, ordenar y ocultar preguntas por categoría.'],
    consejo: BORRADOR,
  },
  '/cms/seo/paginas': {
    titulo: 'Ajustes SEO',
    para: 'Cómo aparece cada página en Google y al compartirla.',
    puedes: [
      'Editar título, meta descripción, palabras clave y Open Graph.',
      'Pedir al asistente con IA 3 meta descripciones y palabras clave; elegir una solo llena el formulario.',
      'Ver si el título o la descripción se repiten en otra página.',
    ],
    noPuedes: ['Guardar un título de más de 70 caracteres o una descripción de más de 160.'],
    consejo: '«Guardar y actualizar metadatos» publica al instante y queda en la auditoría.',
  },
  '/cms/blog': {
    titulo: 'Blog (WordPress)',
    para: 'Los posts se escriben en WordPress; aquí se revisan antes de difundirlos.',
    puedes: ['Ver la vista previa con el estilo del sitio.', 'Abrir el post en WordPress para editarlo.', 'Ocultarlo o mostrarlo en el sitio (al instante).'],
    noPuedes: ['Escribir o editar posts aquí: eso se hace en WordPress.'],
  },
  '/cms/leads/bandeja': {
    titulo: 'Bandeja de solicitudes',
    para: 'Solicitudes de demo y contactos del sitio, del popup y del chatbot.',
    puedes: ['Cambiar el estado (nuevo, contactado, ganado…).', 'Exportar a CSV y enviar al CRM.'],
    noPuedes: ['Borrar solicitudes: se marcan como perdidas.'],
  },
  '/cms/reclamaciones': {
    titulo: 'Libro de reclamaciones',
    para: 'Quejas y reclamos del sitio con su plazo legal de respuesta.',
    puedes: ['Responder al reclamante por correo y cambiar el estado.'],
    noPuedes: ['Borrar un reclamo: es un registro legal (Indecopi).'],
  },
  '/cms/medios': {
    titulo: 'Biblioteca de medios',
    para: 'Imágenes y documentos que usa el sitio.',
    puedes: ['Subir archivos y escribir su texto alternativo (accesibilidad).'],
    noPuedes: ['Subir archivos de más del tamaño permitido o de tipos no admitidos.'],
  },
  '/cms/legal/politicas': {
    titulo: 'Políticas legales',
    para: 'Privacidad, términos, seguridad de datos y cookies, con versiones.',
    puedes: ['Publicar una versión nueva con fecha de vigencia.'],
    noPuedes: ['Editar o borrar una versión publicada: son la prueba de lo que aceptó cada persona.'],
  },
  '/cms/configuracion': {
    titulo: 'Configuración general',
    para: 'Datos legales y de contacto del sitio, y modo mantenimiento.',
    puedes: ['Cambiar razón social, RUC, teléfono, WhatsApp y correos.', 'Activar el modo mantenimiento con un mensaje.'],
    noPuedes: ['Guardar un RUC inválido.'],
  },
  '/cms/integraciones': {
    titulo: 'Tracking e integraciones',
    para: 'Google Tag Manager, Analytics, píxeles, CRM y WordPress.',
    puedes: ['Activar o desactivar cada integración.'],
    noPuedes: ['Ver las claves guardadas: se guardan cifradas.'],
  },
  '/cms/usuarios': {
    titulo: 'Usuarios con acceso',
    para: 'Quién puede entrar al Panel CMS (equipo de Edunova).',
    puedes: ['Ver los usuarios, su último acceso y si usan segundo factor.'],
    noPuedes: ['Dar acceso a usuarios de colegios: el CMS es solo del equipo de Edunova.'],
  },
  '/cms/auditoria': {
    titulo: 'Auditoría de cambios',
    para: 'Quién cambió qué y cuándo en el sitio.',
    puedes: ['Filtrar, exportar y restaurar un valor anterior (queda como borrador).'],
    noPuedes: ['Editar o borrar el registro de auditoría.'],
  },
};
