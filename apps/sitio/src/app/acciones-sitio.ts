'use server';

import { headers } from 'next/headers';
import { demo } from '@/contenido/provisional';
import { API_URL } from '@/lib/sesionCms';

/*
 * Sprint 6 · Formularios del sitio público. El navegador no habla con la API (la CSP solo
 * permite el propio origen): el servidor reenvía el formulario con la IP y el navegador del
 * visitante, firmados con SITIO_PROXY_SECRETO, para los límites de uso y la prueba del
 * consentimiento (EDUN-291).
 */

async function cabecerasVisitante(): Promise<Record<string, string>> {
  const h = await headers();
  const ip = (h.get('x-forwarded-for') ?? '').split(',')[0].trim() || h.get('x-real-ip') || '';
  const secreto = process.env.SITIO_PROXY_SECRETO ?? '';
  return {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    ...(secreto ? { 'X-Sitio-Secreto': secreto, 'X-Visitante-Ip': ip, 'X-Visitante-Agente': (h.get('user-agent') ?? '').slice(0, 500) } : {}),
  };
}

async function enviar(ruta: string, cuerpo: unknown): Promise<Response> {
  return fetch(`${API_URL}${ruta}`, { method: 'POST', headers: await cabecerasVisitante(), body: JSON.stringify(cuerpo), cache: 'no-store', signal: AbortSignal.timeout(10_000) });
}

export type SolicitudDemo = {
  nombre: string;
  cargo: string;
  telefono: string;
  email: string;
  colegio: string;
  alumnos: number;
  plan_interes: string | null;
  demo_fecha: string;
  demo_hora: string;
  consentimiento: boolean;
  origen: string | null;
  page_url: string | null;
  sitio_web: string;
  utm: Record<string, string>;
};

export type ResultadoDemo = { ok: true; email: string } | { ok: false; error: string; campos: string[] };

const UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

/** EDUN-267 · Envía la demo a POST /api/v1/sitio/leads. Sin consentimiento, la API responde 422. */
export async function solicitarDemo(s: SolicitudDemo): Promise<ResultadoDemo> {
  const utm = Object.fromEntries(UTM.map((k) => [k, typeof s.utm?.[k] === 'string' ? s.utm[k].slice(0, 100) : null]));
  let r: Response;
  try {
    r = await enviar('/sitio/leads', {
      source: 'demo',
      nombre: s.nombre,
      cargo: s.cargo,
      telefono: s.telefono,
      email: s.email,
      colegio: s.colegio,
      alumnos: s.alumnos,
      plan_interes: s.plan_interes || null,
      demo_fecha: s.demo_fecha,
      demo_hora: s.demo_hora,
      consentimiento: s.consentimiento === true,
      origen: s.origen,
      page_url: s.page_url?.slice(0, 500) ?? null,
      ...(s.sitio_web ? { sitio_web: s.sitio_web } : {}),
      ...utm,
    });
  } catch {
    return { ok: false, error: demo.errorConexion, campos: [] };
  }

  const cuerpo = await r.json().catch(() => ({}));
  if (r.status === 201) return { ok: true, email: String(cuerpo.email ?? s.email) };
  if (r.status === 422 && cuerpo?.errors) {
    const campos = Object.keys(cuerpo.errors);
    return { ok: false, error: (Object.values(cuerpo.errors)[0] as string[])[0] ?? demo.errorIncompleto, campos };
  }
  return { ok: false, error: r.status === 429 ? String(cuerpo?.message ?? demo.errorConexion) : demo.errorConexion, campos: [] };
}

/** EDUN-306 · Copia de la elección del banner de cookies (auditoría). Si falla, no molesta al visitante. */
export async function registrarCookies(categorias: string[]): Promise<boolean> {
  try {
    const r = await enviar('/sitio/consentimientos/cookies', { categorias: categorias.filter((c) => typeof c === 'string').slice(0, 4) });
    return r.status === 201;
  } catch {
    return false;
  }
}
