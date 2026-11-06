'use server';

import { cookies } from 'next/headers';
import { API_URL, COOKIE_CMS, llamarApi } from '@/lib/sesionCms';

// EDUN-237 · Acciones de servidor de la Biblioteca de medios.

export type Medio = {
  id: number;
  tipo: 'imagen' | 'svg' | 'pdf' | 'video';
  nombre: string;
  url: string;
  mime: string;
  bytes: number;
  ancho: number | null;
  alto: number | null;
  alt: string | null;
};

export type ResultadoSubida = { ok: true; medio: Medio } | { ok: false; mensaje: string };

async function token() {
  return (await cookies()).get(COOKIE_CMS)?.value;
}

export async function listarMedios(tipo?: 'imagenes' | 'pdf'): Promise<Medio[]> {
  try {
    const r = await llamarApi(`/cms/medios${tipo ? `?tipo=${tipo}` : ''}`, { token: await token() });
    return r.ok ? ((await r.json()).data as Medio[]) : [];
  } catch {
    return [];
  }
}

export async function subirMedio(datos: FormData): Promise<ResultadoSubida> {
  const archivo = datos.get('archivo');
  if (!(archivo instanceof File) || archivo.size === 0) return { ok: false, mensaje: 'Elige un archivo.' };

  const envio = new FormData();
  envio.set('archivo', archivo, archivo.name);
  const alt = String(datos.get('alt') ?? '').trim();
  if (alt) envio.set('alt', alt);

  try {
    const r = await fetch(`${API_URL}/cms/medios`, {
      method: 'POST',
      headers: { Accept: 'application/json', Authorization: `Bearer ${await token()}` },
      body: envio,
      cache: 'no-store',
    });
    const cuerpo = await r.json().catch(() => ({}));
    if (r.ok) return { ok: true, medio: cuerpo.data as Medio };
    const primero = cuerpo?.errors ? (Object.values(cuerpo.errors)[0] as string[] | undefined)?.[0] : undefined;
    return { ok: false, mensaje: primero ?? cuerpo?.message ?? 'No se pudo subir el archivo.' };
  } catch {
    return { ok: false, mensaje: 'No pudimos conectar con Edunova.' };
  }
}
