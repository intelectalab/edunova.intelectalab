import 'server-only';
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
export type SeccionFooter = { descripcion: string; derechos: string; moneda: string };

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
  };
  modulos: ModuloWeb[];
  planes: Plan[];
  complementos: Complemento[];
  oferta: Oferta | null;
  contadores: Contador[];
  videos: Video[];
  testimonios: Testimonio[];
  config: Record<string, string | number | boolean | null>;
  menu: { principal: ItemMenu[]; footer: ItemMenu[] };
};

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
