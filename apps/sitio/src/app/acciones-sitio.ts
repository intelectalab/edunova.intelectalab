'use server';

import { headers } from 'next/headers';
import { chatbot, demo, popupSalida, reclamos, seguimiento } from '@/contenido/provisional';
import { API_URL } from '@/lib/sesionCms';

/*
 * Sprint 6 · Formularios del sitio público. El navegador no habla con la API (la CSP solo
 * permite el propio origen): el servidor reenvía el formulario con la IP y el navegador del
 * visitante, firmados con SITIO_PROXY_SECRETO, para los límites de uso y la prueba del
 * consentimiento (EDUN-291).
 */

async function cabecerasVisitante(json = true): Promise<Record<string, string>> {
  const h = await headers();
  const ip = (h.get('x-forwarded-for') ?? '').split(',')[0].trim() || h.get('x-real-ip') || '';
  const secreto = process.env.SITIO_PROXY_SECRETO ?? '';
  return {
    Accept: 'application/json',
    ...(json ? { 'Content-Type': 'application/json' } : {}),
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
  ciudad: string | null;
  alumnos: number;
  plan_interes: string | null;
  /** EDUN-359 · Solo en el formulario largo /demo. */
  sistema_actual?: string | null;
  urgencia?: string | null;
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
      ciudad: s.ciudad,
      alumnos: s.alumnos,
      plan_interes: s.plan_interes || null,
      ...(s.sistema_actual ? { sistema_actual: s.sistema_actual.slice(0, 80) } : {}),
      ...(s.urgencia ? { urgencia: s.urgencia } : {}),
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

export type LeadPopup = { email: string; telefono: string; consentimiento: boolean; page_url: string | null; sitio_web: string; utm: Record<string, string> };

/** EDUN-271 · Correo o WhatsApp del popup de salida (POST /api/v1/sitio/leads/popup). */
export async function registrarLeadPopup(s: LeadPopup): Promise<{ ok: true } | { ok: false; error: string; campos: string[] }> {
  const utm = Object.fromEntries(UTM.map((k) => [k, typeof s.utm?.[k] === 'string' ? s.utm[k].slice(0, 100) : null]));
  let r: Response;
  try {
    r = await enviar('/sitio/leads/popup', {
      email: s.email?.trim() || null,
      telefono: s.telefono?.trim() || null,
      consentimiento: s.consentimiento === true,
      page_url: s.page_url?.slice(0, 500) ?? null,
      ...(s.sitio_web ? { sitio_web: s.sitio_web } : {}),
      ...utm,
    });
  } catch {
    return { ok: false, error: popupSalida.errorConexion, campos: [] };
  }
  if (r.status === 201) return { ok: true };
  const cuerpo = await r.json().catch(() => ({}));
  if (r.status === 422 && cuerpo?.errors) {
    return { ok: false, error: (Object.values(cuerpo.errors)[0] as string[])[0] ?? popupSalida.errorIncompleto, campos: Object.keys(cuerpo.errors) };
  }
  return { ok: false, error: r.status === 429 ? String(cuerpo?.message ?? popupSalida.errorConexion) : popupSalida.errorConexion, campos: [] };
}

/** EDUN-375 · Contacto dejado en el chatbot (POST /api/v1/sitio/chatbot/lead). */
export async function registrarLeadChatbot(d: { nombre: string; email: string; telefono: string; consentimiento: boolean; recorrido: string[]; page_url: string | null; sitio_web: string }): Promise<{ ok: true } | { ok: false; error: string; campos: string[] }> {
  let r: Response;
  try {
    r = await enviar('/sitio/chatbot/lead', {
      nombre: d.nombre?.trim() || null,
      email: d.email?.trim() || null,
      telefono: d.telefono?.trim() || null,
      consentimiento: d.consentimiento === true,
      recorrido: (d.recorrido ?? []).slice(0, 40).map((x) => String(x).slice(0, 300)),
      page_url: d.page_url?.slice(0, 500) ?? null,
      ...(d.sitio_web ? { sitio_web: d.sitio_web } : {}),
    });
  } catch {
    return { ok: false, error: chatbot.error, campos: [] };
  }
  if (r.status === 201) return { ok: true };
  const cuerpo = await r.json().catch(() => ({}));
  if (r.status === 422 && cuerpo?.errors) return { ok: false, error: (Object.values(cuerpo.errors)[0] as string[])[0] ?? chatbot.faltan, campos: Object.keys(cuerpo.errors) };
  return { ok: false, error: chatbot.error, campos: [] };
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

// --- EDUN-294 · Libro de Reclamaciones ------------------------------------------------------

export type ResultadoReclamo =
  | { ok: true; expediente: string; vence_en: string; email: string }
  | { ok: false; error: string; errores: Record<string, string> };

const CAMPOS_RECLAMO = ['tipo', 'nombre', 'tipo_doc', 'numero_doc', 'email', 'telefono', 'direccion', 'menor_edad', 'apoderado_nombre', 'apoderado_doc', 'producto', 'monto', 'descripcion', 'pedido', 'declaracion', 'consentimiento', 'recaptcha', 'sitio_web'];
const MAX_ADJUNTO = 5 * 1024 * 1024;

/** Reenvía la hoja de reclamación (con sus adjuntos) a POST /api/v1/sitio/reclamaciones. */
export async function registrarReclamo(datos: FormData): Promise<ResultadoReclamo> {
  const envio = new FormData();
  for (const campo of CAMPOS_RECLAMO) {
    const v = datos.get(campo);
    if (typeof v === 'string' && v !== '') envio.set(campo, v.slice(0, 4000));
  }
  const archivos = datos.getAll('adjuntos').filter((a): a is File => a instanceof File && a.size > 0);
  if (archivos.length > 2 || archivos.some((a) => a.size > MAX_ADJUNTO)) {
    return { ok: false, error: reclamos.errorAdjuntos, errores: { adjuntos: reclamos.errorAdjuntos } };
  }
  archivos.forEach((a) => envio.append('adjuntos[]', a, a.name));

  let r: Response;
  try {
    r = await fetch(`${API_URL}/sitio/reclamaciones`, { method: 'POST', headers: await cabecerasVisitante(false), body: envio, cache: 'no-store', signal: AbortSignal.timeout(30_000) });
  } catch {
    return { ok: false, error: reclamos.errorConexion, errores: {} };
  }
  const cuerpo = await r.json().catch(() => ({}));
  if (r.status === 201) return { ok: true, expediente: cuerpo.expediente, vence_en: cuerpo.vence_en, email: cuerpo.email };
  if (r.status === 422 && cuerpo?.errors) {
    // «adjuntos.0» → «adjuntos»: el formulario tiene un solo campo de archivos.
    const errores = Object.fromEntries(Object.entries(cuerpo.errors as Record<string, string[]>).map(([k, v]) => [k.split('.')[0], v[0]]));
    return { ok: false, error: reclamos.errorIncompleto, errores };
  }
  return { ok: false, error: r.status === 429 ? String(cuerpo?.message ?? reclamos.errorConexion) : reclamos.errorConexion, errores: {} };
}

// --- EDUN-298 · Seguimiento del reclamo -----------------------------------------------------

export type ResultadoSeguimiento =
  | { ok: true; expediente: string; tipo: string; estado: string; actualizado_en: string; vence_en: string }
  | { ok: false; error: string };

export async function consultarReclamo(expediente: string, documento: string): Promise<ResultadoSeguimiento> {
  const e = String(expediente ?? '').trim().slice(0, 20);
  const d = String(documento ?? '').trim().slice(0, 15);
  if (!e || !d) return { ok: false, error: seguimiento.faltan };

  try {
    const r = await fetch(`${API_URL}/sitio/reclamaciones/seguimiento?${new URLSearchParams({ expediente: e, documento: d })}`, {
      headers: await cabecerasVisitante(false),
      cache: 'no-store',
      signal: AbortSignal.timeout(10_000),
    });
    const cuerpo = await r.json().catch(() => ({}));
    if (r.ok) return { ok: true, ...cuerpo };
    if (r.status === 404 || r.status === 429) return { ok: false, error: String(cuerpo?.message ?? seguimiento.errorConexion) };
    return { ok: false, error: seguimiento.errorConexion };
  } catch {
    return { ok: false, error: seguimiento.errorConexion };
  }
}
