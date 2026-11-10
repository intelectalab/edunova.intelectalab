'use server';

import { cookies } from 'next/headers';
import { COOKIE_CMS, llamarApi } from '@/lib/sesionCms';

// EDUN-233 · Acciones de servidor del editor del CMS. El token va en la cookie httpOnly.

export type EstadoPublicacion = {
  ultima_publicacion: { en: string; por: string | null } | null;
  pendientes: string[];
};

export type Resultado<T> = { ok: true; datos: T } | { ok: false; mensaje: string; errores?: Record<string, string[]> };

async function token() {
  return (await cookies()).get(COOKIE_CMS)?.value;
}

async function responder<T>(r: Response): Promise<Resultado<T>> {
  const cuerpo = await r.json().catch(() => ({}));
  if (r.ok) return { ok: true, datos: cuerpo as T };
  return { ok: false, mensaje: cuerpo?.message ?? 'No se pudo completar la acción.', errores: cuerpo?.errors };
}

export async function guardarBorrador(clave: string, contenido: Record<string, unknown>): Promise<Resultado<{ data: unknown; message: string }>> {
  try {
    const r = await llamarApi(`/cms/secciones/${encodeURIComponent(clave)}`, { metodo: 'PUT', cuerpo: { contenido }, token: await token() });
    return responder<{ data: unknown; message: string }>(r);
  } catch {
    return { ok: false, mensaje: 'No pudimos conectar con Edunova.' };
  }
}

export async function publicarSecciones(claves: string[]): Promise<Resultado<EstadoPublicacion & { publicadas: string[]; message: string }>> {
  try {
    const r = await llamarApi('/cms/publicar', { metodo: 'POST', cuerpo: { claves }, token: await token() });
    return responder<EstadoPublicacion & { publicadas: string[]; message: string }>(r);
  } catch {
    return { ok: false, mensaje: 'No pudimos conectar con Edunova.' };
  }
}

export async function leerEstadoPublicacion(): Promise<EstadoPublicacion | null> {
  try {
    const r = await llamarApi('/cms/estado', { token: await token() });
    return r.ok ? ((await r.json()) as EstadoPublicacion) : null;
  } catch {
    return null;
  }
}

/** Sprint 9 · Sección para una pantalla de edición: lo publicado, el borrador y sus medios. */
export type DatosSeccion<T> = { publicado: T; borrador: T | null; medios: Record<string, { id: number; tipo: string; nombre: string; url: string; alt: string | null }> };

export async function leerSeccion<T>(clave: string): Promise<DatosSeccion<T> | null> {
  try {
    const r = await llamarApi(`/cms/secciones/${encodeURIComponent(clave)}`, { token: await token() });
    if (!r.ok) return null;
    const d = (await r.json()).data;
    return { publicado: d.publicado as T, borrador: (d.borrador ?? null) as T | null, medios: Array.isArray(d.medios) ? {} : (d.medios ?? {}) };
  } catch {
    return null;
  }
}
