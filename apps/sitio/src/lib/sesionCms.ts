import 'server-only';

/*
 * EDUN-226 · Sesión del Panel CMS en el servidor de Next.js.
 * El token Sanctum vive en una cookie httpOnly: el navegador no lo ve y el servidor lo usa
 * para hablar con la API. Solo el superadmin del SaaS tiene acceso (lo decide la API).
 */

export const COOKIE_CMS = 'edunova_cms';

export const API_URL = process.env.EDUNOVA_API_URL ?? 'http://localhost:8100/api/v1';

export type UsuarioCms = { id: number; nombre: string; rol: string };

export type ResultadoSesion =
  | { estado: 'ok'; usuario: UsuarioCms }
  | { estado: 'sin-sesion' }
  | { estado: 'sin-acceso' }
  | { estado: 'error' };

export async function leerSesionCms(token: string | undefined): Promise<ResultadoSesion> {
  if (!token) return { estado: 'sin-sesion' };
  try {
    const r = await fetch(`${API_URL}/cms/sesion`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      cache: 'no-store',
    });
    if (r.status === 401) return { estado: 'sin-sesion' };
    if (r.status === 403) return { estado: 'sin-acceso' };
    if (!r.ok) return { estado: 'error' };
    const datos = (await r.json()) as { usuario: UsuarioCms };
    return { estado: 'ok', usuario: datos.usuario };
  } catch {
    return { estado: 'error' };
  }
}

export async function llamarApi(ruta: string, opciones: { metodo?: string; cuerpo?: unknown; token?: string } = {}) {
  return fetch(`${API_URL}${ruta}`, {
    method: opciones.metodo ?? 'GET',
    headers: {
      Accept: 'application/json',
      ...(opciones.cuerpo !== undefined ? { 'Content-Type': 'application/json' } : {}),
      ...(opciones.token ? { Authorization: `Bearer ${opciones.token}` } : {}),
    },
    body: opciones.cuerpo !== undefined ? JSON.stringify(opciones.cuerpo) : undefined,
    cache: 'no-store',
  });
}
