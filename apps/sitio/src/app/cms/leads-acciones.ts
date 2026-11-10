'use server';

import { cookies } from 'next/headers';
import { COOKIE_CMS, llamarApi } from '@/lib/sesionCms';

// EDUN-278 · Bandeja de solicitudes: lectura con filtros y cambio de estado (con auditoría en la API).

export type EstadoLead = 'nuevo' | 'contactado' | 'en_proceso' | 'ganado' | 'perdido';

export type Lead = {
  id: number;
  nombre: string | null;
  cargo: string | null;
  email: string | null;
  telefono: string | null;
  colegio: string | null;
  ciudad: string | null;
  alumnos: number | null;
  plan_interes: string | null;
  plan_nombre: string | null;
  precio_mensual: string | null;
  estado: EstadoLead;
  source: string;
  demo_fecha: string | null;
  demo_hora: string | null;
  mensaje: string | null;
  utm_source: string | null;
  utm_medium: string | null;
  utm_campaign: string | null;
  page_url: string | null;
  created_at: string;
  primer_contacto_en: string | null;
  /** EDUN-275 · Envío al CRM. */
  crm: 'enviado' | 'pendiente' | 'error' | null;
  crm_enviado_en: string | null;
  crm_intentos: number | null;
  crm_error: string | null;
};

export type Bandeja = {
  data: Lead[];
  pagina: number;
  por_pagina: number;
  total: number;
  paginas: number;
  contadores: Record<'todos' | EstadoLead, number>;
  indicadores: {
    total_mes: number;
    sin_contactar: number;
    respuesta_horas: number | null;
    demos_agendadas: number;
    demos_hoy: number;
    pipeline_mensual: string;
    pipeline_leads: number;
  };
  ciudades: string[];
  planes: { clave: string; nombre: string }[];
  crm: { configurado: boolean; pendientes: number; con_error: number };
};

export type FiltrosBandeja = { estado?: string; plan?: string; ciudad?: string; q?: string; pagina?: string };

async function token() {
  return (await cookies()).get(COOKIE_CMS)?.value;
}

export async function leerBandeja(f: FiltrosBandeja): Promise<Bandeja | null> {
  const p = new URLSearchParams(Object.entries(f).filter((e): e is [string, string] => typeof e[1] === 'string' && e[1] !== ''));
  try {
    const r = await llamarApi(`/cms/leads?${p}`, { token: await token() });
    return r.ok ? ((await r.json()) as Bandeja) : null;
  } catch {
    return null;
  }
}

export async function cambiarEstadoLead(id: number, estado: EstadoLead): Promise<{ ok: boolean; mensaje?: string }> {
  try {
    const r = await llamarApi(`/cms/leads/${id}`, { metodo: 'PATCH', cuerpo: { estado }, token: await token() });
    if (r.ok) return { ok: true };
    const cuerpo = await r.json().catch(() => ({}));
    return { ok: false, mensaje: cuerpo?.message };
  } catch {
    return { ok: false };
  }
}

/** EDUN-282 · Reenvía al CRM las solicitudes que no llegaron (todas o las indicadas). */
export async function sincronizarCrm(ids?: number[]): Promise<{ ok: boolean; mensaje: string }> {
  try {
    const r = await llamarApi('/cms/leads/sincronizar', { metodo: 'POST', cuerpo: ids ? { ids } : {}, token: await token() });
    const cuerpo = await r.json().catch(() => ({}));
    return { ok: r.ok, mensaje: String(cuerpo?.message ?? '') };
  } catch {
    return { ok: false, mensaje: '' };
  }
}
