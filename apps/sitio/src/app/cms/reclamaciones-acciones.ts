'use server';

import { cookies } from 'next/headers';
import { COOKIE_CMS, llamarApi } from '@/lib/sesionCms';

// EDUN-302 · Gestión de reclamaciones: lista, ficha y respuesta (con auditoría en la API).

export type EstadoReclamo = 'recibido' | 'en_revision' | 'respondido' | 'cerrado';
export type Plazo = 'vencido' | 'por_vencer' | 'en_plazo' | 'a_tiempo' | 'fuera_de_plazo';

export type FilaReclamo = {
  id: number;
  numero_expediente: string;
  tipo: 'queja' | 'reclamo';
  nombre_reclamante: string;
  email: string;
  producto_descripcion: string;
  estado: EstadoReclamo;
  vence_en: string;
  created_at: string;
  respondido_en: string | null;
  plazo: Plazo;
};

export type ListaReclamos = {
  data: FilaReclamo[];
  pagina: number;
  por_pagina: number;
  total: number;
  paginas: number;
  contadores: Record<'todos' | EstadoReclamo, number>;
  indicadores: { abiertos: number; vencidos: number; por_vencer: number; a_tiempo_pct: number | null; respondidos_90: number };
};

export type Reclamo = FilaReclamo & {
  tipo_doc: string;
  numero_doc: string;
  telefono: string | null;
  direccion: string | null;
  menor_edad: boolean;
  apoderado_nombre: string | null;
  apoderado_doc: string | null;
  monto_reclamado: string | null;
  descripcion: string;
  pedido: string;
  notas_internas: string | null;
  respuesta_cliente: string | null;
  updated_at: string;
  siguientes: EstadoReclamo[];
  adjuntos: { indice: number; nombre: string; mime: string | null; bytes: number }[];
  historial: { campo: string | null; detalle: string; created_at: string; usuario: string | null }[];
};

export type FiltrosReclamos = { estado?: string; tipo?: string; plazo?: string; q?: string; pagina?: string };

async function token() {
  return (await cookies()).get(COOKIE_CMS)?.value;
}

export async function leerReclamaciones(f: FiltrosReclamos): Promise<ListaReclamos | null> {
  const p = new URLSearchParams(Object.entries(f).filter((e): e is [string, string] => typeof e[1] === 'string' && e[1] !== ''));
  try {
    const r = await llamarApi(`/cms/reclamaciones?${p}`, { token: await token() });
    return r.ok ? ((await r.json()) as ListaReclamos) : null;
  } catch {
    return null;
  }
}

export async function leerReclamo(id: number): Promise<Reclamo | null> {
  try {
    const r = await llamarApi(`/cms/reclamaciones/${id}`, { token: await token() });
    return r.ok ? (((await r.json()) as { data: Reclamo }).data ?? null) : null;
  } catch {
    return null;
  }
}

export type CambiosReclamo = { estado?: EstadoReclamo; respuesta_cliente?: string; notas_internas?: string };

export type ResultadoReclamo = { ok: true; mensaje: string } | { ok: false; mensaje?: string; errores: Record<string, string> };

export async function actualizarReclamo(id: number, cambios: CambiosReclamo): Promise<ResultadoReclamo> {
  try {
    const r = await llamarApi(`/cms/reclamaciones/${id}`, { metodo: 'PATCH', cuerpo: cambios, token: await token() });
    const cuerpo = await r.json().catch(() => ({}));
    if (r.ok) return { ok: true, mensaje: String(cuerpo?.message ?? '') };
    const errores = Object.fromEntries(Object.entries((cuerpo?.errors ?? {}) as Record<string, string[]>).map(([k, v]) => [k, v[0]]));
    return { ok: false, mensaje: cuerpo?.message, errores };
  } catch {
    return { ok: false, errores: {} };
  }
}
