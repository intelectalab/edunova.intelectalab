import 'server-only';
import { REVALIDAR_SEGUNDOS } from './landing';
import { API_URL } from './sesionCms';

// EDUN-287 / SIT-053 · Versión vigente de una política (GET /api/v1/sitio/politicas/{tipo}).
// ISR de 5 minutos con la etiqueta «politicas», que la API puede revalidar al publicar.

export const TIPOS_POLITICA = ['privacidad', 'terminos', 'seguridad', 'cookies'] as const;
export type TipoPolitica = (typeof TIPOS_POLITICA)[number];

export type Politica = {
  tipo: TipoPolitica;
  titulo: string;
  version: string;
  vigente_desde: string;
  borrador: boolean;
  /** HTML ya limpio por la API (solo etiquetas de texto y enlaces seguros). */
  contenido: string;
};

export function esTipoPolitica(tipo: string): tipo is TipoPolitica {
  return (TIPOS_POLITICA as readonly string[]).includes(tipo);
}

export async function obtenerPolitica(tipo: TipoPolitica): Promise<Politica | null> {
  try {
    const r = await fetch(`${API_URL}/sitio/politicas/${tipo}`, {
      headers: { Accept: 'application/json' },
      next: { revalidate: REVALIDAR_SEGUNDOS, tags: ['politicas'] },
    });
    return r.ok ? ((await r.json()) as Politica) : null;
  } catch {
    return null;
  }
}
