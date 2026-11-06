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

export type ItemMenu = { label: string; url: string; target: string; columna: string | null };

export type Landing = {
  secciones: { barra_superior?: { aviso: string }; hero?: Hero } & Record<string, unknown>;
  config: Record<string, string | null>;
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
