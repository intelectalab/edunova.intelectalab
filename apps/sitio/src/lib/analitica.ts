// EDUN-318 / SIT-058 · Eventos de analítica en el dataLayer (TRASPASO §10).
//
// Los eventos siempre van al dataLayer, pero solo salen del navegador si GTM está cargado, y GTM
// solo se carga con consentimiento de analítica o marketing (Consent Mode v2, EDUN-306).
// Nunca llevan datos personales: los parámetros pasan por limpiarParametros().

/** Catálogo de eventos del sitio público (nombres en snake_case, como GA4). */
export const EVENTOS = {
  /** Clic en un botón o enlace que abre la demo. Parámetros: cta_text, ubicacion. */
  cta_click: 'cta_click',
  /** Clic en «Elegir plan». Parámetros: plan. */
  plan_click: 'plan_click',
  /** Se abrió la ventana de demo. Parámetros: source, plan. */
  demo_modal_open: 'demo_modal_open',
  /** Formulario enviado con éxito (demo, popup, reclamo). Parámetros: source, plan, utm_source, utm_campaign. */
  form_submit: 'form_submit',
  /** Formulario rechazado por validación. Parámetros: source, campos (nombres de campo, sin valores). */
  form_error: 'form_error',
  /** Demo agendada. Parámetros: plan, alumnos. */
  demo_request: 'demo_request',
  /** Video de la galería. Parámetros: video_title. */
  video_play: 'video_play',
  /** Popup de salida. Parámetros: disparador (salida | inactividad). */
  exit_popup_view: 'exit_popup_view',
  exit_popup_close: 'exit_popup_close',
  exit_popup_submit: 'exit_popup_submit',
  /** WhatsApp, teléfono o correo de contacto. Parámetros: canal, ubicacion. */
  contact_click: 'contact_click',
  /** Profundidad de scroll (25, 50, 75, 90). Parámetros: porcentaje. */
  scroll_depth: 'scroll_depth',
  /** Una sección de la landing entró en pantalla (una vez por visita a la página). Parámetros: seccion. */
  section_view: 'section_view',
  /** Se abrió una pregunta frecuente. Parámetros: pregunta. */
  faq_open: 'faq_open',
  /** Hoja del Libro de Reclamaciones registrada. Parámetros: tipo. */
  reclamo_submit: 'reclamo_submit',
} as const;

export type Evento = keyof typeof EVENTOS;

/** Claves que nunca pueden viajar a analítica. */
const PROHIBIDAS = /^(email|correo|telefono|tel|phone|nombre|name|documento|numero_doc|dni|ruc|direccion|address|mensaje|descripcion|expediente)$/i;
const PARECE_CORREO = /[^\s@]+@[^\s@]+\.[^\s@]+/;
const PARECE_TELEFONO = /(\+?\d[\d\s()-]{7,}\d)/;

/** Quita datos personales y recorta los textos (máx. 100 caracteres, como GA4). */
export function limpiarParametros(parametros: Record<string, unknown>): Record<string, string | number | boolean> {
  const limpio: Record<string, string | number | boolean> = {};
  for (const [clave, valor] of Object.entries(parametros)) {
    if (PROHIBIDAS.test(clave) || valor === undefined || valor === null) continue;
    if (typeof valor === 'number' || typeof valor === 'boolean') {
      limpio[clave] = valor;
      continue;
    }
    const texto = (Array.isArray(valor) ? valor.join(',') : String(valor)).replace(/\s+/g, ' ').trim();
    if (PARECE_CORREO.test(texto) || PARECE_TELEFONO.test(texto)) continue;
    limpio[clave] = texto.slice(0, 100);
  }
  return limpio;
}

/** Evento de analítica en el dataLayer. Sin GTM cargado, no sale del navegador. */
export function registrarEvento(evento: Evento, parametros: Record<string, unknown> = {}): void {
  if (typeof window === 'undefined') return;
  window.dataLayer = window.dataLayer ?? [];
  window.dataLayer.push({ event: EVENTOS[evento], ...limpiarParametros(parametros) });
}

/** Sección de la landing donde ocurrió algo: el id de la sección o de su título («titulo-hero» → «hero»). */
export function ubicacionDe(el: Element | null): string {
  const seccion = el?.closest('section, header, footer, [role="dialog"]');
  if (!seccion) return 'pagina';
  if (seccion.getAttribute('role') === 'dialog') return 'ventana';
  if (seccion.tagName === 'HEADER') return 'cabecera';
  if (seccion.tagName === 'FOOTER') return 'pie';
  const id = seccion.id || seccion.getAttribute('aria-labelledby') || '';
  return id.replace(/^titulo-/, '') || 'seccion';
}

/** Canal de un enlace de contacto, o null si no lo es. */
export function canalContacto(href: string): 'whatsapp' | 'telefono' | 'correo' | null {
  if (/^https?:\/\/(wa\.me|api\.whatsapp\.com|web\.whatsapp\.com)\//i.test(href)) return 'whatsapp';
  if (/^tel:/i.test(href)) return 'telefono';
  if (/^mailto:/i.test(href)) return 'correo';
  return null;
}

export const UMBRALES_SCROLL = [25, 50, 75, 90] as const;

/** Umbrales de scroll alcanzados que aún no se registraron. */
export function umbralesNuevos(porcentaje: number, registrados: ReadonlySet<number>): number[] {
  return UMBRALES_SCROLL.filter((u) => porcentaje >= u && !registrados.has(u));
}
