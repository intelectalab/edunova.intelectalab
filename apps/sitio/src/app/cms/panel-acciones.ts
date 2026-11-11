'use server';

import { cookies } from 'next/headers';
import { COOKIE_CMS, llamarApi } from '@/lib/sesionCms';

// Sprint 10 · Lecturas y acciones del Panel CMS: dashboard, auditoría, usuarios, SEO y políticas.

async function token() {
  return (await cookies()).get(COOKIE_CMS)?.value;
}

async function leer<T>(ruta: string): Promise<T | null> {
  try {
    const r = await llamarApi(ruta, { token: await token() });
    return r.ok ? ((await r.json()) as T) : null;
  } catch {
    return null;
  }
}

export type ResultadoPanel<T = unknown> = { ok: true; datos: T; mensaje: string } | { ok: false; mensaje: string; errores: Record<string, string> };

async function escribir<T>(ruta: string, metodo: string, cuerpo: unknown): Promise<ResultadoPanel<T>> {
  try {
    const r = await llamarApi(ruta, { metodo, cuerpo, token: await token() });
    const c = await r.json().catch(() => ({}));
    if (r.ok) return { ok: true, datos: (c?.data ?? c) as T, mensaje: String(c?.message ?? '') };
    const errores = Object.fromEntries(Object.entries((c?.errors ?? {}) as Record<string, string[]>).map(([k, v]) => [k, v[0]]));
    return { ok: false, mensaje: String(c?.message ?? 'No se pudo completar la acción.'), errores };
  } catch {
    return { ok: false, mensaje: 'No pudimos conectar con Edunova.', errores: {} };
  }
}

const query = (f: Record<string, string | undefined>) =>
  new URLSearchParams(Object.entries(f).filter((e): e is [string, string] => typeof e[1] === 'string' && e[1] !== '')).toString();

// --- Dashboard (EDUN-383) -------------------------------------------------------------------

export type Dashboard = {
  leads_hoy: number;
  leads_7d: number;
  leads_7d_previos: number;
  leads_sin_contactar: number;
  demos_hoy: number;
  por_origen: Record<string, number>;
  tendencia: { dia: string; leads: number }[];
  reclamos: { abiertos: number; vencidos: number; por_vencer: number };
  borradores: string[];
  crm_pendientes: number;
  ultimos_leads: { id: number; colegio: string | null; nombre: string | null; email: string | null; telefono: string | null; source: string; estado: string; created_at: string }[];
};

export async function leerDashboard() {
  return leer<Dashboard>('/cms/dashboard');
}

// --- Auditoría (EDUN-342) -------------------------------------------------------------------

export type FiltrosAuditoria = { q?: string; usuario?: string; seccion?: string; accion?: string; pagina?: string };

export type RegistroAuditoria = {
  id: number;
  created_at: string;
  usuario: string | null;
  usuario_rol: string;
  seccion: string;
  accion: string;
  entidad: string;
  entidad_id: string;
  campo: string | null;
  detalle: string;
  valor_antes: unknown;
  valor_despues: unknown;
  restaurable: boolean;
};

export type Auditoria = {
  data: RegistroAuditoria[];
  pagina: number;
  por_pagina: number;
  total: number;
  paginas: number;
  indicadores: { cambios_hoy: number; publicaciones_7d: number; usuarios_con_acceso: number; versiones: number };
  secciones: Record<string, string>;
  usuarios: { id: number; nombre: string | null }[];
  acciones: Record<string, string>;
};

export async function leerAuditoria(f: FiltrosAuditoria) {
  return leer<Auditoria>(`/cms/auditoria?${query(f)}`);
}

export async function restaurarCambio(id: number) {
  return escribir(`/cms/auditoria/${id}/restaurar`, 'POST', {});
}

// --- Usuarios (EDUN-407) --------------------------------------------------------------------

export type UsuarioCmsLista = { id: number; nombre: string; email: string; ultimo_acceso_at: string | null; desde: string; dos_pasos: boolean; cambios_30d: number };

export async function leerUsuarios() {
  return (await leer<{ data: UsuarioCmsLista[] }>('/cms/usuarios'))?.data ?? null;
}

// --- SEO (EDUN-314) -------------------------------------------------------------------------

export type PaginaSeo = {
  slug: string;
  meta_titulo: string;
  meta_descripcion: string;
  canonical_url: string | null;
  robots: string;
  keywords: string[];
  og_titulo: string | null;
  og_descripcion: string | null;
  og_imagen_id: number | null;
  og_imagen_url: string | null;
  og_imagen_alt: string | null;
  og_imagen_ancho: number | null;
  og_imagen_alto: number | null;
  updated_at: string;
};

export async function leerPaginasSeo() {
  return (await leer<{ data: { slug: string; meta_titulo: string; robots: string; updated_at: string }[] }>('/cms/seo'))?.data ?? null;
}

export async function leerSeo(slug: string) {
  return (await leer<{ data: PaginaSeo }>(`/cms/seo/${encodeURIComponent(slug)}`))?.data ?? null;
}

export async function guardarSeo(slug: string, d: Omit<PaginaSeo, 'slug' | 'updated_at' | 'og_imagen_url' | 'og_imagen_alt' | 'og_imagen_ancho' | 'og_imagen_alto'>) {
  return escribir<PaginaSeo>(`/cms/seo/${encodeURIComponent(slug)}`, 'PUT', d);
}

// --- Políticas (EDUN-395) -------------------------------------------------------------------

export type VersionPolitica = { id: number; version: string; vigente_desde: string; borrador: boolean; created_at: string; contenido: string; estado: 'vigente' | 'programada' | 'anterior' };
export type PoliticaCms = { tipo: string; nombre: string; versiones: VersionPolitica[] };

export async function leerPoliticasCms() {
  return (await leer<{ data: PoliticaCms[] }>('/cms/politicas'))?.data ?? null;
}

export async function publicarPolitica(tipo: string, d: { version: string; vigente_desde: string; contenido: string; revision_pendiente: boolean }) {
  return escribir<PoliticaCms>(`/cms/politicas/${encodeURIComponent(tipo)}`, 'POST', d);
}

// --- Tracking e integraciones (EDUN-403) ----------------------------------------------------

type ClaveGuardada = { configurado: boolean };
export type IntegracionesCms = {
  tracking: Record<'gtm' | 'ga4' | 'meta_pixel' | 'linkedin' | 'clarity' | 'search_console', { valor: string | null; activo: boolean }>;
  crm: { url: string | null; token: ClaveGuardada };
  smtp: { host: string | null; puerto: number; usuario: string | null; cifrado: string; remitente: string | null; clave: ClaveGuardada };
  ia: { proveedor: string | null; clave: ClaveGuardada };
  wordpress: { url: string | null };
};

/** Lo que se envía: las claves van como texto nuevo o null para mantenerlas; *_borrar las elimina. */
export type CambiosIntegraciones = {
  tracking: IntegracionesCms['tracking'];
  crm: { url: string | null; token: string | null; token_borrar: boolean };
  smtp: { host: string | null; puerto: number; usuario: string | null; cifrado: string; remitente: string | null; clave: string | null; clave_borrar: boolean };
  ia: { proveedor: string | null; clave: string | null; clave_borrar: boolean };
  wordpress: { url: string | null };
};

export async function leerIntegraciones() {
  return (await leer<{ data: IntegracionesCms }>('/cms/integraciones'))?.data ?? null;
}

export async function guardarIntegraciones(d: CambiosIntegraciones) {
  return escribir<IntegracionesCms>('/cms/integraciones', 'PUT', d);
}

// --- Blog de WordPress (EDUN-367 / SIT-023) ------------------------------------------------------

export type PostCms = {
  wp_post_id: number;
  slug: string;
  titulo: string;
  resumen: string | null;
  imagen_url: string | null;
  publicado_en: string | null;
  actualizado_wp: string | null;
  autor_wp: string | null;
  oculto: boolean;
  estado: 'publicado' | 'oculto';
  revisado_at: string | null;
  wp_url: string | null;
  categoria_nombre: string | null;
  cambio_sin_revisar: boolean;
  editar_url: string | null;
};
export type BlogCms = { configurado: boolean; wp_admin: string | null; ultima_sincronia: string | null; data: PostCms[] };
export type VistaPreviaPost = Pick<PostCms, 'slug' | 'titulo' | 'resumen' | 'imagen_url' | 'publicado_en' | 'actualizado_wp' | 'autor_wp' | 'oculto' | 'categoria_nombre' | 'editar_url'> & { contenido: string | null };

export async function leerBlogCms() {
  return leer<BlogCms>('/cms/blog');
}

export async function leerVistaPreviaPost(slug: string) {
  return (await leer<{ data: VistaPreviaPost }>(`/cms/blog/${encodeURIComponent(slug)}`))?.data ?? null;
}

export async function sincronizarBlog() {
  return escribir<BlogCms>('/cms/blog/sincronizar', 'POST', {});
}

export async function cambiarVisibilidadPost(slug: string, oculto: boolean) {
  return escribir<VistaPreviaPost>(`/cms/blog/${encodeURIComponent(slug)}`, 'PATCH', { oculto });
}

// --- Asistente SEO con IA (EDUN-411 / SIT-030) -----------------------------------------------------

export type AnalisisTexto = { largo: number; ideal: [number, number]; estado: 'bien' | 'corto' | 'largo' | 'vacio'; legibilidad: number; nivel: string };
export type SugerenciasSeo = { proveedor: string; titulo: string; keywords: string[]; descripciones: (AnalisisTexto & { texto: string })[] };
export type AnalisisSeo = {
  titulo: { largo: number; ideal: [number, number]; estado: AnalisisTexto['estado']; duplicado_en: string[] };
  descripcion: AnalisisTexto & { duplicado_en: string[] };
};
export type ConfigAsistenteSeo = { proveedor: string; modelo: string; proveedores: Record<string, string>; modelos: string[]; clave_configurada: boolean };

export async function leerAsistenteSeo() {
  return (await leer<{ data: ConfigAsistenteSeo }>('/cms/seo-asistente'))?.data ?? null;
}

export async function guardarAsistenteSeo(proveedor: string, modelo: string) {
  return escribir<ConfigAsistenteSeo>('/cms/seo-asistente', 'PUT', { proveedor, modelo });
}

export async function sugerirSeo(slug: string, contexto: string) {
  return escribir<SugerenciasSeo>(`/cms/seo/${encodeURIComponent(slug)}/sugerencias`, 'POST', { contexto: contexto.trim() || null });
}

export async function analizarSeo(slug: string, metaTitulo: string, metaDescripcion: string) {
  return escribir<AnalisisSeo>(`/cms/seo/${encodeURIComponent(slug)}/analisis`, 'POST', { meta_titulo: metaTitulo, meta_descripcion: metaDescripcion });
}
