import 'server-only';
import type { Tracking } from './consentimiento';
import type { ConfigPopup } from './popupSalida';
import { API_URL } from './sesionCms';

// Contenido publicado de la landing (GET /api/v1/sitio/landing). ISR de 5 minutos y etiqueta
// «landing», que la API revalida al publicar desde el CMS (EDUN-233).

export type Hero = {
  insignia: string;
  titulo: string;
  subtitulo: string;
  cta_principal: string;
  cta_secundario: string | null;
  sellos: string | null;
};

export type ModuloWeb = {
  clave: string;
  nombre: string;
  icono: string;
  descripcion: string;
  etiqueta: string | null;
  en_grid: boolean;
  imagen: { url: string; alt: string | null; ancho: number | null; alto: number | null } | null;
  /** EDUN-347 · Detalle en /funcionalidades. */
  descripcion_larga?: string | null;
  beneficios?: string[];
};

export type SeccionModulos = { insignia: string; titulo: string; descripcion: string | null; boton_ver: string; boton_ocultar: string };

export type Plan = {
  clave: string;
  nombre: string;
  rango_texto: string;
  precio_mensual: string | null;
  descripcion: string | null;
  caracteristicas: { texto: string; incluida: boolean }[];
  recomendado: boolean;
  cta_texto: string;
};
export type Complemento = { clave: string; etiqueta: string; precio: string | null; nota: string | null };
export type Oferta = { titulo: string | null; texto: string; cta: string | null };
export type Contador = { label: string; valor: number; prefijo: string | null; sufijo: string | null };
export type Video = { id: number; titulo: string; descripcion: string | null; proveedor: string; embed_url: string; duracion_seg: number | null; miniatura: string | null; miniatura_alt: string | null };
export type Testimonio = { id: number; nombre: string | null; cargo: string; institucion: string; texto: string; calificacion: number; es_ejemplo: boolean; avatar: string | null };

export type SeccionPlanes = { insignia: string; titulo: string; descripcion: string | null; mensual: string; anual: string; por_mes: string; personalizado: string; personalizado_nota: string };
export type SeccionRetos = { insignia: string; titulo: string; descripcion: string | null; etiqueta_dolor: string; etiqueta_solucion: string; tarjetas: { icono: string; titulo: string; dolor: string; solucion: string; visible: boolean }[] };
export type SeccionPasos = { insignia: string; titulo: string; descripcion: string | null; pasos: { titulo: string; descripcion: string; nota: string | null; visible: boolean }[] };
export type SeccionCasos = { insignia: string; titulo: string; descripcion: string | null; tarjetas: { icono: string; rango: string; titulo: string; descripcion: string; beneficios: string[]; modulos: string | null; nota: string | null; visible: boolean }[] };
export type SeccionTestimonios = { insignia: string; titulo: string; leyenda_ejemplo: string };
export type SeccionVideos = { insignia: string; titulo: string; descripcion: string | null; boton_demo: string };
export type SeccionCtaFinal = { insignia: string | null; titulo: string; descripcion: string | null; boton: string; beneficios: string[] };
/** EDUN-338 · «Confían en Edunova»: título, segundos por vuelta del carrusel y logos visibles a la vez. */
export type SeccionLogos = { titulo: string; velocidad: number; columnas: number };
export type LogoCliente = { nombre: string; sitio_web: string | null; logo: { url: string; ancho: number | null; alto: number | null } };
/** EDUN-399 · Modo mantenimiento (cms.config.mantenimiento). */
export type Mantenimiento = { activo: boolean; mensaje: string | null; vuelta: string | null };

export type SeccionFooter = { descripcion: string; derechos: string; moneda: string };

export type Seo = {
  titulo: string;
  descripcion: string;
  canonical: string | null;
  robots: string;
  keywords: string[];
  og_titulo: string | null;
  og_descripcion: string | null;
  og_imagen: { url: string; alt: string | null; ancho: number | null; alto: number | null } | null;
};

export type ItemMenu = { label: string; url: string; target: string; columna: string | null };

export type Landing = {
  secciones: {
    barra_superior?: { aviso: string };
    hero?: Hero;
    modulos?: SeccionModulos;
    planes?: SeccionPlanes;
    retos?: SeccionRetos;
    pasos?: SeccionPasos;
    casos_uso?: SeccionCasos;
    testimonios?: SeccionTestimonios;
    videos?: SeccionVideos;
    cta_final?: SeccionCtaFinal;
    footer?: SeccionFooter;
    logos?: SeccionLogos;
  };
  modulos: ModuloWeb[];
  planes: Plan[];
  complementos: Complemento[];
  oferta: Oferta | null;
  contadores: Contador[];
  videos: Video[];
  testimonios: Testimonio[];
  seo: Seo | null;
  faq: { pregunta: string; respuesta: string; categoria?: string | null }[];
  config: Record<string, string | number | boolean | null>;
  menu: { principal: ItemMenu[]; footer: ItemMenu[] };
  /** EDUN-306 · Contenedor de GTM; solo se carga con consentimiento de analítica o marketing. */
  tracking?: Tracking;
  /** EDUN-271 · Popup de salida (null si está apagado en el CMS). */
  popup?: ConfigPopup | null;
  /** EDUN-338 · Logos autorizados y visibles. */
  logos?: LogoCliente[];
  /** EDUN-347 · Videos por módulo (/funcionalidades). */
  videos_modulos?: (Video & { modulo_clave: string })[];
  /** EDUN-375 · Chatbot (null si está apagado). */
  chatbot?: Chatbot | null;
};

/** EDUN-375 · Configuración pública del chatbot y su flujo (destinos = posiciones en «nodos»). */
export type NodoChatbot = { tipo: 'mensaje' | 'opciones' | 'captura' | 'url' | 'whatsapp' | 'fin'; texto: string; url: string | null; siguiente: number | null; opciones: { texto: string; destino: number }[] };
export type Chatbot = {
  activo: boolean;
  modo: 'flujo' | 'whatsapp';
  titulo: string;
  saludo: string;
  fuera_horario: string;
  whatsapp_mensaje: string | null;
  horario: { dias: number[]; desde: string; hasta: string };
  nodos: NodoChatbot[];
  whatsapp: string | null;
};

/** EDUN-399 · El sitio está en mantenimiento si el CMS lo activó. */
export function mantenimiento(datos: Landing | null): Mantenimiento | null {
  const m = datos?.config.mantenimiento as unknown as Mantenimiento | null | undefined;
  return m && m.activo ? m : null;
}

export const REVALIDAR_SEGUNDOS = 300;

export async function obtenerLanding(): Promise<Landing | null> {
  try {
    const r = await fetch(`${API_URL}/sitio/landing`, {
      headers: { Accept: 'application/json' },
      next: { revalidate: REVALIDAR_SEGUNDOS, tags: ['landing'] },
    });
    return r.ok ? ((await r.json()) as Landing) : null;
  } catch {
    return null;
  }
}
